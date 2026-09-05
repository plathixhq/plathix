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
		$screen_context = $this->resolve_sidebar_context( $hook );
		if ( null === $screen_context ) {
			return null;
		}

		$media_mode      = $this->get_media_library_mode();
		$screen_kind     = $this->get_screen_kind( $screen_context );
		$filter_strategy = $this->get_filter_strategy( $screen_context, $media_mode );

		return compact( 'screen_context', 'screen_kind', 'media_mode', 'filter_strategy' );
	}

	/**
	 * @return array{screen_context:string,screen_kind:string,media_mode:string,filter_strategy:string}|null
	 */
	public function resolve_frontend(): ?array {
		if ( ! $this->is_frontend_media_modal_request() ) {
			return null;
		}

		return [
			'screen_context'  => 'upload',
			'screen_kind'     => 'modal',
			'media_mode'      => 'grid',
			'filter_strategy' => 'media-frame',
		];
	}

	public function is_upload_list_page(): bool {
		global $pagenow;
		return $pagenow === 'upload.php';
	}

	public function should_render_static_shell(): bool {
		global $pagenow;
		$hook = is_string( $pagenow ) ? $pagenow : '';

		if ( $hook === '' ) {
			return false;
		}

		$screen_context = $this->resolve_sidebar_context( $hook );
		if ( null === $screen_context ) {
			return false;
		}

		return ! ( $screen_context === 'upload' && ! $this->is_upload_list_page() );
	}

	private function resolve_sidebar_context(string $hook): ?string {
		// CTAN-201: Free attachment-native — резолвер знает только медиатеку (upload/modal).
		// edit.php-контекст не существует во Free: экраны списков записей обслуживает PRO
		// собственным резолвером/конфигом ([internal]).
		if ( $this->has_media_modal( $hook ) ) {
			return 'upload';
		}

		return null;
	}

	private function has_media_modal(string $hook): bool {
		if ( in_array( $hook, [ 'upload.php', 'post.php', 'post-new.php', 'site-editor.php' ], true ) ) {
			return true;
		}

		return RequestContext::is_page_builder_request();
	}

	private function is_frontend_media_modal_request(): bool {
		if (
			! BuilderDetect::is_frontend_builder_request(
			is_admin(),
			[ 'attachment' ], // CTAN-201: attachment-native
			$_GET // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only detection, values never output
			)
		) {
			return false;
		}

		// [internal]: детектор выше только определяет builder-контекст, не право доступа —
		// без этого гейта анонимный/безправый посетитель получал полное дерево папок
		// медиатеки, добавив к любому фронтенд-URL один GET-параметр builder-маркера.
		// Тот же владелец правила, что уже гейтит REST /folders (RestController::check()).
		return Authorization::capability( 'view', 'attachment' );
	}

	private function get_media_library_mode(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- read-only screen-resolution preference determining grid/list media view for enqueue decision; sanitized (sanitize_key), no form processing, no DB write
		$requested_mode = sanitize_key( (string) wp_unslash( $_GET['mode'] ?? $_POST['mode'] ?? '' ) );
		if ( in_array( $requested_mode, [ 'grid', 'list' ], true ) ) {
			return $requested_mode;
		}

		$mode = sanitize_key( (string) get_user_option( 'media_library_mode', get_current_user_id() ) );

		return in_array( $mode, [ 'grid', 'list' ], true ) ? $mode : 'grid';
	}

	private function get_screen_kind(string $screen_context): string {
		if ( $screen_context === 'upload' && ! $this->is_upload_list_page() ) {
			return 'modal';
		}

		return 'static';
	}

	private function get_filter_strategy(string $screen_context, string $media_mode): string {
		if ( $screen_context === 'upload' && ! $this->is_upload_list_page() ) {
			return 'media-frame';
		}

		if ( $screen_context === 'upload' && $media_mode === 'grid' ) {
			return 'media-frame';
		}

		// CEC-103 ([internal]): Free-резолвер знает только медиатеку —
		// 'edit' здесь недостижим (resolve_sidebar_context отдаёт upload/null), а свои
		// экраны PRO резолвит сам и подаёт готовую стратегию своим ctx.
		if ( $screen_context === 'upload' ) {
			return 'static-list';
		}

		return 'url';
	}
}
