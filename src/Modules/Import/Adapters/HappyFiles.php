<?php

declare(strict_types=1);

namespace Plathix\Modules\Import\Adapters;

final class HappyFiles extends AbstractTaxonomyImportAdapter
{
	protected const TAXONOMY = 'happyfiles_category';

	public function key(): string {
		return 'happyfiles';
	}

	/**
	 * @param list<array{id: int, name: string, parent: int, items: list<int>}> $tree
	 * @return list<array{id: int, name: string, parent: int, items: list<int>}>
	 */

	protected function sortTree(array $tree): array {
		usort(
			$tree,
			static fn(array $a, array $b): int => [ $a['parent'], $a['id'] ] <=> [ $b['parent'], $b['id'] ]
		);

		return $tree;
	}
}
