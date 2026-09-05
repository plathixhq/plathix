<?php

declare(strict_types=1);

namespace Plathix\Modules\Import;

use Plathix\Http\Nonce;

/**
 * Enqueue-сервис модуля Import.
 *
 * Грузит import.js ТОЛЬКО на странице Tools (slug `plathix-tools`, is_ui_page=true).
 * До FA-102 import-JS был частью settings.js и грузился только на Settings — кнопки на Tools
 * не работали (баг). После FA-102 владение перешло модулю, страница соответствует кнопкам.
 */
final class ImportEnqueueService
{
	private const SCRIPT_HANDLE = 'plathix-import';
	private const TOOLS_SLUG    = 'plathix-tools';

	/** Навешивает enqueue на admin_enqueue_scripts. */
	public function register(): void
	{
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	/** Грузит import.js только на странице Tools. */
	public function enqueue(): void
	{
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing check
		if ( sanitize_key( (string) ( $_GET['page'] ?? '' ) ) !== self::TOOLS_SLUG ) {
			return;
		}

		$path = PLATHIX_ASSETS_PATH . 'js/import.js';
		if ( ! file_exists( $path ) ) {
			return;
		}

		$asset   = $this->get_asset();
		$version = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? (string) time() : (string) ( $asset['version'] ?? PLATHIX_VERSION );

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			PLATHIX_ASSETS_URL . 'js/import.js',
			(array) ( $asset['dependencies'] ?? [] ),
			$version,
			true
		);

		// CSS сетки импорта co-located ([internal], #113): вынесен из общего
		// admin-ui.css, грузится только здесь (Tools). Несамодостаточен (@container опирается на
		// .plathix-main из платформы) — dep plathix-admin-ui, который грузится на Tools (is_ui_page).
		wp_enqueue_style(
			self::SCRIPT_HANDLE,
			PLATHIX_ASSETS_URL . 'css/import.css',
			[ 'plathix-admin-ui' ],
			$version
		);

		wp_localize_script( self::SCRIPT_HANDLE, 'PlathixSettings', $this->build_data() );
	}

	/** @return array<string, mixed> */
	private function build_data(): array
	{
		return [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => Nonce::create(),
			'i18n'    => $this->build_i18n(),
		];
	}

	/** @return array<string, string> */
	private function build_i18n(): array
	{
		return [
			'import_queued'    => __( 'Import queued. Waiting for Action Scheduler...', 'plathix' ),
			'import_running'   => __( 'Import is running...', 'plathix' ),
			/* translators: %d: number of moved items. */
			'import_completed' => __( 'Import completed. Moved items: %d', 'plathix' ),
			'import_failed'    => __( 'Import failed.', 'plathix' ),
			'import_timeout'   => __( 'Import is still pending. Action Scheduler runner may be unavailable.', 'plathix' ),
			// [internal]: единичный/несколько подряд транспортных сбоев самого опроса статуса
			// (сеть, 429/503 от хостинга) — не провал импорта, отдельное сообщение от import_failed.
			'import_status_unstable' => __( 'Could not check import status — connection is unstable. Please wait or try again later.', 'plathix' ),
			'request_failed'   => __( 'Request failed.', 'plathix' ),
		];
	}

	/** @return array<string, mixed> */
	private function get_asset(): array
	{
		$file = PLATHIX_ASSETS_PATH . 'js/import.asset.php';
		if ( file_exists( $file ) ) {
			$asset = require $file;
			if ( is_array( $asset ) ) {
				return $asset;
			}
		}

		return [
			'version'      => PLATHIX_VERSION,
			'dependencies' => [],
		];
	}
}
