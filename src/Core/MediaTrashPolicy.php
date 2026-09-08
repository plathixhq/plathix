<?php

declare(strict_types=1);

namespace Plathix\Core;

use Plathix\Loader;

final class MediaTrashPolicy
{
	/**
	 * Media trash is available when attachments exist as a post type.
	 * The plugin now uses WP trash status as the source of truth and does not
	 * silently fall back to permanent deletion.
	 */
	public static function trashEnabled(): bool {
		return post_type_exists('attachment');
	}

	public function __construct(
		private readonly Loader $loader
	) {

		$this->loader->addFilter( 'media_view_settings', $this, 'enableNativeMediaTrash', 10, 2 );

		$this->loader->addFilter( 'bulk_actions-upload', $this, 'fixTrashBulkActions', 10, 1 );
	}

	/**
	 * @param array<string, mixed> $settings
	 * @return array<string, mixed>
	 */
	public function enableNativeMediaTrash(array $settings, ?\WP_Post $post = null): array {
		$settings['mediaTrash'] = 1;
		return $settings;
	}

	/**
	 * @param array<string, string> $actions
	 * @return array<string, string>
	 */

	public function fixTrashBulkActions(array $actions): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only nav filter for building bulk-action labels; sanitized (sanitize_key), not written
		$attachment_filter = sanitize_key( (string) wp_unslash( $_REQUEST['attachment-filter'] ?? '' ) );
		if ( $attachment_filter !== 'trash' ) {
			return $actions;
		}

		return array(
			'untrash' => __( 'Restore', 'plathix' ),
			'delete'  => __( 'Delete permanently', 'plathix' ),
		);
	}
}
