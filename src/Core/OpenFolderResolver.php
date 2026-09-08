<?php

declare(strict_types=1);

namespace Plathix\Core;

final class OpenFolderResolver
{

	public static function normalize(int $folder_id, string $post_type): int {
		if ( $folder_id <= 0 ) {
			return 0;
		}

		$taxonomy = TaxonomyResolver::fromPostTypeOrFallback( $post_type );

		return ( new FolderRepository() )->getById( $folder_id, $taxonomy ) instanceof \WP_Term
			? $folder_id
			: 0;
	}
}
