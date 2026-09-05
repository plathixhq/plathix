<?php

declare(strict_types=1);

namespace Plathix\Modules\Preset;

use Plathix\Http\AjaxGuard;
use Plathix\Infrastructure\Keys;
use Plathix\PublicApi\PlathixAPI;
use Plathix\User\AccessLevel;
use Plathix\User\AccessResolver;

/**
 * Admin-post обработчики операций над пресетами ([internal] / [internal]).
 *
 * Вынесено из {@see PresetsPage} (namespace Admin): обработка admin-post-действий над пресетами
 * (apply/delete/upload/scratch), редиректы и notice-транзиенты — это ответственность фичи
 * пресетов, а НЕ admin-слоя. Все бизнес-делегаты этих обработчиков живут здесь, в
 * `Modules\Preset` ({@see PlathixAPI::presets()}, {@see PresetUploadPipeline},
 * {@see PresetRepository}, {@see FolderResetService}) — вынос СНИМАЕТ прежнюю кросс-модульную
 * связь Admin→Preset-делегаты, а не создаёт её.
 *
 * Структурный образец — {@see \Plathix\Modules\FreeFirstRun\Module} (page-render + post-actions
 * раздельно в одном модуле): {@see PresetsPage} остаётся page-callback в Admin, а обработка POST —
 * здесь; оба регистрируются из {@see Module::boot()}.
 *
 * Action-строки — единый источник на фасаде {@see PresetsPage} (константы APPLY/DELETE/UPLOAD/
 * SCRATCH_ACTION): их читает и генератор nonce ({@see PresetsPage::render_card} и др.), и
 * {@see \Plathix\Modules\FreeFirstRun\FreeWizard} (cross-module URL кнопок). Этот класс их только
 * ПОТРЕБЛЯЕТ через префикс `PresetsPage::`, а не определяет свои копии — иначе рассинхрон nonce.
 */
final class PresetPostActions
{
	public function __construct(
		private readonly PresetRepository $repository = new PresetRepository(),
		private readonly PresetUploadPipeline $upload_pipeline = new PresetUploadPipeline(),
	) {
	}

	public function register(): void {
		add_action( 'admin_post_' . PresetsPage::APPLY_ACTION, [ $this, 'handle_apply' ] );
		add_action( 'admin_post_' . PresetsPage::DELETE_ACTION, [ $this, 'handle_delete' ] );
		add_action( 'admin_post_' . PresetsPage::UPLOAD_ACTION, [ $this, 'handle_upload' ] );
		add_action( 'admin_post_' . PresetsPage::SCRATCH_ACTION, [ $this, 'handle_scratch' ] );
		// [internal]: dry-run проверка ZIP через AJAX до показа success-уведомления в модалке.
		add_action( 'wp_ajax_' . PresetsPage::VALIDATE_ACTION, [ $this, 'handle_validate' ] );
		add_action( 'admin_notices', [ $this, 'maybe_show_scratch_notice' ] );
	}

	public function handle_apply(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'plathix' ), 403 );
		}

		$preset_id = (int) ( $_REQUEST['preset_id'] ?? 0 ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cap verified above; (int) cast; nonce checked against this value on next line
		check_admin_referer( PresetsPage::APPLY_ACTION . '_' . $preset_id );

		// title пресета нужен для success-notice; форма apply() его не несёт — берём find().
		$preset = $this->repository->find( $preset_id );

		if ( $preset === null ) {
			$this->redirect_with_notice( 'error', __( 'Preset not found.', 'plathix' ) );
			return;
		}

		// Применение — через стабильную границу Free ([internal]), единый владелец apply-логики.
		$result = PlathixAPI::presets()->apply( $preset_id );

		if ( ! $result['success'] ) {
			$msg = (string) ( $result['error']['message'] ?? __( 'Apply failed.', 'plathix' ) );
			$this->redirect_with_notice( 'error', $msg );
			return;
		}

		$created = (int) ( $result['created'] ?? 0 );
		$this->redirect_with_notice(
			'success',
			sprintf(
				/* translators: 1: preset title, 2: folder count */
				__( 'Preset "%1$s" applied. %2$d folders created.', 'plathix' ),
				(string) ( $preset['title'] ?? '' ),
				$created
			)
		);
	}

	public function handle_delete(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'plathix' ), 403 );
		}

		$preset_id = (int) ( $_REQUEST['preset_id'] ?? 0 ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cap verified above; (int) cast; nonce checked against this value on next line
		check_admin_referer( PresetsPage::DELETE_ACTION . '_' . $preset_id );

		$preset = $this->repository->find( $preset_id );

		if ( $preset === null ) {
			$this->redirect_with_notice( 'error', __( 'Preset not found.', 'plathix' ) );
			return;
		}

		// Built-in presets cannot be deleted via UI (spec §26.4)
		if ( (string) ( $preset['source_type'] ?? '' ) === PresetSourceType::BUILTIN ) {
			$this->redirect_with_notice( 'error', __( 'Built-in presets cannot be deleted.', 'plathix' ) );
			return;
		}

		$this->repository->delete( $preset_id );

		do_action( 'plathix/audit/record', 'preset_deleted', [
			'presetId'   => $preset_id,
			'slug'       => (string) ( $preset['slug'] ?? '' ),
			'title'      => (string) ( $preset['title'] ?? '' ),
			'sourceType' => (string) ( $preset['source_type'] ?? '' ),
		]);

		$this->redirect_with_notice( 'success', __( 'Preset deleted.', 'plathix' ) );
	}

	public function handle_upload(): void {
		// [internal]: AccessResolver-aware гейт — паритет с handle_validate() (dry-run
		// того же пути, AjaxGuard::require_cap()). Голый current_user_can('manage_options')
		// не видит PRO RolePolicy access-level override (plathix/user/access_level):
		// понижение AccessLevel ниже Full для юзера с формальным manage_options ловилось
		// на dry-run, но не на реальной записи. currentUserIsFullAdmin() — единый источник
		// правды для этой пары проверок ([internal]/#535), не ручное дублирование.
		if ( ! AccessResolver::currentUserIsFullAdmin() ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'plathix' ), 403 );
		}

		check_admin_referer( PresetsPage::UPLOAD_ACTION );

		$file = $_FILES['plathix_preset_zip'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce+cap verified above; $_FILES array handled by upload pipeline (no text sanitization applicable)
		if ( ! is_array( $file ) || empty( $file['tmp_name'] ) ) {
			$this->redirect_with_notice( 'error', __( 'No file uploaded.', 'plathix' ) );
			return;
		}

		$result = $this->upload_pipeline->run( $file );

		if ( ! $result['success'] ) {
			$msg = (string) ( $result['error']['message'] ?? __( 'Upload failed.', 'plathix' ) );
			$this->redirect_with_notice( 'error', $msg );
			return;
		}

		$new_preset_id = (int) ( $result['preset']['id'] ?? 0 );

		$this->redirect_with_notice(
			'success',
			sprintf(
				/* translators: %s: preset title */
				__( 'Preset "%s" uploaded successfully.', 'plathix' ),
				(string) ( $result['preset']['title'] ?? $result['title'] ?? '' )
			),
			$new_preset_id > 0 ? $new_preset_id : null
		);
	}

	/**
	 * [internal]: AJAX dry-run проверка ZIP-структуры, вызывается сразу при выборе файла в
	 * модалке (preset.js), до клика «Upload». Возвращает тот же формат {success, preset|error},
	 * что и upload_pipeline->run() — фронтенду не нужен отдельный парсер ответа. Не создаёт
	 * запись в каталоге и не пишет preview-файл (dry_run: true в pipeline).
	 */
	public function handle_validate(): void {
		// [internal] (follow-up находка): голый current_user_can() не видит per-role/per-user
		// access-level override (plathix/user/access_level, PRO RolePolicy). Порядок этой
		// проверки относительно nonce-проверки ниже — предсуществующий (не anti-оракул канон),
		// не меняется этим фиксом, вне scope [internal].
		AjaxGuard::require_cap( AccessLevel::Full, 'manage_options' );

		check_ajax_referer( PresetsPage::UPLOAD_ACTION );

		$file = $_FILES['plathix_preset_zip'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce+cap verified above; $_FILES array handled by upload pipeline (no text sanitization applicable)
		if ( ! is_array( $file ) || empty( $file['tmp_name'] ) ) {
			wp_send_json_error( [ 'message' => __( 'No file uploaded.', 'plathix' ) ] );
		}

		$result = $this->upload_pipeline->run( $file, PresetSourceType::CUSTOM, true );

		if ( ! $result['success'] ) {
			wp_send_json_error( $result['error'] );
		}

		wp_send_json_success( $result['preset'] );
	}

	public function handle_scratch(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'plathix' ), 403 );
		}

		check_admin_referer( PresetsPage::SCRATCH_ACTION );

		$result = ( new FolderResetService() )->run();

		if ( ! $result['success'] ) {
			$errors = (int) ( $result['errors'] ?? 0 );
			$this->set_scratch_notice(
				'error',
				sprintf(
					/* translators: %d: error count */
					__( 'Reset completed with %d errors.', 'plathix' ),
					$errors
				)
			);
			wp_safe_redirect( admin_url( 'upload.php' ) );
			exit;
		}

		$this->set_scratch_notice( 'success', __( 'All folders have been reset. You can now start from scratch.', 'plathix' ) );
		wp_safe_redirect( admin_url( 'upload.php' ) );
		exit;
	}

	/** Сохраняет one-shot admin notice в transient для показа на следующей странице (upload.php). */
	private function set_scratch_notice(string $type, string $message): void {
		set_transient( Keys::transient( 'scratch_notice_' . get_current_user_id() ), [ 'type' => $type, 'message' => $message ], 60 );
	}

	/** Показывает и удаляет one-shot notice после handle_scratch() редиректа. */
	public function maybe_show_scratch_notice(): void {
		$key  = Keys::transient( 'scratch_notice_' . get_current_user_id() );
		$data = get_transient( $key );
		if ( ! is_array( $data ) ) {
			return;
		}
		delete_transient( $key );
		$type    = in_array( $data['type'] ?? '', [ 'success', 'error' ], true ) ? $data['type'] : 'info';
		$message = (string) ( $data['message'] ?? '' );
		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $type ),
			esc_html( $message )
		);
	}

	/**
	 * @param int|null $new_preset_id ID только что загруженного пресета. Если передан,
	 *   добавляется в redirect-URL как plathix_new_preset — JS на странице скроллит к
	 *   карточке этого пресета и подсвечивает её (см. admin-ui.js). Используется
	 *   только upload-success; остальные вызовы передают null и параметр не добавляют.
	 */
	private function redirect_with_notice(string $type, string $message, ?int $new_preset_id = null): void {
		$args = [
			'page'            => PresetsPage::PAGE_SLUG,
			'plathix_notice'  => rawurlencode( $message ),
			'plathix_ntype'   => $type,
		];

		if ( $new_preset_id !== null && $new_preset_id > 0 ) {
			$args['plathix_new_preset'] = $new_preset_id;
		}

		$url = add_query_arg( $args, admin_url( 'admin.php' ) );
		wp_safe_redirect( $url );
		exit;
	}
}
