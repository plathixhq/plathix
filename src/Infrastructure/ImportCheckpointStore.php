<?php

declare(strict_types=1);

namespace Plathix\Infrastructure;

final class ImportCheckpointStore
{
	private const TTL_SECONDS = 72 * HOUR_IN_SECONDS;

	/**
	 * @param array<int, int> $map
	 * @param list<int>       $created
	 */

	public function save(string $adapter_key, array $map, int $moved, array $created): void {
		update_option(
			self::optionKey( $adapter_key ),
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
	 * @return array{map: array<int,int>, moved: int, created?: list<int>, created_at: string, expires_at: string}|null
	 */

	public function get(string $adapter_key): ?array {
		$checkpoint = get_option( self::optionKey( $adapter_key ), null );

		if ( ! is_array( $checkpoint ) ) {
			return null;
		}

		/** @var array{map: array<int,int>, moved: int, created?: list<int>, created_at: string, expires_at: string} $checkpoint */
		return $checkpoint;
	}

	public function delete(string $adapter_key): void {
		delete_option( self::optionKey( $adapter_key ) );
	}

	/**
	 * @param array{expires_at: string} $checkpoint
	 */
	public function isExpired(array $checkpoint): bool {
		return strtotime( $checkpoint['expires_at'] ) < time();
	}

	private static function optionKey(string $adapter_key): string {
		return 'plathix_import_checkpoint_' . $adapter_key;
	}
}
