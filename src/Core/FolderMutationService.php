<?php

declare(strict_types=1);

namespace Plathix\Core;

final class FolderMutationService
{
	public function __construct(
		private readonly FolderTreeService $tree,
		private readonly FolderCountService $folders
	) {
	}

	/**
	 * @param array<string, mixed> $changes
	 */

	public function applyChanges(int $id, array $changes, string $taxonomy): ?\WP_Error {
		$applied = [];

		if ( array_key_exists( 'name', $changes ) ) {
			$error = $this->tree->rename( $id, sanitize_text_field( (string) $changes['name'] ), $taxonomy );
			if ( is_wp_error( $error ) ) {
				/**
				 * @var \WP_Error $error
				 */

				return $this->withApplied( $error, $applied );
			}
			$applied[] = 'name';
		}

		if ( array_key_exists( 'parent_id', $changes ) ) {
			$error = $this->tree->move( $id, absint( $changes['parent_id'] ), $taxonomy );
			if ( is_wp_error( $error ) ) {
				/** @var \WP_Error $error Narrowed inside is_wp_error() guard. */
				return $this->withApplied( $error, $applied );
			}
			$applied[] = 'parent_id';
		}

		if ( array_key_exists( 'position', $changes ) ) {
			$error = $this->tree->setOrder( $id, absint( $changes['position'] ), $taxonomy );
			if ( is_wp_error( $error ) ) {
				/** @var \WP_Error $error Narrowed inside is_wp_error() guard. */
				return $this->withApplied( $error, $applied );
			}
			$applied[] = 'position';
		}

		if ( array_key_exists( 'color', $changes ) ) {
			update_term_meta( $id, PLATHIX_TERM_COLOR, sanitize_hex_color( (string) $changes['color'] ) ?? '' );
			$this->folders->invalidate( $taxonomy );
		}

		return null;
	}

	/**
	 * @param list<string> $applied
	 */

	private function withApplied(\WP_Error $error, array $applied): \WP_Error {
		if ( [] === $applied ) {
			return $error;
		}

		$data = $error->get_error_data();
		$data = is_array( $data ) ? $data : [];
		$data['applied'] = $applied;

		return new \WP_Error( $error->get_error_code(), $error->get_error_message(), $data );
	}
}
