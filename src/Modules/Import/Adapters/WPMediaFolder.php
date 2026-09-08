<?php

declare(strict_types=1);

namespace Plathix\Modules\Import\Adapters;

final class WPMediaFolder extends AbstractTaxonomyImportAdapter
{
	protected const TAXONOMY = 'wpmf-category';

	private const ROOT_SLUG = 'wp-media-folder-root';

	public function key(): string {
		return 'wpmediafolder';
	}

	/**
	 * @param array{id: int, name: string, parent: int, items: list<int>} $entry
	 * @param array<string, mixed>                                       $raw
	 */
	protected function skipTerm(array $entry, array $raw): bool {
		return (string) ( $raw['slug'] ?? '' ) === self::ROOT_SLUG;
	}
}
