<?php

declare(strict_types=1);

namespace Plathix\Modules\Settings;

use Plathix\Infrastructure\TempDirectory;
use Plathix\PublicApi\ImportExportApi;
use Plathix\PublicApi\PresetsApi;

/**
 * Обрабатывает admin-post экшены страницы настроек: export, export preset, import JSON,
 * и ([internal]) per-таб сохранение опций Settings-страницы — изолированная замена
 * общей options.php/OPTION_GROUP whitelist-группы ([internal]/#518).
 *
 * Регистраторы опций (Free: SettingsPage/SvgSettings/TrashSettings; PRO: AccessSettings/
 * ContentTypes\Module/AttachmentMeta\Module) вызывают register_save_handler() по одной
 * опции через do_action('plathix/settings/save', ...) и register_tab_handler() по табу
 * (единожды на таб, список опций знает либо сам регистратор — single-owner таб, либо
 * собран через фильтр plathix/settings/option_tab_map — multi-owner таб 'general').
 * Этот класс не хранит имён PRO-опций нигде — только то, что ему передали регистраторы
 * runtime-вызовами (project_module_autonomy_invariant).
 */
final class SettingsSaveHandler
{
	/** @var callable(): bool */
	private $can_manage;

	/** @var callable(): string */
	private $settings_url;

	/** @var array<string, callable(mixed=): bool> option_name => save callback ([internal]; значение передаётся аргументом — [internal]) */
	private array $save_callbacks = [];

	/** @var array<string, array<int, string>> tab_slug => option names ([internal]) */
	private array $tab_options = [];

	/**
	 * @var array<string, string> option_name => tab_slug — обратный индекс владения опцией
	 *   табом ([internal]/[internal]). Первый таб, зарегистрировавший опцию, становится её
	 *   владельцем; попытка другого таба заявить ту же опцию отклоняется в
	 *   register_tab_handler().
	 */
	private array $option_owner = [];

	/**
	 * @param callable(): bool   $can_manage   Проверка прав текущего пользователя.
	 * @param callable(): string $settings_url URL страницы настроек для редиректов.
	 */
	public function __construct(callable $can_manage, callable $settings_url) {
		$this->can_manage   = $can_manage;
		$this->settings_url = $settings_url;
	}

	/**
	 * Регистрирует save-callback для одной опции. Вызывается на
	 * do_action('plathix/settings/save', $option_name, $save_callback).
	 *
	 * @param string          $option_name   Имя опции (совпадает с именем, регистрируемым модулем).
	 * @param callable(mixed=): bool $save_callback Выполняет sanitize+update_option, возвращает
	 *   true при успешном сохранении. НЕ вызывает current_user_can()/check_admin_referer() сам —
	 *   auth делает централизованно admin_post_plathix_save_{$tab_slug} handler.
	 *   [internal]: получает сырое значение из $_POST аргументом (диспетчер читает его в
	 *   одном месте после проверки nonce). Параметр необязателен — замыкания, объявленные до
	 *   смены контракта, продолжают работать без изменений.
	 */
	public function register_save_handler(string $option_name, callable $save_callback): void {
		$this->save_callbacks[ $option_name ] = $save_callback;
	}

	/**
	 * Регистрирует один admin_post_plathix_save_{$tab_slug} handler на весь таб —
	 * вызывается один раз на таб (не на опцию), $option_names — полный список опций
	 * этого таба, известный вызывающей стороне (single-owner таб: регистратор знает
	 * свои опции напрямую; multi-owner таб 'general': список собран хостом через
	 * apply_filters('plathix/settings/option_tab_map', ...) до вызова этого метода).
	 *
	 * [internal]: опция, уже заявленная другим табом (первый регистратор побеждает),
	 * исключается из $option_names этого таба — хук tab_option_conflict сигнализирует
	 * о конфликте (имя хука полностью в do_action() ниже, вызов внутри тела метода).
	 * Защищает от того, что модуль, дописавший чужую опцию в option_tab_map, мог бы
	 * заставить сабмит своего таба выполнить save-callback опции, которой владеет
	 * другой таб.
	 *
	 * @param string             $tab_slug     Слаг таба (general/svg/trash/access).
	 * @param array<int, string> $option_names Опции этого таба — все обязаны быть уже
	 *   зарегистрированы через register_save_handler() к моменту admin_init.
	 * @param \Plathix\Loader|null $loader Loader для admin_post-wiring, если вызывающая
	 *   сторона его использует (SettingsPage); null — прямой add_action (тот же паттерн,
	 *   что уже используют SvgSettings/TrashSettings для plathix/settings/register).
	 */
	public function register_tab_handler(string $tab_slug, array $option_names, ?\Plathix\Loader $loader = null): void {
		$owned_option_names = [];
		foreach ( $option_names as $option_name ) {
			$existing_tab = $this->option_owner[ $option_name ] ?? null;
			if ( $existing_tab !== null && $existing_tab !== $tab_slug ) {
				// [internal]: опция уже заявлена другим табом — исключаем её из этого таба
				// вместо того, чтобы позволить сабмиту чужой формы выполнить её save-callback.
				do_action( 'plathix/settings/tab_option_conflict', $option_name, $existing_tab, $tab_slug );
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					// [internal]: hook имеет 0 production-listener'ов ни в Free, ни в PRO —
					// без этой записи конфликт владения опцией не виден нигде (только WP_DEBUG,
					// не шумит в прод-лог, тот же паттерн что ZipDownload\Module::boot()).
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- debug log gated behind WP_DEBUG, not shipped debug code
					error_log( __METHOD__ . ": option '{$option_name}' already owned by tab '{$existing_tab}', rejected from tab '{$tab_slug}'." );
				}
				continue;
			}

			$this->option_owner[ $option_name ] = $tab_slug;
			$owned_option_names[]               = $option_name;
		}

		$this->tab_options[ $tab_slug ] = $owned_option_names;

		if ( $loader ) {
			$loader->add_action( 'admin_post_plathix_save_' . $tab_slug, $this, 'handle_tab_save' );
		} else {
			add_action( 'admin_post_plathix_save_' . $tab_slug, [ $this, 'handle_tab_save' ] );
		}
	}

	/**
	 * Единая точка входа для ЛЮБОГО admin_post_plathix_save_{$tab_slug} — WP не может
	 * передать $tab_slug hook'у напрямую (admin_post_{action} вызывается без аргументов),
	 * поэтому таб резолвится из текущего action-имени запроса, не из Closure-замыкания
	 * (Closure не дедуплицируется WP add_action при повторной регистрации — риск двойного
	 * вызова, senior-dev-skeptic finding). Централизованный auth-check, try/catch изоляция
	 * каждого callback'а (частичный провал не должен обрывать остальные), failure-hook на
	 * каждый провал, redirect с сохранением активного таба + settings-updated=true только
	 * при полном успехе, иначе plathix_settings_partial_fail=1.
	 */
	public function handle_tab_save(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- read-only action-name resolution, real auth check is check_admin_referer() below
		$action   = sanitize_key( (string) ( $_REQUEST['action'] ?? '' ) );
		$tab_slug = str_starts_with( $action, 'plathix_save_' ) ? substr( $action, strlen( 'plathix_save_' ) ) : '';
		$option_names = $this->tab_options[ $tab_slug ] ?? [];

		if ( ! ( $this->can_manage )() ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'plathix' ) );
		}

		check_admin_referer( 'plathix_save_' . $tab_slug );

		$all_succeeded = true;
		foreach ( $option_names as $option_name ) {
			$callback = $this->save_callbacks[ $option_name ] ?? null;
			if ( $callback === null ) {
				continue;
			}

			$reason = null;
			try {
				// [internal] ([internal]): значение читается ЗДЕСЬ — в единственном месте,
				// после can_manage() и check_admin_referer() выше. До этого каждое замыкание
				// лезло в $_POST само, и линтер видел суперглобал без видимой ему проверки
				// nonce (проверка централизована и замыканию не видна).
				//
				// Замыкания, объявленные без параметра, продолжают работать: PHP допускает
				// передачу лишнего аргумента в callable с меньшей арностью. Поэтому смена
				// контракта обратно совместима для всех 11 потребителей (8 Free + 3 PRO).
				// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- nonce and capability are verified above at the single entry point of this handler; the value is deliberately handed over raw because each option owns its sanitizer (sanitize_policy(), sanitize_days(), sanitize_bool(), absint()) and applies wp_unslash() itself — unslashing here would double-process values the callbacks already handle
				$raw = $_POST[ $option_name ] ?? null;

				$succeeded = (bool) $callback( $raw );
			} catch ( \Throwable $e ) {
				$succeeded = false;
				$reason    = $e->getMessage();
			}

			if ( ! $succeeded ) {
				$all_succeeded = false;
				do_action( 'plathix/settings/save_failed', $option_name, $reason );
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					// [internal]: hook имеет 0 production-listener'ов ни в Free, ни в PRO —
					// без этой записи причина провала сохранения опции теряется (только
					// WP_DEBUG, не шумит в прод-лог, тот же паттерн что ZipDownload\Module::boot()).
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- debug log gated behind WP_DEBUG, not shipped debug code
					error_log( __METHOD__ . ": failed to save option '{$option_name}': " . ( $reason ?? 'no reason provided' ) );
				}
			}
		}

		$redirect = add_query_arg( '_plathix_redirect_tab', $tab_slug, ( $this->settings_url )() );
		$redirect = $all_succeeded
			? add_query_arg( 'settings-updated', 'true', $redirect )
			: add_query_arg( 'plathix_settings_partial_fail', '1', $redirect );

		wp_safe_redirect( $redirect );
		exit;
	}

	public function handle_export(): void {
		if ( ! ( $this->can_manage )() ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'plathix' ) );
		}

		check_admin_referer( 'plathix_export', 'plathix_export_nonce' );

		$selected = isset( $_POST['plathix_export_taxonomies'] ) && is_array( $_POST['plathix_export_taxonomies'] )
			? array_map( 'sanitize_key', $_POST['plathix_export_taxonomies'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- capability checked at the top of this method and check_admin_referer( 'plathix_export', 'plathix_export_nonce' ) runs before this read; each element is sanitize_key()'d here
			: null;

		$json = wp_json_encode(
			( new ImportExportApi() )->exportStructureFiltered( $selected ),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);

		if ( ! is_string( $json ) ) {
			wp_die( esc_html__( 'Failed to generate export JSON.', 'plathix' ) );
		}

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="plathix-export-' . time() . '.json"' );
		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON file download (Content-Type: application/json, Content-Disposition: attachment); HTML escaping would corrupt the exported file
		exit;
	}

	public function handle_export_preset(): void {
		if ( ! ( $this->can_manage )() ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'plathix' ) );
		}

		check_admin_referer( 'plathix_export_preset', 'plathix_export_preset_nonce' );

		$result = ( new PresetsApi() )->exportCurrentSiteAsPreset();

		if ( ! $result['success'] ) {
			$msg = (string) ( $result['error']['message'] ?? __( 'Export failed.', 'plathix' ) );
			wp_die( esc_html( $msg ) );
		}

		$zip_path = (string) ( $result['zip_path'] ?? '' );
		$temp_dir = (string) ( $result['temp_dir'] ?? '' );
		$slug     = (string) ( $result['slug'] ?? 'plathix-preset' );

		if ( ! is_file( $zip_path ) ) {
			wp_die( esc_html__( 'Export archive not found.', 'plathix' ) );
		}

		$size = (int) filesize( $zip_path );

		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Length: ' . $size );
		header( 'Content-Disposition: attachment; filename="' . $slug . '.zip"' );
		header( 'X-Content-Type-Options: nosniff' );

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		readfile( $zip_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streams the export ZIP straight to the browser; WP_Filesystem has no streaming read and would load the whole archive into memory

		if ( $temp_dir !== '' && is_dir( $temp_dir ) ) {
			$this->remove_dir( $temp_dir );
		}

		exit;
	}

	public function handle_import_json(): void {
		if ( ! ( $this->can_manage )() ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'plathix' ) );
		}

		check_admin_referer( 'plathix_import_json', 'plathix_import_json_nonce' );

		$file = $_FILES['plathix_import_file'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce+cap verified above; $_FILES array validated via is_uploaded_file() (no text sanitization applicable)
		if ( ! is_array( $file ) || ! isset( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			wp_safe_redirect( add_query_arg( 'plathix_import', 'no_file', ( $this->settings_url )() ) );
			exit;
		}

		$raw = file_get_contents( $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads the uploaded file from PHP's own tmp_name after is_uploaded_file(); WP_Filesystem does not apply to the upload staging path
		if ( ! is_string( $raw ) ) {
			wp_safe_redirect( add_query_arg( 'plathix_import', 'read_error', ( $this->settings_url )() ) );
			exit;
		}

		$payload = json_decode( $raw, true );
		if ( ! is_array( $payload ) || ( $payload['plugin'] ?? '' ) !== 'plathix' ) {
			wp_safe_redirect( add_query_arg( 'plathix_import', 'invalid_file', ( $this->settings_url )() ) );
			exit;
		}

		$selected = isset( $_POST['plathix_import_taxonomies'] ) && is_array( $_POST['plathix_import_taxonomies'] )
			? array_map( 'sanitize_key', $_POST['plathix_import_taxonomies'] )
			: null;

		$stats = ( new ImportExportApi() )->importStructure( $payload, $selected );

		wp_safe_redirect(
			add_query_arg(
				[
					'plathix_import'          => 'done',
					'plathix_import_imported' => $stats['imported'],
					'plathix_import_errors'   => $stats['errors'],
				],
				( $this->settings_url )()
			)
		);
		exit;
	}

	private function remove_dir(string $dir): void {
		TempDirectory::remove_tree( $dir );
	}
}
