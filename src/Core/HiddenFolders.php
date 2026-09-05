<?php

declare(strict_types=1);

namespace Plathix\Core;

/**
 * Единственный Core-источник id папок, скрытых из живого дерева (soft-trash, [internal]).
 *
 * Продолжение #133 ([internal]): soft-trash — надстройка Trash. Мету
 * `_plathix_folder_trashed` пишет ТОЛЬКО Trash-модуль ({@see \Plathix\Modules\Trash\FolderTrashService}
 * / {@see \Plathix\Modules\Trash\FolderRestoreService}); ядро её лишь читало напрямую через
 * FolderRepository::get_trashed_ids. Теперь Core получает список скрытых папок только через фильтр
 * `plathix/folder/hidden_ids` — направление зависимости модуль→ядро.
 *
 * Грациозная деградация: Trash-модуль выключен → подписчика нет → фильтр возвращает [] → живое
 * дерево и счёт показывают ВСЕ папки, папок-корзины нет. Ядро работает.
 *
 * @see \Plathix\Core\TrashFolder аналогичный фасад для trash term_id (#133).
 */
final class HiddenFolders
{
	/**
	 * id папок, помеченных в корзину (soft-trash) в данной таксономии; [] если корзина недоступна
	 * (Trash-модуль выключен либо папок в корзине нет).
	 *
	 * @return array<int, int>
	 */
	public static function ids(string $taxonomy): array {
		/** @var array<int, int> $ids */
		$ids = (array) apply_filters( 'plathix/folder/hidden_ids', [], $taxonomy );

		return $ids;
	}
}
