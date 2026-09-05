<?php

declare(strict_types=1);

namespace Plathix\Modules\Replace;

use Plathix\Http\RestController;
use Plathix\Infrastructure\MediaModalEnqueue;
use Plathix\PublicApi\AttachmentMetaApi;

final class AttachmentReplaceUi
{
	public function register(): void {
		add_filter( 'attachment_fields_to_edit', [ $this, 'add_replace_field' ], 10, 2 );
		add_filter( 'media_row_actions', [ $this, 'add_replace_row_action' ], 10, 2 );
		// [internal]: единая точка регистрации — Plathix\Infrastructure\MediaModalEnqueue.
		MediaModalEnqueue::register( [ $this, 'enqueue_replace_script' ] );
	}

	public function enqueue_replace_script(): void {
		if ( wp_script_is( 'plathix-replace-media', 'enqueued' ) ) {
			return;
		}

		$asset_file = PLATHIX_ASSETS_PATH . 'js/replace-media.asset.php';
		$asset      = file_exists( $asset_file ) ? (array) require $asset_file : [];
		$deps       = (array) ( $asset['dependencies'] ?? [] );
		// Cache-bust по хешу содержимого ассета ([internal]): голый PLATHIX_VERSION
		// = дата сборки плагина, не менялся при правке одного ассета → браузеры не подхватывали
		// изменение. Хеш из .asset.php меняется при пересборке. Паттерн — как builder.css/admin-ui.
		$version    = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? (string) time() : (string) ( $asset['version'] ?? PLATHIX_VERSION );

		wp_enqueue_script( 'plathix-replace-media', PLATHIX_ASSETS_URL . 'js/replace-media.js', $deps, $version, true );
		wp_localize_script( 'plathix-replace-media', 'PlathixReplace', [
			'restUrl'   => rest_url( 'plathix/v1/' ),
			// [internal]: запасной base для серверов, режущих POST к /wp-json/, единый
			// источник — RestController::rest_route_fallback_base().
			'restUrlFallback' => RestController::rest_route_fallback_base(),
			'restNonce' => wp_create_nonce( 'wp_rest' ),
		] );

		// Стили compat-полей «Папка»/«Заменить файл». Грузятся там же, где скрипт
		// (модалка + страница attachment), т.к. именно там рендерятся поля.
		if ( file_exists( PLATHIX_ASSETS_PATH . 'css/attachment-fields.css' ) ) {
			wp_enqueue_style( 'plathix-attachment-fields', PLATHIX_ASSETS_URL . 'css/attachment-fields.css', [], $version );
		}
	}

	/**
	 * @param array<string,mixed> $form_fields
	 * @return array<string,mixed>
	 */
	public function add_replace_field(array $form_fields, \WP_Post $post): array {
		// На странице файла блок идёт из мета-бокса справа — не дублируем compat-полем.
		if ( ( new AttachmentMetaApi() )->isAttachmentEditPage() ) {
			return $form_fields;
		}

		/** @var \WP_Post&object{ID:int} $post -- phpstan-wordpress stub omits declared WP_Post properties */
		$form_fields['plathix_replace_file'] = [
			'label' => __( 'Replace file', 'plathix' ),
			'input' => 'html',
			'html'  => $this->replace_trigger_markup( $post->ID, true ),
			'helps' => esc_html__( 'Keeps the same attachment ID and WordPress relationships. Direct URL references may require manual review if filename or file type changes.', 'plathix' ),
		];

		return $form_fields;
	}

	/**
	 * @param array<string,string> $actions
	 * @return array<string,string>
	 */
	public function add_replace_row_action(array $actions, \WP_Post $post): array {
		/** @var \WP_Post&object{ID:int} $post -- phpstan-wordpress stub omits declared WP_Post properties */
		if ( $post->post_type !== 'attachment' ) {
			return $actions;
		}

		$actions['plathix_replace_file'] = $this->replace_trigger_markup( $post->ID, false );

		return $actions;
	}

	/**
	 * Публичная разметка триггера замены для переиспользования вне compat-полей
	 * (например, в мета-боксе на странице файла). Возвращает ту же структуру
	 * классов `plathix-replace-file-*`, что ловит существующий JS.
	 */
	public function render_replace_trigger(int $attachment_id): string {
		return $this->replace_trigger_markup( $attachment_id, true );
	}

	private function replace_trigger_markup(int $attachment_id, bool $details_view): string {
		// В details-view (модалка/страница файла) кнопка стилизуется во всю ширину
		// через plathix-replace__file-button; в списке (row action) остаётся ссылкой.
		$button_class = $details_view ? 'button plathix-replace__file-button' : 'plathix-replace-file-link';
		$note         = $details_view
			? ''
			: '<span class="screen-reader-text">' . esc_html__( 'Replace file', 'plathix' ) . '</span>';

		// Иконка замены (inline SVG) показывается только в details-view рядом с текстом.
		$icon = $details_view
			? '<svg class="plathix-replace__file-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="17 1 21 5 17 9"></polyline><path d="M3 11V9a4 4 0 0 1 4-4h14"></path><polyline points="7 23 3 19 7 15"></polyline><path d="M21 13v2a4 4 0 0 1-4 4H3"></path></svg>'
			: '';

		return sprintf(
			'<span class="plathix-replace__file-wrap" data-attachment-id="%1$d">' .
			'<button type="button" class="%2$s plathix-replace__file-trigger" data-attachment-id="%1$d">%3$s%4$s</button>' .
			'<input type="file" class="plathix-replace__file-input" data-attachment-id="%1$d" hidden>' .
			'%5$s' .
			'</span>',
			$attachment_id,
			esc_attr( $button_class ),
			$icon,
			esc_html__( 'Replace file', 'plathix' ),
			$note
		);
	}
}
