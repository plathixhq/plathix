<?php

declare(strict_types=1);

namespace Plathix\Infrastructure\Jobs;

use Plathix\Core\FolderCountLifecycle;

/**
 * Handles the plathix_job_orphan_cleanup Action Scheduler job.
 */
final class OrphanCleanupJobRunner
{
	/** @param array<string, mixed> $args */
	public function run(array $args, callable $run_in_blog_context): void {
		$blog_id = (int) ( $args['blog_id'] ?? get_current_blog_id() );

		$run_in_blog_context(
			$blog_id,
			function (): void {
				$saved_taxonomies = (array) get_option( 'plathix_taxonomies', [] );
				$live_taxonomies  = array_values(
					array_filter(
						get_taxonomies(),
						static fn(string $taxonomy): bool => PLATHIX_TAXONOMY === $taxonomy || str_starts_with( $taxonomy, PLATHIX_TAX_PREFIX )
					)
				);
				$taxonomies = array_unique( array_merge( $saved_taxonomies, $live_taxonomies ) );

				foreach ( $taxonomies as $taxonomy ) {
					$taxonomy = sanitize_key( (string) $taxonomy );
					if ( '' === $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
						continue;
					}

					$terms = get_terms(
						[
							'taxonomy'   => $taxonomy,
							'hide_empty' => false,
							'fields'     => 'ids',
						]
					);

					if ( is_wp_error( $terms ) ) {
						continue;
					}

					foreach ( $terms as $term_id ) {
						$object_ids = get_objects_in_term( (int) $term_id, $taxonomy );
						if ( is_wp_error( $object_ids ) ) {
							continue;
						}

						foreach ( $object_ids as $object_id ) {
							$post = get_post( (int) $object_id );
							// [internal]: НЕ трогаем trashed-посты — их retention (когда снимать
							// relation) уже полностью владеет TrashCleanupJobRunner, который
							// удаляет пост навсегда по истечении настроенного срока хранения
							// (wp_delete_post force=true сам чистит term_relationships на уровне
							// WP core). Снимать relation здесь безусловно ломало restore из
							// корзины (FolderRestoreService/MediaDeleteService используют её как
							// критерий восстановления) раньше, чем retention реально истекал.
							// Единственный легитимный случай для этой джобы — пост физически не
							// существует (термин указывает на удалённый мимо WP API объект).
							// [internal]: suppress() — SQL-истина (batch_counts()) никогда
							// не считала этот object_id (JOIN с wp_posts не находит строку), дельта
							// здесь заведомо ложная; тот же принцип, что уже применён к агрегатным
							// операциям владельца дерева (FolderTreeService::delete_recursive_body).
							if ( ! $post ) {
								FolderCountLifecycle::suppress(
									static fn () => wp_remove_object_terms( (int) $object_id, (int) $term_id, $taxonomy )
								);
							}
						}
					}

					$missing_position_terms = get_terms(
						[
							'taxonomy'   => $taxonomy,
							'hide_empty' => false,
							'fields'     => 'all',
							'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- meta_query with NOT EXISTS is the only WP-native way to find terms missing a specific meta key (position backfill), not a hot request path.
								[
									'key'     => PLATHIX_TERM_POSITION,
									'compare' => 'NOT EXISTS',
								],
							],
						]
					);

					if ( is_wp_error( $missing_position_terms ) ) {
						continue;
					}

					foreach ( $this->build_missing_position_backfill( (array) $missing_position_terms, $taxonomy ) as $term_id => $position ) {
						update_term_meta( (int) $term_id, PLATHIX_TERM_POSITION, $position );
					}
				}

				// Скан протухших option-locks (plathix_lock_*) УДАЛЁН вместе с option-fallback
				// лока ([internal], [internal]): эти ключи больше никто не пишет,
				// скан всегда находил бы 0. Уборка orphan term-relationships и backfill позиций
				// выше — сохранены, это отдельная ответственность джобы.
			}
		);
	}

	/**
	 * @param array<int,\WP_Term> $terms
	 * @return array<int,int>
	 */
	private function build_missing_position_backfill(array $terms, string $taxonomy): array {
		if ( $terms === [] ) {
			return [];
		}

		$grouped = [];
		foreach ( $terms as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}

			$parent_id = (int) $term->parent;
			if ( ! isset( $grouped[ $parent_id ] ) ) {
				$grouped[ $parent_id ] = [];
			}

			$grouped[ $parent_id ][] = $term;
		}

		$result = [];

		foreach ( $grouped as $parent_id => $children ) {
			$max_position = 0;
			$sibling_ids  = (array) get_terms(
				[
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
					'parent'     => (int) $parent_id,
					'fields'     => 'ids',
				]
			);

			// Batch-prime term meta cache (fields=ids skips automatic priming).
			if ( ! empty( $sibling_ids ) ) {
				update_termmeta_cache( $sibling_ids );
			}

			foreach ( $sibling_ids as $sibling_id ) {
				$position = (int) get_term_meta( (int) $sibling_id, PLATHIX_TERM_POSITION, true );
				if ( $position > $max_position ) {
					$max_position = $position;
				}
			}

			usort(
				$children,
				static fn(\WP_Term $left, \WP_Term $right): int => strcasecmp( $left->name, $right->name )
			);

			foreach ( $children as $term ) {
				$max_position = $max_position > 0 ? $max_position + 1000 : 1000;
				$result[ (int) $term->term_id ] = $max_position;
			}
		}

		return $result;
	}
}
