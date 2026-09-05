<?php

declare(strict_types=1);

namespace Plathix\Modules\AttachmentMeta;

use Plathix\Core\FolderRepository;
use Plathix\Core\TaxonomyResolver;
use Plathix\Http\RestController;
use Plathix\Infrastructure\MediaModalEnqueue;

/**
 * Энкью скрипта/стилей split-control "Папка" (popover-дерево + смена папки на месте).
 * Тот же хук-паттерн, что AttachmentReplaceUi::enqueue_replace_script — вешается на
 * admin_enqueue_scripts (страница вложения) и wp_enqueue_media (медиа-модалка).
 * [internal]: единая точка регистрации — Plathix\Infrastructure\MediaModalEnqueue.
 */
final class FolderSwitchUi
{
	public function register(): void {
		MediaModalEnqueue::register( [ $this, 'enqueue_folder_switch_script' ] );
	}

	public function enqueue_folder_switch_script(): void {
		if ( wp_script_is( 'plathix-folder-switch', 'enqueued' ) ) {
			return;
		}

		$asset_file = PLATHIX_ASSETS_PATH . 'js/folder-switch.asset.php';
		$asset      = file_exists( $asset_file ) ? (array) require $asset_file : [];
		$deps       = (array) ( $asset['dependencies'] ?? [] );
		$version    = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? (string) time() : (string) ( $asset['version'] ?? PLATHIX_VERSION );

		$taxonomy = TaxonomyResolver::fromPostType( 'attachment' );

		wp_enqueue_script( 'plathix-folder-switch', PLATHIX_ASSETS_URL . 'js/folder-switch.js', $deps, $version, true );
		wp_localize_script( 'plathix-folder-switch', 'PlathixFolderSwitch', [
			'restUrl'             => rest_url( 'plathix/v1/' ),
			// [internal]: тот же класс бага, что replace/bulk-trash; единый источник —
			// RestController::rest_route_fallback_base().
			'restUrlFallback'     => RestController::rest_route_fallback_base(),
			'restNonce'           => wp_create_nonce( 'wp_rest' ),
			'taxonomy'            => $taxonomy,
			// Реальный id термина "Несортированные" на этой инсталляции — нужен фронту,
			// чтобы после DELETE /items (перемещение "в Медиафайлы", виртуальный root с
			// id=0 в дереве) показать корректную левую зону, не гадая id по имени/позиции.
			'uncategorizedTermId' => ( new FolderRepository() )->get_uncategorized_term_id( $taxonomy ),
		] );

		// Тот же CSS-файл, что и Replace (attachment-fields.css) — WP дедуплицирует enqueue
		// по handle, повторный вызов с тем же handle/src безвреден.
		if ( file_exists( PLATHIX_ASSETS_PATH . 'css/attachment-fields.css' ) ) {
			wp_enqueue_style( 'plathix-attachment-fields', PLATHIX_ASSETS_URL . 'css/attachment-fields.css', [], $version );
		}
	}
}
