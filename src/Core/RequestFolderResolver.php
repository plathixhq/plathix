<?php

declare(strict_types=1);

namespace Plathix\Core;

use Plathix\User\Preferences;

final class RequestFolderResolver
{
	public static function resolve(string $post_type, string $taxonomy): int {
		$url_folder = absint( wp_unslash( $_GET['plathix_folder'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only nav param picking the open folder for the current request; sanitized (absint), no form processing, no DB write
		if ( $url_folder > 0 ) {
			return $url_folder;
		}

		// If the user lands on the native media trash screen, sync the selection with the
		// real system Trash term so the current selection stays consistent.
		if ( $post_type === 'attachment' && isset( $_GET['status'] ) && $_GET['status'] === 'trash' ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only nav param detecting the native media trash screen to sync selection; compared as literal, no form processing, no DB write
			return TrashFolder::id( $taxonomy );
		}

		return Preferences::getOpenFolderId( get_current_user_id(), $post_type );
	}
}
