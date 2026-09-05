<?php

declare(strict_types=1);

namespace Plathix\Core;

/**
 * Результат {@see MediaDeleteService::bulk_trash()} ([internal], [internal],
 * хвост #512/#452).
 *
 * Форма собиралась голым массивом и читалась строковыми ключами через `??` у потребителей
 * за границей репозитория (PRO WP-CLI). Опечатка в ключе была бы невидима PHPStan level 8:
 * `treatPhpDocTypesAsCertain: false` (`phpstan.neon:20`) означает, что `@return array{...}`
 * защитой быть не может — только обращение к несуществующему свойству объекта ловится
 * анализом.
 *
 * `toArray()` (по образцу {@see FolderDTO}) существует для точки, где форма всё ещё уходит
 * наружу как array — публичный hook-слот `plathix/media/trash_runner`
 * ({@see MediaTrashRunner::trash()}), чей контракт этим пакетом намеренно не меняется
 * ([internal], excluded пункт "методы, чью форму отдаёт hook-слот").
 */
final class MediaTrashResult
{
	public function __construct(
		/** @var int[] */
		public readonly array $trashed,
		/** @var int[] */
		public readonly array $failed,
		/** @var int[] */
		public readonly array $skipped
	) {
	}

	/** @return array{trashed: array<int>, failed: array<int>, skipped: array<int>} */
	public function toArray(): array {
		return [
			'trashed' => $this->trashed,
			'failed'  => $this->failed,
			'skipped' => $this->skipped,
		];
	}
}
