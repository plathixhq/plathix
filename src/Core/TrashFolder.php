<?php

declare(strict_types=1);

namespace Plathix\Core;

/**
 * Единственный Core-источник term_id папки-корзины ([internal], [internal]).
 *
 * Trash — отключаемая надстройка (канон A7, {@see \Plathix\Modules\Trash\Module}): ядро НЕ
 * держит trash-идентичность у себя. Идентичность (slug, term, lifecycle) принадлежит
 * Modules\Trash\Module, который резолвит id через фильтр `plathix/folder/trash_id`. Ядро
 * зовёт только этот фасад — направление зависимости модуль→ядро (Core не читает
 * Module::TRASH_SLUG, что сохраняет edition-split).
 *
 * Грациозная деградация: модуль выключен → подписчика нет → фильтр возвращает дефолт 0 →
 * grid не фильтрует по trash, sidebar не рисует папку Trash, count не строит trash-DTO
 * (гейты `trash_id > 0`). Ядро работает, теряется только папка Trash.
 */
final class TrashFolder
{
	/**
	 * term_id папки-корзины в данной таксономии, или 0 если корзина недоступна
	 * (Trash-модуль выключен либо term ещё не создан).
	 */
	public static function id(string $taxonomy): int {
		return (int) apply_filters( 'plathix/folder/trash_id', 0, $taxonomy );
	}
}
