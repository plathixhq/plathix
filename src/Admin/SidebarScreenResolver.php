<?php

declare(strict_types=1);

namespace Plathix\Admin;

use Plathix\Core\BuilderDetect;
use Plathix\Core\RequestContext;
use Plathix\Http\Authorization;

/**
 * Determines which sidebar context (if any) applies to the current admin screen.
 */
final class SidebarScreenResolver
{
	/**
	 * @return array{screen_context:string,screen_kind:string,media_mode:string,filter_strategy:string}|null
	 *   Returns null when no sidebar should be rendered on this screen.
	 */
	public function resolve(string $hook): ?array {
		$screen_context = $this->resolveSidebarContext( $hook );
		if ( null === $screen_context ) {
			return null;
		}

		$media_mode      = $this->getMediaLibraryMode();
		$screen_kind     = $this->getScreenKind( $screen_context, $hook );
		$filter_strategy = $this->getFilterStrategy( $screen_context, $media_mode, $hook );

		return compact( 'screen_context', 'screen_kind', 'media_mode', 'filter_strategy' );
	}

	/**
	 * @return array{screen_context:string,screen_kind:string,media_mode:string,filter_strategy:string}|null
	 */
	public function resolveFrontend(): ?array {
		if ( ! $this->isFrontendMediaModalRequest() ) {
			return null;
		}

		return [
			'screen_context'  => 'upload',
			'screen_kind'     => 'modal',
			'media_mode'      => 'grid',
			'filter_strategy' => 'media-frame',
		];
	}

	public function isUploadListPage(): bool {
		global $pagenow;
		return $pagenow === 'upload.php';
	}

	public function shouldRenderStaticShell(): bool {
		global $pagenow;
		$hook = is_string( $pagenow ) ? $pagenow : '';

		if ( $hook === '' ) {
			return false;
		}

		$screen_context = $this->resolveSidebarContext( $hook );
		if ( null === $screen_context ) {
			return false;
		}

		return ! ( $screen_context === 'upload' && ! $this->isUploadListPageForHook( $hook ) );
	}

	private function isUploadListPageForHook(string $hook): bool {
		return $hook === 'upload.php';
	}

	private function resolveSidebarContext(string $hook): ?string {

		if ( ! $this->hasMediaModal( $hook ) ) {
			return null;
		}

		if ( ! Authorization::capability( 'view', 'attachment' ) ) {
			return null;
		}

		return 'upload';
	}

	private function hasMediaModal(string $hook): bool {
		if ( in_array( $hook, [ 'upload.php', 'post.php', 'post-new.php', 'site-editor.php' ], true ) ) {
			return true;
		}

		return RequestContext::isPageBuilderRequest();
	}

	private function isFrontendMediaModalRequest(): bool {
		if (
			! BuilderDetect::isFrontendBuilderRequest(
			is_admin(),
			[ 'attachment' ], // CTAN-201: attachment-native
			$_GET // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only detection, values never output
			)
		) {
			return false;
		}

		return Authorization::capability( 'view', 'attachment' );
	}

	private function getMediaLibraryMode(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- read-only screen-resolution preference determining grid/list media view for enqueue decision; sanitized (sanitize_key), no form processing, no DB write
		$requested_mode = sanitize_key( (string) wp_unslash( $_GET['mode'] ?? $_POST['mode'] ?? '' ) );
		if ( in_array( $requested_mode, [ 'grid', 'list' ], true ) ) {
			return $requested_mode;
		}

		$mode = sanitize_key( (string) get_user_option( 'media_library_mode', get_current_user_id() ) );

		return in_array( $mode, [ 'grid', 'list' ], true ) ? $mode : 'grid';
	}

	private function getScreenKind(string $screen_context, string $hook): string {
		if ( $screen_context === 'upload' && ! $this->isUploadListPageForHook( $hook ) ) {
			return 'modal';
		}

		return 'static';
	}

	private function getFilterStrategy(string $screen_context, string $media_mode, string $hook): string {
		if ( $screen_context === 'upload' && ! $this->isUploadListPageForHook( $hook ) ) {
			return 'media-frame';
		}

		if ( $screen_context === 'upload' && $media_mode === 'grid' ) {
			return 'media-frame';
		}

		if ( $screen_context === 'upload' ) {
			return 'static-list';
		}

		return 'url';
	}
}
