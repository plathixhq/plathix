<?php

declare(strict_types=1);

namespace Plathix\Modules\AttachmentMeta;

use Plathix\Core\FolderRepository;
use Plathix\Core\TaxonomyResolver;
use Plathix\Http\RestController;
use Plathix\Infrastructure\MediaModalEnqueue;

final class FolderSwitchUi
{
	public function register(): void {
		MediaModalEnqueue::register( [ $this, 'enqueueFolderSwitchScript' ] );
	}

	public function enqueueFolderSwitchScript(): void {
		if ( wp_script_is( 'plathix-folder-switch', 'enqueued' ) ) {
			return;
		}

		$asset   = \Plathix\Infrastructure\AssetManifest::read( 'js/folder-switch.asset.php' );
		$deps    = $asset['dependencies'] ?? [];
		$version = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? (string) time() : $asset['version'];

		$taxonomy = TaxonomyResolver::fromPostType( 'attachment' );

		wp_enqueue_script( 'plathix-folder-switch', PLATHIX_ASSETS_URL . 'js/folder-switch.js', $deps, $version, true );
		wp_localize_script( 'plathix-folder-switch', 'PlathixFolderSwitch', [
			'restUrl'             => rest_url( 'plathix/v1/' ),

			'restUrlFallback'     => RestController::restRouteFallbackBase(),
			'restNonce'           => wp_create_nonce( 'wp_rest' ),
			'taxonomy'            => $taxonomy,

			'uncategorizedTermId' => ( new FolderRepository() )->getUncategorizedTermId( $taxonomy ),
		] );

		if ( file_exists( PLATHIX_ASSETS_PATH . 'css/attachment-fields.css' ) ) {
			wp_enqueue_style( 'plathix-attachment-fields', PLATHIX_ASSETS_URL . 'css/attachment-fields.css', [], $version );
		}
	}
}
