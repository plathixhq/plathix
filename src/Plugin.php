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

	public static function get_instance(): self {
		return self::$instance ??= new self(new Loader());
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;
		AllowedMimeTypes::register_hooks();
		PresetSchema::maybe_install();

		// [internal] ([internal]): self-heal для 3 recurring-джоб на сайтах сети,
		// созданных ПОСЛЕ network-wide активации — Activator::run() посещает только
		// сайты, существующие на момент активации (get_sites()-цикл), новый сайт этим
		// циклом не покрывается. init priority 20 — строго после инициализации Action
		// Scheduler data store (AS вешает себя на init priority 1), тот же принцип, что
		// уже применяется в Trash\Module::ensure_retention_schedule() для RETENTION_JOB.
		// Планирование джоб идемпотентно (guard внутри JobDispatcher) — безопасно как
		// self-heal на каждом request. RETENTION_JOB намеренно не включена сюда — она
		// уже покрыта существующим self-heal в Trash\Module.
		add_action('init', [ self::class, 'ensure_recurring_jobs_scheduled' ], 20);

		$this->assert_phase(0, 'migrations');
		Migrator::run(PLATHIX_VERSION);

		$this->assert_phase(1, 'taxonomy');
		new Taxonomy($this->loader);

		$this->assert_phase(2, 'request_context');
		new RequestContext($this->loader);
		new FolderQuery($this->loader);
		new MediaTrashPolicy($this->loader);
		// Авто-распределение загрузок по папкам бутстрапится через Modules\Upload\Module
		// (фаза 2, Loader приходит аргументом хука plathix/modules/boot), см. plathix.php.

		add_action('add_attachment', [ Cache::class, 'on_attachment_change' ], 10, 1);
		add_action('delete_attachment', [ Cache::class, 'on_attachment_change' ], 10, 1);
		// edit_attachment стреляет при правке вложения (заголовок/alt через wp_update_post).
		// Без него кэш галереи (gallery_items, TTL) держал старый заголовок до истечения
		// TTL — изменения в админке не были видны на фронте. [internal].
		add_action('edit_attachment', [ Cache::class, 'on_attachment_change' ], 10, 1);
		// wp_update_attachment_metadata is a filter (apply_filters); the callback passes
		// $metadata through unchanged. Registered via add_filter so the return value is
		// respected (add_action would type-discard it — same runtime, cleaner contract).
		add_filter('wp_update_attachment_metadata', [ Cache::class, 'on_metadata_update' ], 10, 2);

		// Bust gallery cache when any plathix folder term is assigned or removed.
		// Without this, moving attachments between folders leaves the gallery shortcode
		// serving stale cached output until the 15-minute TTL expires naturally.
		add_action(
			'set_object_terms',
			static function (int $object_id, array $terms, array $tt_ids, string $taxonomy): void {
				if ( str_starts_with($taxonomy, PLATHIX_TAX_PREFIX) || $taxonomy === PLATHIX_TAXONOMY ) {
					Cache::on_attachment_change(null, $taxonomy);
				}
			},
			10,
			4
		);

		if ( is_multisite() ) {
			add_action('switch_blog', [ FolderRepository::class, 'clear_runtime_cache' ]);
		}

		// Bust dashboard_stats immediately on folder mutations instead of waiting for the
		// hourly TTL. plathix/audit/record already fires on folder_created/renamed/moved/
		// deleted (FolderMutationController) with no prior subscriber. [internal].
		add_action('plathix/audit/record', [ Cache::class, 'on_folder_audit_event' ], 10, 1);

		$cache = Cache::make();
		$jobs = new JobDispatcher();
		$rate_limiter = new RateLimiter($cache);

		$this->assert_phase(3, 'transport');
			new Assets($this->loader);
		// REST-аутентификация по сервис-токенам бутстрапится через Modules\ApiKey\Module
		// (фаза 2), см. plathix.php.
		$repository  = new FolderRepository();
		$folders     = new FolderCountService( $repository, $cache );
		// [internal]: единственный владелец per-file дельт рекурсивного счётчика —
		// подписчик lifecycle-хуков (set_object_terms/trashed_post/untrashed_post).
		// Прямые вызовы increment_recursive_chain из Upload/FolderAssignmentService/
		// MediaDeleteService/Trash\Module удалены этим же пакетом.
		( new FolderCountLifecycle( $folders ) )->register();
		// Жизненный цикл НЕ-attachment записей (post/page/CPT). Рекурсивный счётчик папок
		// живёт в termmeta, TTL у него нет, и обновляется он только точечным инкрементом.
		// На trash/untrash термы записи не меняются, поэтому подписка на set_object_terms
		// выше эти события не видит, а Trash\Module::on_trashed_post() обслуживает только
		// attachment. Без этих трёх хуков ушедшая в корзину запись оставалась учтённой в
		// рекурсивном числе навсегда — ошибка накапливалась с каждым событием.
		// Замыкания, а не статические колбэки: нужен уже созданный выше $folders, владелец
		// обеих метрик; собственный экземпляр сервиса здесь плодить незачем.
		add_action('trashed_post', static function ($post_id) use ($folders): void {
			$folders->adjust_for_post( (int) $post_id, -1);
		}, 10, 1);
		add_action('untrashed_post', static function ($post_id) use ($folders): void {
			$folders->adjust_for_post( (int) $post_id, +1);
		}, 10, 1);
		// Delete-путь (и не-attachment, и attachment) ведёт FolderCountLifecycle парой
		// «стэш на pre-хуке -> apply на deleted_post» ([internal]): прямой −1 на
		// before_delete_post нарушал FCCOLD-инвариант (cold-seed предка включал ещё-живую
		// запись и съедал дельту -> вечный +1). Подписки — в register() Lifecycle выше.

		$tree        = new FolderTreeService( $repository, $folders );
		$assignment  = new FolderAssignmentService( $repository, $folders, $cache );
		new AjaxRouter( $repository, $folders, $tree, $assignment, $this->loader, $rate_limiter );
		// REST (RestController + route-классы) бутстрапится через Modules\Rest\Module (фаза 2):
		// платформенные $jobs/$rate_limiter/$loader едут аргументами хука plathix/modules/boot
		// ниже. См. plathix.php.
		// ZIP-отдача (DownloadController) уехала в PRO вместе с ZIP-фичей ([internal]).
		// AjaxRouter — платформенный транспорт, остаётся здесь.

		$this->assert_phase(4, 'jobs');
		$jobs->register_handlers();

		// Gallery бутстрапится через Modules\Gallery\Module (фаза 2), см. plathix.php.

		// Import (импорт из конкурентов) бутстрапится через Modules\Import\Module
		// (фаза 2, под флагом import), см. plathix.php.

		// SVG-поддержка бутстрапится через Modules\Svg\Module (фаза 2), см. plathix.php.

		// CLI-команды бутстрапятся через Modules\Cli\Module (фаза 2, под WP_CLI-guard),
		// см. plathix.php. Регистрация забрана из бывшего God-регистратора в
		// FolderCommand (static register) в модуль.

		// Двухфазный bootstrap модулей: фаза 1 — модули объявляют сервисы и подписываются
		// на фазу 2. Фаза 2 — модули вешают runtime-хуки WP, когда все уже зарегистрированы.
		// Порядок гарантирован: boot стреляет строго после register.
		do_action('plathix/modules/register');
		// Платформенные сервисы передаются в фазу 2 аргументами: модулю Rest нужны
		// $jobs/$rate_limiter/$loader для RestController (общие экземпляры, после
		// register_handlers выше). Остальные модули (boot(): void) лишние аргументы игнорируют.
		do_action('plathix/modules/boot', $jobs, $rate_limiter, $this->loader);

		$this->loader->run();
	}

	/**
	 * Self-heal регистрация трёх platform-owned recurring cron-джоб для сайтов сети,
	 * не посещённых Activator'ом при активации ([internal], [internal]). Зеркалирует
	 * Trash\Module::ensure_retention_schedule() ([internal]) структурно один в один:
	 * подписка на init приоритетом ПОСЛЕ инициализации Action Scheduler data store (AS
	 * сама вешает $store/$logger/$runner на init приоритет 1), dispatch_recurring() сам
	 * идемпотентен (as_has_scheduled_action guard) — повторный вызов на каждом запросе
	 * безопасен.
	 */
	public static function ensure_recurring_jobs_scheduled(): void {
		$jobs = new JobDispatcher();
		$jobs->dispatch_recurring( JobDispatcher::JOB_CLEANUP_TEMP, JobDispatcher::JOB_CLEANUP_TEMP_INTERVAL );
		$jobs->dispatch_recurring( JobDispatcher::JOB_ORPHAN_CLEANUP, 30 * DAY_IN_SECONDS );
		$jobs->dispatch_recurring( JobDispatcher::JOB_IMPORT_CHECKPOINT_CLEANUP, DAY_IN_SECONDS );
		// [internal]: reconcile recursive-счётчиков папок — self-healing, не защита
		// от отсутствующего хука, поэтому планируется тем же self-heal путём, что и три
		// джобы выше (не активированные до этого фикса сайты сети).
		$jobs->dispatch_recurring( JobDispatcher::JOB_FOLDER_COUNT_RECONCILE, JobDispatcher::JOB_FOLDER_COUNT_RECONCILE_INTERVAL );
	}

	private function assert_phase(int $phase, string $context): void {
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
