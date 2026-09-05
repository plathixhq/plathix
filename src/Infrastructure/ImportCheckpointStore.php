<?php

declare(strict_types=1);

namespace Plathix\Infrastructure;

/**
 * Persistent checkpoint для ImportManager::import() — сохраняет промежуточное состояние
 * BFS-обхода дерева между волнами, чтобы обрыв процесса (timeout, crash) не терял прогресс.
 */
final class ImportCheckpointStore
{
	private const TTL_SECONDS = 72 * HOUR_IN_SECONDS;

	/**
	 * @param array<int, int> $map     old_id => new_id (create-or-reuse: значение может быть id
	 *                                 УЖЕ существовавшей папки — карта отвечает «куда мапить»,
	 *                                 не «что откатывать»)
	 * @param list<int>       $created id терминов, РЕАЛЬНО созданных этим импортом, в порядке
	 *                                 вставки — единственный законный источник для rollback
	 *                                 ([internal], [internal])
	 */
	public function save(string $adapter_key, array $map, int $moved, array $created): void {
		update_option(
			self::option_key( $adapter_key ),
			[
				'map'        => $map,
				'moved'      => $moved,
				'created'    => $created,
				'created_at' => gmdate( 'c' ),
				'expires_at' => gmdate( 'c', time() + self::TTL_SECONDS ),
			],
			false
		);
	}

	/**
	 * Ключ `created` optional: записи, сохранённые кодом до [internal], его не содержат —
	 * читатели обязаны трактовать отсутствие как «создателей не знаем» (rollback fail-safe:
	 * ничего не удалять), не как пустой откат по map.
	 *
	 * @return array{map: array<int,int>, moved: int, created?: list<int>, created_at: string, expires_at: string}|null
	 */
	public function get(string $adapter_key): ?array {
		$checkpoint = get_option( self::option_key( $adapter_key ), null );

		if ( ! is_array( $checkpoint ) ) {
			return null;
		}

		/** @var array{map: array<int,int>, moved: int, created?: list<int>, created_at: string, expires_at: string} $checkpoint */
		return $checkpoint;
	}

	public function delete(string $adapter_key): void {
		delete_option( self::option_key( $adapter_key ) );
	}

	/**
	 * @param array{expires_at: string} $checkpoint
	 */
	public function is_expired(array $checkpoint): bool {
		return strtotime( $checkpoint['expires_at'] ) < time();
	}

	private static function option_key(string $adapter_key): string {
		return 'plathix_import_checkpoint_' . $adapter_key;
	}
}
