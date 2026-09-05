<?php

declare(strict_types=1);

namespace Plathix\Helpers;

final class QueryHelper
{
	/**
	 * @param array<int|string, mixed> $existing
	 * @param array<string, mixed>     $new_clause
	 * @return array<int|string, mixed>
	 */
	public static function merge_tax_query_safely(array $existing, array $new_clause): array {
		if ( $existing === [] ) {
			return [ $new_clause ];
		}

		$relation = strtoupper( (string) ( $existing['relation'] ?? 'AND' ) );

		if ( $relation !== 'OR' ) {
			$merged = $existing;
			$merged[] = $new_clause;

			return $merged;
		}

		return [
			'relation' => 'AND',
			$existing,
			$new_clause,
		];
	}
}
