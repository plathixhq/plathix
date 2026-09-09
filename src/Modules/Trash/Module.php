<?php

declare(strict_types=1);

namespace Plathix\Modules\Trash;

use Plathix\Contracts\ModuleInterface;
use Plathix\Core\FolderId;
use Plathix\Core\FolderRepository;
use Plathix\Core\Taxonomy;
use Plathix\Infrastructure\JobDispatcher;
use Plathix\Infrastructure\Logger;
use Plathix\Infrastructure\MediaModalEnqueue;
use Plathix\PublicApi\ReplaceApi;

final class Module implements ModuleInterface
{
	private const TRASH_SLUG      = 'plathix-trash';

	public const RETENTION_JOB    = 'plathix_job_trash_cleanup';

	public const RETENTION_JOB_INTERVAL = DAY_IN_SECONDS;

	public const TRASH_TIME_META = '_plathix_trash_time';

	private ?JobDispatcher $jobs = null;

	public function register(): void
	{
		add_action( 'plathix/modules/boot', [ $this, 'boot' ], 10, 3 );

		add_filter( 'plathix/folder/system_slugs', [ $this, 'addTrashSlug' ] );

		add_filter( 'plathix/folder/trash_id', [ $this, 'resolveTrashId' ], 10, 2 );

		add_filter( 'plathix/folder/hidden_ids', [ $this, 'resolveHiddenFolderIds' ], 10, 2 );

		add_filter( 'plathix/sidebar/i18n', [ $this, 'addSidebarI18n' ] );

		add_action( 'plathix/jobs/unschedule', [ $this, 'unscheduleRetentionJob' ] );
	}

	/**
	 * @param array<string, string> $strings
	 * @return array<string, string>
	 */

	public function addSidebarI18n(array $strings): array
	{
		$strings['move_to_trash']             = __( 'Move to Trash', 'plathix' );
		$strings['files_selected']            = __( 'files selected', 'plathix' );
		$strings['trash_confirm_hint']        = __( 'Files will be moved to trash and can be restored from there.', 'plathix' );
		$strings['file_trashed_notif']        = __( '1 file moved to trash', 'plathix' );
		$strings['files_trashed_notif']       = __( 'files moved to trash', 'plathix' );
		$strings['files_trash_failed_notif']  = __( 'files could not be moved to trash', 'plathix' );
		$strings['restore_label']             = __( 'Restore', 'plathix' );
		$strings['trashed_folders_heading']   = __( 'Trash', 'plathix' );
		$strings['loading']                   = __( 'Loading…', 'plathix' );
		$strings['folders_section']           = __( 'Folders', 'plathix' );
		$strings['purge_label']               = __( 'Delete permanently', 'plathix' );
		$strings['purge_confirm']             = __( 'Delete this folder permanently? This cannot be undone.', 'plathix' );
		$strings['deleted_today']             = __( 'deleted today', 'plathix' );
		$strings['deleted_yesterday']         = __( 'deleted yesterday', 'plathix' );
		/* translators: %d — number of days since the folder was moved to Trash. */
		$strings['deleted_days_ago']          = __( 'deleted %d days ago', 'plathix' );
		$strings['file_restored_notif']       = __( '1 file restored', 'plathix' );
		$strings['files_restored_notif']      = __( 'files restored', 'plathix' );
		$strings['files_restore_failed_notif'] = __( 'files could not be restored', 'plathix' );

		$strings['file_restored_moved_notif']  = __( '1 file restored and moved', 'plathix' );
		$strings['files_restored_moved_notif'] = __( 'files restored and moved', 'plathix' );

		/* translators: %s: destination folder name. */
		$strings['dragdrop_restore_confirm_named'] = __( 'Restore file and move it to folder "%s"?', 'plathix' );
		$strings['dragdrop_restore_confirm']       = __( 'Restore file and move it to this folder?', 'plathix' );
		$strings['upload_blocked_in_trash']        = __( 'Go to your active media library to upload new files.', 'plathix' );
		$strings['folder_restore_failed_notif'] = __( 'folder could not be restored', 'plathix' );
		$strings['folder_purge_failed_notif']  = __( 'folder could not be deleted permanently', 'plathix' );
		/* translators: Placeholder values are inserted at runtime. */

		$strings['trash_files_short']         = __( 'F', 'plathix' );
		/* translators: Placeholder values are inserted at runtime. */

		$strings['trash_folders_short']       = __( 'D', 'plathix' );
		/* translators: Placeholder values are inserted at runtime. */

		$strings['trash_files_label']         = __( 'Files', 'plathix' );
		/* translators: Placeholder values are inserted at runtime. */

		$strings['trash_folders_label']       = __( 'Folders', 'plathix' );

		return $strings;
	}

	/**
	 * @param JobDispatcher|null $jobs
	 * @param mixed              $rateLimiter
	 * @param mixed              $loader
	 */

	public function boot(?JobDispatcher $jobs = null, mixed $rateLimiter = null, mixed $loader = null): void
	{

		MediaModalEnqueue::register( [ $this, 'enqueueScripts' ], 20, 20 );

		add_action( 'init', [ $this, 'ensureTrashTerms' ] );

		add_action( 'plathix/taxonomy/ensureSystemTerms', [ $this, 'ensureTrashTerms' ] );

		( new TrashSettings() )->register();

		add_action( 'trashed_post', [ $this, 'onTrashedPost' ] );
		add_action( 'untrashed_post', [ $this, 'onUntrashedPost' ] );

		add_filter( 'pre_trash_post', [ $this, 'blockTrashOfAlreadyTrashedPost' ], 10, 3 );

		add_action( self::RETENTION_JOB, static function (array $args = []): void {
			( new TrashCleanupJobRunner() )->run( $args, [ new JobDispatcher(), 'runInBlogContext' ] );
		} );

		if ( $jobs instanceof JobDispatcher ) {
			$this->jobs = $jobs;
			add_action( 'init', [ $this, 'ensureRetentionSchedule' ], 20 );
		}
	}

	public function ensureRetentionSchedule(): void
	{
		if ( ! is_admin() ) {
			return;
		}

		$this->jobs?->dispatchRecurring( self::RETENTION_JOB, self::RETENTION_JOB_INTERVAL );
	}

	public function unscheduleRetentionJob(int $blog_id): void
	{
		if ( function_exists( 'as_unschedule_all_actions' ) ) {

			as_unschedule_all_actions( self::RETENTION_JOB, JobDispatcher::recurringUnscheduleArgs( $blog_id ), JobDispatcher::groupForBlog( $blog_id ) );
		}
	}

	public function enqueueScripts(): void {
		if ( ! wp_script_is( 'plathix-sidebar', 'enqueued' ) ) {
			return;
		}

		$asset = \Plathix\Infrastructure\AssetManifest::read( 'js/trash.asset.php' );

		wp_enqueue_script(
			'plathix-trash',
			defined( 'PLATHIX_ASSETS_URL' ) ? PLATHIX_ASSETS_URL . 'js/trash.js' : '',
			array_unique( array_merge( [ 'plathix-sidebar' ], $asset['dependencies'] ?? [] ) ),
			$asset['version'],
			[
				'in_footer' => true,
				'strategy'  => 'defer',
			]
		);

		wp_enqueue_style(
			'plathix-trash',
			defined( 'PLATHIX_ASSETS_URL' ) ? PLATHIX_ASSETS_URL . 'css/trash.css' : '',
			[ 'plathix-sidebar' ],
			$asset['version']
		);
	}

	/**
	 * @param int $post_id
	 */

	public function onTrashedPost($post_id): void
	{
		$post_id = (int) $post_id;
		if ( get_post_type( $post_id ) !== 'attachment' ) {
			return;
		}
		delete_post_meta( $post_id, '_wp_trash_meta_time' );

		$old    = get_post_meta( $post_id, self::TRASH_TIME_META, true );
		$now    = time();
		$result = update_post_meta( $post_id, self::TRASH_TIME_META, $now );

		if ( ! $result && $old !== $now ) {
			Logger::error( 'trash_retention_meta_write_failed', [ 'post_id' => $post_id ] );
		}
	}

	/**
	 * @param int $post_id
	 */

	public function onUntrashedPost($post_id): void
	{
		$post_id = (int) $post_id;
		if ( get_post_type( $post_id ) !== 'attachment' ) {
			return;
		}
		delete_post_meta( $post_id, self::TRASH_TIME_META );
	}

	/**
	 * @param mixed    $check
	 * @param \WP_Post $post
	 * @param string   $previous_status
	 * @return mixed
	 */

	public function blockTrashOfAlreadyTrashedPost($check, \WP_Post $post, string $previous_status) {
		if ( $post->post_status === 'trash' ) {
			return false;
		}

		if ( ( new ReplaceApi() )->isReplaceInProgress( $post->ID ) ) {
			return false;
		}

		return $check;
	}

	/**
	 * @param array<int, string> $slugs
	 * @return array<int, string>
	 */

	public function addTrashSlug(array $slugs): array
	{
		$slugs[] = self::TRASH_SLUG;

		return $slugs;
	}

	public function resolveTrashId(int $id, string $taxonomy): int
	{
		$term = get_term_by( 'slug', self::TRASH_SLUG, $taxonomy );

		return $term instanceof \WP_Term ? (int) $term->term_id : 0;
	}

	/**
	 * @param array<int, int> $ids
	 * @return array<int, int>
	 */

	public function resolveHiddenFolderIds(array $ids, string $taxonomy): array
	{
		return ( new FolderRepository() )->getTrashedIds( $taxonomy );
	}

	public function ensureTrashTerms(): void
	{
		foreach ( Taxonomy::getEnabledTaxonomies() as $taxonomy ) {
			$trash = get_term_by( 'slug', self::TRASH_SLUG, $taxonomy );
			if ( ! $trash instanceof \WP_Term ) {
				$created = wp_insert_term(
					'Trash',
					$taxonomy,
					[
						'slug'   => self::TRASH_SLUG,
						'parent' => FolderId::ROOT,
					]
				);

				if ( is_wp_error( $created ) ) {
					Logger::warning( 'trash_module_ensure_trash_term_failed', [ 'taxonomy' => $taxonomy ] );
				}
			} elseif ( (int) $trash->parent !== FolderId::ROOT ) {
				$updated = wp_update_term(
					(int) $trash->term_id,
					$taxonomy,
					[
						'parent' => FolderId::ROOT,
					]
				);

				if ( is_wp_error( $updated ) ) {
					Logger::warning( 'trash_module_reparent_trash_term_failed', [ 'taxonomy' => $taxonomy, 'term_id' => $trash->term_id ] );
				}
			}
		}

		FolderRepository::clearRuntimeCache();
	}
}
