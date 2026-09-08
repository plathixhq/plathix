<?php

declare(strict_types=1);

namespace Plathix\Infrastructure\Jobs;

use Plathix\Infrastructure\ImportCheckpointStore;
use Plathix\Modules\Import\ImportManager;

final class ImportCheckpointCleanupJobRunner
{
	/** @param array<string, mixed> $args */
	public function run(array $args, callable $runInBlogContext): void {
		$blog_id = (int) ( $args['blog_id'] ?? get_current_blog_id() );

		$runInBlogContext(
			$blog_id,
			function (): void {
				global $wpdb;

				$checkpoint_store = new ImportCheckpointStore();
				$import_manager   = new ImportManager();

				$option_names = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off scan of a bounded set of adapter checkpoint options (few adapters, not a hot path), same pattern as DataWiper.
					$wpdb->prepare(
						"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
						$wpdb->esc_like( 'plathix_import_checkpoint_' ) . '%'
					)
				);

				foreach ( (array) $option_names as $option_name ) {
					$adapter_key = substr( (string) $option_name, strlen( 'plathix_import_checkpoint_' ) );
					if ( '' === $adapter_key ) {
						continue;
					}

					$checkpoint = $checkpoint_store->get( $adapter_key );
					if ( null !== $checkpoint && $checkpoint_store->isExpired( $checkpoint ) ) {

						$import_manager->rollbackPartial( $adapter_key );
					}
				}
			}
		);
	}
}
