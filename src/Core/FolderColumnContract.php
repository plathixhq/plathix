<?php

declare(strict_types=1);

namespace Plathix\Core;

/**
 * Стабильное platform-имя WP admin-колонки «Папка» ([internal]). Вынесено из
 * Plathix\Modules\ListScreen\FolderColumn — та остаётся владельцем рендер-логики колонки,
 * а само имя колонки как naming-контракт живёт здесь, доступное PRO без прямого use на
 * feature-класс.
 */
final class FolderColumnContract
{
	public const COLUMN_KEY = 'plathix_folder';
}
