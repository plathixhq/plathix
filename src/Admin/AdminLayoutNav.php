<?php

declare(strict_types=1);

namespace Plathix\Admin;

final class AdminLayoutNav
{
	/**
	 * @return array{main: array<int, array<string, mixed>>, footer: array<int, array<string, mixed>>}
	 */

	public static function sections(): array
	{
		/** @var array<int, array<string, mixed>> $pages */
		$pages = (array) apply_filters( 'plathix/admin/menu_pages', [] );

		$grouped = [ 'main' => [], 'footer' => [] ];

		foreach ( $pages as $page ) {
			if ( ! is_array( $page ) ) {
				continue;
			}
			$section = (string) ( $page['section'] ?? '' );
			if ( ! isset( $grouped[ $section ] ) ) {
				continue;

			}
			$grouped[ $section ][] = $page;
		}

		foreach ( $grouped as &$items ) {
			usort(
				$items,
				static fn (array $a, array $b): int => ( (int) ( $a['order'] ?? 0 ) ) <=> ( (int) ( $b['order'] ?? 0 ) )
			);
		}
		unset( $items );

		return $grouped;
	}
}
