<?php

declare(strict_types=1);

namespace Plathix;

use Plathix\Admin\Assets;
use Plathix\Core\FolderAssignmentService;
use Plathix\Core\FolderCountLifecycle;
use Plathix\Core\FolderCountService;
use Plathix\Core\FolderQuery;
use Plathix\Core\FolderRepository;
use Plathix\Core\FolderTreeService;
use Plathix\Core\MediaTrashPolicy;
use Plathix\Core\Migrator;
use Plathix\Core\RequestContext;
use Plathix\Core\Taxonomy;
use Plathix\Core\TaxonomyResolver;
use Plathix\Http\AjaxRouter;
use Plathix\Infrastructure\AllowedMimeTypes;
use Plathix\Infrastructure\Cache;
use Plathix\Infrastructure\JobDispatcher;
use Plathix\Infrastructure\RateLimiter;
use Plathix\Modules\Preset\PresetSchema;

final class Plugin
{
	private static ?self $instance = null;
	private bool $booted = false;
	private int $boot_phase = -1;

	private function __construct(
		private readonly Loader $loader
	) {
	}

	public static function getInstance(): self {
		return self::$instance ??= new self(new Loader());
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;
		AllowedMimeTypes::registerHooks();
		PresetSchema::maybeInstall();

		add_action('init', [ self::class, 'ensureRecurringJobsScheduled' ], 20);

		$this->assertPhase(0, 'migrations');
		Migrator::run(PLATHIX_VERSION);

		$this->assertPhase(1, 'taxonomy');
		new Taxonomy($this->loader);

		$this->assertPhase(2, 'request_context');
		new RequestContext($this->loader);
		new FolderQuery($this->loader);
		new MediaTrashPolicy($this->loader);


		add_action('add_attachment', [ Cache::class, 'onAttachmentAdded' ], 10, 1);
		add_action('delete_attachment', [ Cache::class, 'onAttachmentChange' ], 10, 1);

		add_action('edit_attachment', [ Cache::class, 'onAttachmentChange' ], 10, 1);
		// wp_update_attachment_metadata is a filter (apply_filters); the callback passes
		// $metadata through unchanged. Registered via add_filter so the return value is
		// respected (add_action would type-discard it — same runtime, cleaner contract).
		add_filter('wp_update_attachment_metadata', [ Cache::class, 'onMetadataUpdate' ], 10, 2);

		// Bust gallery cache when any plathix folder term is assigned or removed.
		// Without this, moving attachments between folders leaves the gallery shortcode
		// serving stale cached output until the 15-minute TTL expires naturally.
		add_action(
			'set_object_terms',
			static function (int $object_id, array $terms, array $tt_ids, string $taxonomy): void {
				if ( TaxonomyResolver::isPlathixTaxonomy($taxonomy) ) {
					Cache::onAttachmentChange(null, $taxonomy);
				}
			},
			10,
			4
		);

		if ( is_multisite() ) {
			add_action('switch_blog', [ FolderRepository::class, 'clearRuntimeCache' ]);
		}

		// Bust dashboard_stats immediately on folder mutations instead of waiting for the
		// hourly TTL. plathix/audit/record already fires on folder_created/renamed/moved/

		add_action('plathix/audit/record', [ Cache::class, 'onFolderAuditEvent' ], 10, 1);

		$cache = Cache::make();
		$jobs = new JobDispatcher();
		$rateLimiter = new RateLimiter($cache);

		$this->assertPhase(3, 'transport');
			new Assets($this->loader);

		$repository  = new FolderRepository();
		$folders     = new FolderCountService( $repository, $cache );

		( new FolderCountLifecycle( $folders ) )->register();

		add_action('trashed_post', static function ($post_id) use ($folders): void {
			$folders->adjustForPost( (int) $post_id, -1);
		}, 10, 1);
		add_action('untrashed_post', static function ($post_id) use ($folders): void {
			$folders->adjustForPost( (int) $post_id, +1);
		}, 10, 1);


		$tree        = new FolderTreeService( $repository, $folders );
		$assignment  = new FolderAssignmentService( $repository, $folders, $cache );
		new AjaxRouter( $repository, $folders, $tree, $assignment, $this->loader, $rateLimiter );


		$this->assertPhase(4, 'jobs');
		$jobs->registerHandlers();

		do_action('plathix/modules/register');

		do_action('plathix/modules/boot', $jobs, $rateLimiter, $this->loader);

		$this->loader->run();
	}

	public static function ensureRecurringJobsScheduled(): void {
		if ( ! is_admin() ) {
			return;
		}

		$jobs = new JobDispatcher();
		$jobs->dispatchRecurring( JobDispatcher::JOB_CLEANUP_TEMP, JobDispatcher::JOB_CLEANUP_TEMP_INTERVAL );
		$jobs->dispatchRecurring( JobDispatcher::JOB_ORPHAN_CLEANUP, 30 * DAY_IN_SECONDS );
		$jobs->dispatchRecurring( JobDispatcher::JOB_IMPORT_CHECKPOINT_CLEANUP, DAY_IN_SECONDS );

		$jobs->dispatchRecurring( JobDispatcher::JOB_FOLDER_COUNT_RECONCILE, JobDispatcher::JOB_FOLDER_COUNT_RECONCILE_INTERVAL );
	}

	private function assertPhase(int $phase, string $context): void {
		if ( ! ( defined('WP_DEBUG') && WP_DEBUG ) ) {
			return;
		}

		if ( $phase <= $this->boot_phase && $this->boot_phase > 0 ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- developer-facing diagnostic, never output to users
			throw new \LogicException(
				"Plugin::boot() phase violation: '{$context}' (phase={$phase}) called after phase={$this->boot_phase}. Check boot() for out-of-order initialization."
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$this->boot_phase = $phase;
	}
}
