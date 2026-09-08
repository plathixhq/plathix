<?php

declare(strict_types=1);

namespace Plathix\Modules\ListScreen;

use Plathix\Contracts\ModuleInterface;
use Plathix\Core\FolderRepository;

class Module implements ModuleInterface
{

	public function register(): void
	{
		add_action( 'plathix/modules/boot', [ $this, 'boot' ] );
	}

	public function boot(): void
	{
		if ( is_admin() ) {
			( new FolderColumn() )->register();
			( new ListScreenFragmentsController( new FolderRepository() ) )->register();
			( new SearchSortFields() )->register();
			add_action( 'admin_init', [ $this, 'maybeRedirectLegacyTrashStatus' ] );
		}
	}

	public function maybeRedirectLegacyTrashStatus(): void
	{
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only nav params, no state mutation, only redirect target detection
		$target_url = self::resolveLegacyTrashRedirectTarget(
			(string) ( $GLOBALS['pagenow'] ?? '' ),
			wp_doing_ajax() || wp_doing_cron(),
			sanitize_key( (string) wp_unslash( $_GET['status'] ?? '' ) ),
			! empty( $_GET['attachment-filter'] ),
			(string) wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- passed through add_query_arg/remove_query_arg (WP core) before use, same pattern as core's own default REQUEST_URI usage
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( null === $target_url ) {
			return;
		}

		wp_safe_redirect( $target_url );
		exit;
	}

	public static function resolveLegacyTrashRedirectTarget(
		string $pagenow,
		bool $is_ajax_or_cron,
		string $status_param,
		bool $has_attachment_filter,
		string $request_uri
	): ?string {
		if ( 'upload.php' !== $pagenow ) {
			return null;
		}

		if ( $is_ajax_or_cron ) {
			return null;
		}

		if ( 'trash' !== $status_param ) {
			return null;
		}

		if ( $has_attachment_filter ) {
			return null;
		}

		return add_query_arg( 'attachment-filter', 'trash', remove_query_arg( 'status', $request_uri ) );
	}
}
