<?php

declare(strict_types=1);

namespace Plathix\Modules\Import;

use Plathix\Core\FolderAssignmentService;
use Plathix\Core\FolderCountService;
use Plathix\Core\FolderRepository;
use Plathix\Core\FolderTreeService;
use Plathix\Core\ImportJobDTO;
use Plathix\Core\TaxonomyResolver;
use Plathix\Helpers\Sanitize;
use Plathix\Http\RestAuditPayloadBuilders;
use Plathix\Infrastructure\Cache;
use Plathix\Infrastructure\ImportCheckpointStore;
use Plathix\Infrastructure\JobDispatcher;
use Plathix\Infrastructure\JobLockService;
use Plathix\Infrastructure\Logger;
use Plathix\Loader;

final class ImportManager
{
	/** @var ImportAdapterInterface[] */
	private array $adapters = [];

	private \Closure $folder_creator;

	private \Closure $items_mover;

	private ?array $defaultServices = null;

	/**
	 * @param ?\Closure $folder_creator
	 * @param ?\Closure $items_mover
	 */

	public function __construct(
		private readonly ?Loader $loader = null,
		?\Closure $folder_creator = null,
		?\Closure $items_mover = null
	) {
		$this->adapters[] = new Adapters\HappyFiles();
		$this->adapters[] = new Adapters\FileBird();
		$this->adapters[] = new Adapters\RealMediaLib();
		$this->adapters[] = new Adapters\WPMediaFolder();
		$this->adapters[] = new Adapters\WickedFolders();

		$this->folder_creator = $folder_creator ?? function (string $name, int $parent, string $taxonomy): array|\WP_Error {
			return $this->defaultServices()['tree']->createDetailed( $name, $parent, $taxonomy );
		};
		$this->items_mover = $items_mover ?? function (array $items, int $folder, string $taxonomy): array {
			return $this->defaultServices()['assignment']->moveItemsBulk( $items, $folder, $taxonomy );
		};

		if ( $this->loader ) {
			$this->loader->addAction( 'plathix/import/job', $this, 'handleJobImport' );
		}
	}

	/**
	 * @return array{tree:FolderTreeService,assignment:FolderAssignmentService}
	 */

	private function defaultServices(): array {
		if ( null === $this->defaultServices ) {
			$cache         = Cache::make();
			$repository    = new FolderRepository();
			$countService = new FolderCountService( $repository, $cache );
			$this->defaultServices = [
				'tree'       => new FolderTreeService( $repository, $countService ),
				'assignment' => new FolderAssignmentService( $repository, $countService, $cache ),
			];
		}

		return $this->defaultServices;
	}

	public function register(): void {
		if ( $this->loader ) {
			return;
		}

		add_action( 'plathix/import/job', [ $this, 'handleJobImport' ] );
	}

	/** @return array<string, bool> */
	public function available(): array {
		$result = [];
		foreach ( $this->adapters as $adapter ) {
			$result[ $adapter->key() ] = $adapter->isAvailable();
		}

		return $result;
	}

	/**
	 * @return array<string,bool>
	 */

	public function imported(): array {
		$result = [];
		foreach ( $this->adapters as $adapter ) {
			$result[ $adapter->key() ] = (bool) get_option( self::importedOptionKey( $adapter->key() ), false );
		}

		return $result;
	}

	private static function importedOptionKey(string $adapter_key): string {
		return 'plathix_import_done_' . $adapter_key;
	}

	public function hasPendingCheckpoint(string $adapter_key): bool {
		$checkpoint_store = new ImportCheckpointStore();
		$checkpoint = $checkpoint_store->get( $adapter_key );

		return null !== $checkpoint && ! $checkpoint_store->isExpired( $checkpoint );
	}

	/**
	 * @return array{moved: int, errors: list<array{code: string, message: string}>}
	 */
	public function import(string $adapter_key, string $taxonomy = PLATHIX_TAXONOMY): array {
		$adapter = $this->findAdapter( $adapter_key );

		if ( ! $adapter || ! $adapter->isAvailable() ) {
			return [ 'moved' => 0, 'errors' => [] ];
		}

		$lock_service = new JobLockService();
		$lock_name = 'import:' . $adapter_key;
		$lock = $lock_service->acquireOrder( $lock_name );

		if ( 'none' === $lock['mode'] ) {

			return [
				'moved'  => 0,
				'errors' => [ [ 'code' => 'importLocked', 'message' => __( 'Import is already running for this adapter.', 'plathix' ) ] ],
			];
		}

		try {
			return $this->importLocked( $adapter, $adapter_key, $taxonomy );
		} finally {
			$lock_service->releaseOrder( $lock_name, $lock );
		}
	}

	/**
	 * @return array{moved: int, errors: list<array{code: string, message: string}>}
	 */
	private function importLocked(ImportAdapterInterface $adapter, string $adapter_key, string $taxonomy): array {
		$checkpoint_store = new ImportCheckpointStore();
		$checkpoint = $checkpoint_store->get( $adapter_key );

		$map = [];
		$moved = 0;
		$created_ids = [];

		if ( null !== $checkpoint && ! $checkpoint_store->isExpired( $checkpoint ) ) {

			foreach ( $checkpoint['map'] as $old_id => $new_id ) {
				if ( 0 === $new_id || term_exists( $new_id, $taxonomy ) ) {
					$map[ $old_id ] = $new_id;
				}
			}
			$moved = $checkpoint['moved'];

			foreach ( $checkpoint['created'] ?? [] as $created_id ) {
				if ( $created_id > 0 && term_exists( $created_id, $taxonomy ) ) {
					$created_ids[] = (int) $created_id;
				}
			}
		}

		$tree_data = $adapter->exportTree();
		$errors = [];

		$pending = $tree_data;
		$max_passes = count( $pending ) + 1;
		$pass = 0;

		while ( $pending !== [] && $pass++ < $max_passes ) {
			$still_pending = [];

			foreach ( $pending as $node ) {
				$old_id = absint( $node['id'] ?? 0 );
				$parent_old = absint( $node['parent'] ?? 0 );

				if ( $parent_old > 0 && ! isset( $map[ $parent_old ] ) ) {
					$still_pending[] = $node;
					continue;
				}

				if ( $old_id > 0 && isset( $map[ $old_id ] ) ) {

					continue;
				}

				$parent_new = $map[ $parent_old ] ?? 0;
				$created = ( $this->folder_creator )( (string) ( $node['name'] ?? 'Imported' ), $parent_new, $taxonomy );
				if ( is_wp_error( $created ) ) {

					$errors[] = [
						'code'    => (string) $created->get_error_code(),
						'message' => (string) $created->get_error_message(),
					];
					continue;
				}
				/**
				 * @var array{id: int, created: bool} $created
				 */


				$new_id = (int) $created['id'];
				if ( $old_id > 0 ) {
					$map[ $old_id ] = $new_id;
				}

				if ( $created['created'] ) {
					$created_ids[] = $new_id;
				}

				$items = Sanitize::ids( $node['items'] ?? [] );
				if ( $items !== [] ) {
					$result = ( $this->items_mover )( $items, $new_id, $taxonomy );
					$moved += (int) ( $result['moved'] ?? 0 );

					foreach ( (array) ( $result['failed'] ?? [] ) as $failed_item_id ) {
						$errors[] = [
							'code'    => 'item_move_failed',
							'message' => sprintf(
								'Item %d could not be moved into folder %d.',
								absint( $failed_item_id ),
								$new_id
							),
						];
					}
				}
			}

			$pending = $still_pending;

			$checkpoint_store->save( $adapter_key, $map, $moved, $created_ids );
		}

		foreach ( $pending as $node ) {
			$errors[] = [
				'code'    => 'orphaned_node',
				'message' => sprintf(
					'Folder row (old id %d, parent %d) could not be resolved — parent never appeared in the import.',
					absint( $node['id'] ?? 0 ),
					absint( $node['parent'] ?? 0 )
				),
			];
		}

		$checkpoint_store->delete( $adapter_key );

		return [ 'moved' => $moved, 'errors' => $errors ];
	}

	/**
	 * @return string
	 */

	public function rollbackPartial(string $adapter_key): string {
		$checkpoint_store = new ImportCheckpointStore();

		if ( null === $checkpoint_store->get( $adapter_key ) ) {
			return 'noop';
		}

		$lock_service = new JobLockService();
		$lock_name = 'import:' . $adapter_key;
		$import_lock = $lock_service->acquireOrder( $lock_name );
		if ( 'none' === $import_lock['mode'] ) {
			return 'locked';
		}

		try {
			$tree = $this->defaultServices()['tree'];
			$structure_lock = $tree->acquireStructureLock( PLATHIX_TAXONOMY );
			if ( 'none' === $structure_lock['mode'] ) {
				return 'locked';
			}

			try {

				$checkpoint = ( new ImportCheckpointStore() )->get( $adapter_key );
				if ( null === $checkpoint ) {

					return 'noop';
				}

				foreach ( array_reverse( $checkpoint['created'] ?? [] ) as $new_id ) {
					if ( $new_id > 0 ) {
						$deleted = wp_delete_term( (int) $new_id, PLATHIX_TAXONOMY );

						if ( is_wp_error( $deleted ) || false === $deleted ) {
							Logger::warning( 'import_manager_rollback_delete_term_failed', [ 'term_id' => (int) $new_id ] );
						}
					}
				}

				$checkpoint_store->delete( $adapter_key );

				return 'done';
			} finally {
				$tree->releaseStructureLock( PLATHIX_TAXONOMY, $structure_lock );
			}
		} finally {
			$lock_service->releaseOrder( $lock_name, $import_lock );
		}
	}

	public function startImport(string $adapter, string $post_type, int $user_id): ImportJobDTO {
		$job_id = ( new JobDispatcher() )->dispatch(
			JobDispatcher::JOB_IMPORT,
			[
				'adapter'   => $adapter,
				'user_id'   => $user_id,
				'post_type' => $post_type,
			]
		);

		if ( $job_id > 0 ) {

			do_action(
				'plathix/audit/record',
				'import_job_queued',
				RestAuditPayloadBuilders::importJob( $adapter, $post_type, $job_id )
			);
		}

		return new ImportJobDTO(
			$job_id > 0 ? 'queued' : 'dispatch_failed',
			$job_id > 0 ? $job_id : 0,
			$adapter,
			$post_type
		);
	}

	/**
	 * @param array<string, mixed> $args
	 */
	public function handleJobImport(array $args = []): void {
		$adapter = sanitize_key( (string) ( $args['adapter'] ?? '' ) );
		if ( '' === $adapter ) {
			return;
		}

		$tree_adapter = $this->findAdapter( $adapter );
		if ( ! $tree_adapter ) {
			Logger::warning( 'Import: unknown adapter in job payload', [ 'adapter' => $adapter ] );
			return;
		}

		$post_type = sanitize_key( (string) ( $args['post_type'] ?? 'attachment' ) );
		$taxonomy  = TaxonomyResolver::fromPostType( $post_type );

		$had_source_data = $tree_adapter->exportTree() !== [];

		$source_query_failed = $tree_adapter->hadQueryFailure();

		$result = $this->import( $adapter, $taxonomy );
		$moved  = $result['moved'];

		if ( $result['errors'] !== [] ) {
			foreach ( $result['errors'] as $error ) {
				Logger::warning(
					'Import: folder creation failed',
					[ 'adapter' => $adapter, 'code' => $error['code'], 'message' => $error['message'] ]
				);
			}
		}

		if ( $moved > 0 || ( ! $had_source_data && ! $source_query_failed ) ) {
			update_option( self::importedOptionKey( $adapter ), true, false );
		}

		$jobs = new JobDispatcher();
		$action_id = $jobs->getActionId( JobDispatcher::JOB_IMPORT, $args );

		if ( $action_id > 0 ) {

			$jobs->storeResultForAction(
				$action_id,
				[
					'adapter' => $adapter,
					'moved'   => $moved,
					'user_id' => (int) ( $args['user_id'] ?? 0 ),
					'blog_id' => get_current_blog_id(),
				]
			);
		}
	}

	private function findAdapter(string $adapter_key): ?ImportAdapterInterface {
		foreach ( $this->adapters as $candidate ) {
			if ( $candidate->key() === $adapter_key ) {
				return $candidate;
			}
		}

		return null;
	}
}
