<?php

declare(strict_types=1);

namespace Plathix\Modules\Preset;

final class ConflictSuffixGenerator
{
	/**
	 * Returns the first free name among siblings of $parent_id in $taxonomy.
	 * If $name is already free, returns $name unchanged.
	 * Collision sequence: "Name - imported", "Name - imported 2", "Name - imported 3", …
	 */
	public function resolve(string $name, int $parent_id, string $taxonomy): string {
		if ( ! term_exists( $name, $taxonomy, $parent_id ) ) {
			return $name;
		}

		$candidate = $name . ' - imported';
		if ( ! term_exists( $candidate, $taxonomy, $parent_id ) ) {
			return $candidate;
		}

		$n = 2;
		while ( true ) {
			$candidate = $name . ' - imported ' . $n;
			if ( ! term_exists( $candidate, $taxonomy, $parent_id ) ) {
				return $candidate;
			}
			$n++;
		}
	}
}
