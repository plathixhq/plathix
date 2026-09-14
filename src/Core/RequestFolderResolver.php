<?php

declare(strict_types=1);

namespace Plathix\Core;

use Plathix\Helpers\Sanitize;
use Plathix\User\Preferences;

final class RequestFolderResolver
{
	public static function resolve(string $post_type, string $taxonomy): int {
		$url_folder = absint( wp_unslash( $_GET['plathix_folder'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only nav param picking the open folder for the current request; sanitized (absint), no form processing, no DB write
		if ( $url_folder > 0 ) {
			return $url_folder;
		}

		if ( $post_type === 'attachment' && isset( $_GET['status'] ) && sanitize_key( Sanitize::toScalarString( wp_unslash( $_GET['status'] ) ) ) === 'trash' ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read-only nav param detecting the native media trash screen to sync selection; sanitized via toScalarString()+sanitize_key(), compared as literal, no form processing, no DB write
			return TrashFolder::id( $taxonomy );
		}

		return Preferences::getOpenFolderId( get_current_user_id(), $post_type );
	}
}
