<?php

declare(strict_types=1);

namespace Plathix\Core;

final class OpenFolderResolver
{
	/**
	 * Нормализует запрошенный open-folder-preference к существующей папке в таксономии post_type.
	 *
	 * Несуществующая / cross-taxonomy / ≤0 папка → ROOT (0): невалидный UI-указатель не должен
	 * персиститься в user_meta ([internal]). Раньше запись шла сырым absint() → битый id залипал и
	 * давал «пустую папку» при каждом заходе. Потребители (Upload/FolderQuery/Sidebar) уже трактуют
	 * 0 как ROOT, поэтому нормализация в 0 безопасна и предпочтительнее строгого 4xx для
	 * некритичного UI-preference.
	 *
	 * Резолв таксономии — паритет AjaxRouter::request_taxonomy():377-380 (fromPostType +
	 * taxonomy_exists-fallback), чтобы AJAX и REST не разошлись.
	 */
	public static function normalize(int $folder_id, string $post_type): int {
		if ( $folder_id <= 0 ) {
			return 0;
		}

		$taxonomy = TaxonomyResolver::fromPostType( $post_type );
		if ( ! taxonomy_exists( $taxonomy ) ) {
			$taxonomy = PLATHIX_TAXONOMY;
		}

		return ( new FolderRepository() )->get_by_id( $folder_id, $taxonomy ) instanceof \WP_Term
			? $folder_id
			: 0;
	}
}
