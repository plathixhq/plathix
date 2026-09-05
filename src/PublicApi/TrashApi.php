<?php

declare(strict_types=1);

namespace Plathix\PublicApi;

use Plathix\Modules\Trash\Module;

/**
 * Стабильная граница Free для чтения ключа trash-time postmeta — потребители вне модуля
 * Trash не должны `use`-ить internal-класс `Module` ради одной константы
 * ([internal]: строгая унитарность, 0 исключений; [internal]).
 */
final class TrashApi
{
	/** Ключ postmeta, хранящий unix-timestamp момента перемещения вложения в корзину. */
	public function trashTimeMetaKey(): string
	{
		return Module::TRASH_TIME_META;
	}
}
