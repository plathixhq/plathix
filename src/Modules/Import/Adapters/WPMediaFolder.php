<?php

declare(strict_types=1);

namespace Plathix\Modules\Import\Adapters;

/**
 * Импорт папок WPMediaFolder. Вся механика чтения чужой таксономии — в
 * {@see AbstractTaxonomyImportAdapter}; здесь только то, чем этот конкурент отличается.
 */
final class WPMediaFolder extends AbstractTaxonomyImportAdapter
{
	protected const TAXONOMY = 'wpmf-category';

	/** Служебный корневой термин WPMediaFolder, в дерево Plathix не переносится. */
	private const ROOT_SLUG = 'wp-media-folder-root';

	public function key(): string {
		return 'wpmediafolder';
	}

	/**
	 * @param array{id: int, name: string, parent: int, items: list<int>} $entry
	 * @param array<string, mixed>                                       $raw
	 */
	protected function skip_term(array $entry, array $raw): bool {
		return (string) ( $raw['slug'] ?? '' ) === self::ROOT_SLUG;
	}
}
