<?php

declare(strict_types=1);

namespace Plathix\Modules\Settings;

use Plathix\Infrastructure\Logger;
use Plathix\Infrastructure\TempDirectory;
use Plathix\PublicApi\ImportExportApi;
use Plathix\PublicApi\PresetsApi;

final class SettingsSaveHandler
{
	/** @var callable(): bool */
	private $can_manage;

	/** @var callable(): string */
	private $settingsUrl;

	private array $save_callbacks = [];

	private array $tab_options = [];

	private array $option_owner = [];

	/**
	 * @param callable(): bool   $can_manage
	 * @param callable(): string $settingsUrl
	 */

	public function __construct(callable $can_manage, callable $settingsUrl) {
		$this->can_manage   = $can_manage;
		$this->settingsUrl = $settingsUrl;
	}

	/**
	 * @param string          $option_name
	 * @param callable(mixed=): bool $save_callback
	 */

	public function registerSaveHandler(string $option_name, callable $save_callback): void {
		$this->save_callbacks[ $option_name ] = $save_callback;
	}

	/**
	 * @param string             $tab_slug
	 * @param array<int, string> $option_names
	 */

	public function registerTabHandler(string $tab_slug, array $option_names): void {
		$owned_option_names = [];
		foreach ( $option_names as $option_name ) {
			$existing_tab = $this->option_owner[ $option_name ] ?? null;
			if ( $existing_tab !== null && $existing_tab !== $tab_slug ) {

				do_action( 'plathix/settings/tab_option_conflict', $option_name, $existing_tab, $tab_slug );

				Logger::error( __METHOD__ . ': option already owned by another tab.', [
					'option_name'  => $option_name,
					'existing_tab' => $existing_tab,
					'tab_slug'     => $tab_slug,
				] );
				continue;
			}

			$this->option_owner[ $option_name ] = $tab_slug;
			$owned_option_names[]               = $option_name;
		}

		$this->tab_options[ $tab_slug ] = $owned_option_names;

		add_action( 'admin_post_plathix_save_' . $tab_slug, [ $this, 'handleTabSave' ] );
	}

	public function handleTabSave(): void {
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

				//

				// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- nonce and capability are verified above at the single entry point of this handler; the value is deliberately handed over raw because each option owns its sanitizer (sanitizePolicy(), sanitizeDays(), sanitizeBool(), absint()) and applies wp_unslash() itself — unslashing here would double-process values the callbacks already handle
				$raw = $_POST[ $option_name ] ?? null;

				$succeeded = (bool) $callback( $raw );
			} catch ( \Throwable $e ) {
				$succeeded = false;
				$reason    = $e->getMessage();
			}

			if ( ! $succeeded ) {
				$all_succeeded = false;
				do_action( 'plathix/settings/save_failed', $option_name, $reason );

				Logger::error( __METHOD__ . ': failed to save option.', [
					'option_name' => $option_name,
					'reason'      => $reason ?? 'no reason provided',
				] );
			}
		}

		$redirect = add_query_arg( '_plathix_redirect_tab', $tab_slug, ( $this->settingsUrl )() );
		$redirect = $all_succeeded
			? add_query_arg( 'settings-updated', 'true', $redirect )
			: add_query_arg( 'plathix_settings_partial_fail', '1', $redirect );

		wp_safe_redirect( $redirect );
		exit;
	}

	public function handleExport(): void {
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

		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Disposition: attachment; filename="plathix-export-' . time() . '.json"' );
		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON file download (Content-Type: application/json, Content-Disposition: attachment); HTML escaping would corrupt the exported file
		exit;
	}

	public function handleExportPreset(): void {
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
			$this->removeDir( $temp_dir );
		}

		exit;
	}

	public function handleImportJson(): void {
		if ( ! ( $this->can_manage )() ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'plathix' ) );
		}

		check_admin_referer( 'plathix_import_json', 'plathix_import_json_nonce' );

		$file = $_FILES['plathix_import_file'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce+cap verified above; $_FILES array validated via is_uploaded_file() (no text sanitization applicable)
		if ( ! is_array( $file ) || ! isset( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			wp_safe_redirect( add_query_arg( 'plathix_import', 'no_file', ( $this->settingsUrl )() ) );
			exit;
		}

		$raw = file_get_contents( $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads the uploaded file from PHP's own tmp_name after is_uploaded_file(); WP_Filesystem does not apply to the upload staging path
		if ( ! is_string( $raw ) ) {
			wp_safe_redirect( add_query_arg( 'plathix_import', 'read_error', ( $this->settingsUrl )() ) );
			exit;
		}

		$payload = json_decode( $raw, true );
		if ( ! is_array( $payload ) || ( $payload['plugin'] ?? '' ) !== 'plathix' ) {
			wp_safe_redirect( add_query_arg( 'plathix_import', 'invalid_file', ( $this->settingsUrl )() ) );
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
				( $this->settingsUrl )()
			)
		);
		exit;
	}

	private function removeDir(string $dir): void {
		TempDirectory::removeTree( $dir );
	}
}
