<?php

declare(strict_types=1);

namespace Plathix\Modules\Import\Adapters;

/**
 * Импорт папок HappyFiles. Вся механика чтения чужой таксономии — в
 * {@see AbstractTaxonomyImportAdapter}; здесь только то, чем этот конкурент отличается.
 */
final class HappyFiles extends AbstractTaxonomyImportAdapter
{
	protected const TAXONOMY = 'happyfiles_category';

	public function key(): string {
		return 'happyfiles';
	}

	/**
	 * HappyFiles не гарантирует порядок выборки — сортируем по (parent, id), чтобы дерево
	 * строилось детерминированно от корней к листьям.
	 *
	 * @param list<array{id: int, name: string, parent: int, items: list<int>}> $tree
	 * @return list<array{id: int, name: string, parent: int, items: list<int>}>
	 */
	protected function sort_tree(array $tree): array {
		usort(
			$tree,
			static fn(array $a, array $b): int => [ $a['parent'], $a['id'] ] <=> [ $b['parent'], $b['id'] ]
		);

		return $tree;
	}
}
