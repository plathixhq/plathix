<?php

declare(strict_types=1);

namespace Plathix\Modules\Import;

use Plathix\Http\Nonce;
use Plathix\PublicApi\ToolsApi;

final class ImportEnqueueService
{
	private const SCRIPT_HANDLE = 'plathix-import';

	public function register(): void
	{
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	public function enqueue(): void
	{
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( sanitize_key( (string) ( $_GET['page'] ?? '' ) ) !== ( new ToolsApi() )->pageSlug() ) {
			return;
		}

		$path = PLATHIX_ASSETS_PATH . 'js/import.js';
		if ( ! file_exists( $path ) ) {
			return;
		}

		$asset   = $this->getAsset();
		$version = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? (string) time() : (string) ( $asset['version'] ?? PLATHIX_VERSION );

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			PLATHIX_ASSETS_URL . 'js/import.js',
			(array) ( $asset['dependencies'] ?? [] ),
			$version,
			true
		);

		wp_enqueue_style(
			self::SCRIPT_HANDLE,
			PLATHIX_ASSETS_URL . 'css/import.css',
			[ 'plathix-admin-ui' ],
			$version
		);

		wp_localize_script( self::SCRIPT_HANDLE, 'PlathixSettings', $this->buildData() );
	}

	/** @return array<string, mixed> */
	private function buildData(): array
	{
		return [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => Nonce::create(),
			'i18n'    => $this->buildI18n(),
		];
	}

	/** @return array<string, string> */
	private function buildI18n(): array
	{
		return [
			'import_queued'    => __( 'Import queued. Waiting for Action Scheduler...', 'plathix' ),
			'import_running'   => __( 'Import is running...', 'plathix' ),
			/* translators: %d: number of moved items. */
			'import_completed' => __( 'Import completed. Moved items: %d', 'plathix' ),
			'import_failed'    => __( 'Import failed.', 'plathix' ),
			'import_timeout'   => __( 'Import is still pending. Action Scheduler runner may be unavailable.', 'plathix' ),

			'import_status_unstable' => __( 'Could not check import status — connection is unstable. Please wait or try again later.', 'plathix' ),
			'request_failed'   => __( 'Request failed.', 'plathix' ),
		];
	}

	/** @return array<string, mixed> */
	private function getAsset(): array
	{
		return \Plathix\Infrastructure\AssetManifest::read( 'js/import.asset.php' );
	}
}
