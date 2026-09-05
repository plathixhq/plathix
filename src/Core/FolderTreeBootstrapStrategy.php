<?php

declare(strict_types=1);

namespace Plathix\Core;

/**
 * Единственный источник правды для «когда дерево папок слишком велико для eager-загрузки»
 * ([internal], [internal]).
 *
 * Domain-факт о дереве папок, не presentation-специфика: остаётся истинным независимо от
 * того, кто рендерит bootstrap (sidebar сегодня, потенциальный второй consumer завтра).
 * Имя фильтра `plathix/sidebar/lazy_tree_threshold` не меняется — переехало только место
 * вызова `apply_filters()`.
 */
final class FolderTreeBootstrapStrategy
{
	public static function threshold(): int {
		return (int) apply_filters( 'plathix/sidebar/lazy_tree_threshold', 200 );
	}

	public static function should_defer(int $folder_count): bool {
		return $folder_count > self::threshold();
	}
}
