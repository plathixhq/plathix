<?php

declare(strict_types=1);

namespace Plathix\Core;

/**
 * Результат постановки импорта в очередь (BOUNDDTO-002, [internal]).
 *
 * Форма собиралась голым массивом в {@see \Plathix\Modules\Import\ImportManager::start_import()}
 * и читалась строковыми ключами двумя транспортами — AJAX и PublicApi (а через него CLI в
 * PRO). Опечатка в ключе была невидима: PHPStan не проверяет ключи массива, если чтение
 * идёт через `??`, и именно так прошёл баг #452 (`$job['job_id'] ?? 0` при записи `jobId`
 * — успешный dispatch отдавал 500).
 *
 * Здесь форма — реальный тип, а не PHPDoc: `treatPhpDocTypesAsCertain: false`
 * (`phpstan.neon:20`) означает, что PHPDoc в этом проекте намеренно не истина, поэтому
 * `@return array{...}` защитой быть не может, а обращение к несуществующему свойству —
 * может.
 *
 * Метода `to_array()` здесь намеренно НЕТ, в отличие от {@see FolderDTO}: у двух
 * транспортов разная внешняя форма ответа (AJAX добавляет вычисляемое `queued`), и каждый
 * собирает свой JSON сам, из свойств. Общий `to_array(): array<string, mixed>` вернул бы
 * тип обратно в «массив чего угодно» ровно на той границе, ради которой класс и заведён.
 */
final class ImportJobDTO
{
	public function __construct(
		/** 'queued' — задача поставлена; 'dispatch_failed' — очередь не приняла. */
		public readonly string $status,
		/** Реальный id задачи из dispatch; 0, когда поставить не удалось. */
		public readonly int $jobId,
		public readonly string $adapter,
		public readonly string $postType
	) {
	}

	public function isQueued(): bool {
		return 'queued' === $this->status;
	}
}
