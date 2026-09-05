<?php

declare(strict_types=1);

namespace Plathix\Modules\Trash;

use Plathix\Contracts\ModuleInterface;
use Plathix\Core\FolderId;
use Plathix\Core\FolderRepository;
use Plathix\Core\Taxonomy;
use Plathix\Infrastructure\JobDispatcher;
use Plathix\Infrastructure\MediaModalEnqueue;

/**
 * Модуль Корзины ([internal]).
 *
 * ВЛАДЕЕТ системной папкой Trash: создаёт её term (lifecycle-сущность фичи, канон A7 —
 * как Audit-модуль владеет своей таблицей+cron, Activator.php:64-66) и регистрирует свой slug
 * в платформенном реестре системных slug (фильтр plathix/folder/system_slugs).
 *
 * ГРАНИЦА (скептик A7, PASS 2026-07-09): ДЕЙСТВИЯ trash/restore (MediaDeleteService) остаются
 * ПЛАТФОРМОЙ — wp_trash_post/untrash доступны всегда, term не читают. Модуль владеет только
 * НАДСТРОЙКОЙ-ПРОСМОТРОМ: наша папка Trash в дереве сайдбара. Без модуля файлы всё равно трашатся
 * (нативный WP-trash), видна нативная корзина upload.php?status=trash — теряется лишь наша папка
 * (грациозная деградация: FolderCountService создаёт trash-DTO только if trash_id>0).
 *
 * uncategorized-term НЕ трогается — он структурное ядро (файлу без папки нужен дом), остаётся
 * в платформе (FolderRepository::ensure_system_terms uncategorized-блок, Activator::ensure_uncategorized_terms).
 *
 * Namespace Plathix\Modules\Trash — модуль выносим в PRO по касанию (как Svg).
 */
final class Module implements ModuleInterface
{
	private const TRASH_SLUG      = 'plathix-trash';
	// public: Activator.php регистрирует planning для этого job ([internal]) — тот же
	// паттерн, что JobDispatcher::JOB_CLEANUP_TEMP и другие уже публичные job-константы.
	public const RETENTION_JOB    = 'plathix_job_trash_cleanup';
	// [internal]: единый источник правды для интервала планирования — тот же принцип, что
	// JobDispatcher::JOB_CLEANUP_TEMP_INTERVAL ([internal]), константа рядом с владельцем
	// имени job'ы вместо повторного литерала DAY_IN_SECONDS в Activator и Module.
	public const RETENTION_JOB_INTERVAL = DAY_IN_SECONDS;
	// public: DataWiper::wipe() — единый источник правды для ключа postmeta при
	// uninstall/wipe cleanup ([internal]), тот же принцип, что RETENTION_JOB выше.
	public const TRASH_TIME_META = '_plathix_trash_time';

	/**
	 * Инъекция фазы 2 (boot), сохраняется для self-heal-регистрации на init ([internal]).
	 * @var JobDispatcher|null
	 */
	private ?JobDispatcher $jobs = null;

	/**
	 * Фаза 1: подписка на фазу 2 + на реестр системных slug. Runtime-хуки WP здесь не вешаются.
	 * boot принимает JobDispatcher (retention-cron) → accepted_args=3 обязателен (module-standard №4,
	 * иначе $jobs придёт null — тихий баг).
	 */
	public function register(): void
	{
		add_action( 'plathix/modules/boot', [ $this, 'boot' ], 10, 3 );
		// Реестр system_slugs читается платформой рано (count_all, Preset) — подписку на фильтр
		// вешаем уже в фазе 1, это не runtime-WP-хук, а дополнение платформенного контракта.
		add_filter( 'plathix/folder/system_slugs', [ $this, 'add_trash_slug' ] );
		// term_id папки-корзины для ядра ([internal], [internal]): Core зовёт TrashFolder::id
		// → этот фильтр. Владелец идентичности — модуль; без модуля фильтр отдаёт дефолт 0 (ядро
		// деградирует грациозно). Подписка в фазе 1, до чтения (grid ajax prio 99 / sidebar-config).
		add_filter( 'plathix/folder/trash_id', [ $this, 'resolve_trash_id' ], 10, 2 );
		// id папок, скрытых из живого дерева (soft-trash, [internal]): ядро зовёт HiddenFolders::ids
		// → этот фильтр. Мету _plathix_folder_trashed пишет только модуль, поэтому «скрытые папки» —
		// его надстройка. Без модуля фильтр отдаёт [] (живое дерево показывает всё).
		add_filter( 'plathix/folder/hidden_ids', [ $this, 'resolve_hidden_folder_ids' ], 10, 2 );
		// i18n-строки корзины — собственность модуля ([internal], прецедент FolderColor).
		add_filter( 'plathix/sidebar/i18n', [ $this, 'add_sidebar_i18n' ] );
		// [internal]: RETENTION_JOB — собственная константа модуля (не JobDispatcher::JOB_*),
		// Deactivator.php не может её знать явно — модуль сам снимает своё через generic
		// extension point (симметрично plathix/jobs/register_handlers).
		add_action( 'plathix/jobs/unschedule', [ $this, 'unschedule_retention_job' ] );
	}

	/**
	 * Дописывает i18n-строки корзины в конфиг сайдбара (перенос из платформенного SidebarI18nBuilder).
	 * Без модуля JS использует fallback-строки в t() — UI не ломается, показывает англ. дефолт.
	 *
	 * @param array<string, string> $strings
	 * @return array<string, string>
	 */
	public function add_sidebar_i18n(array $strings): array
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
		$strings['folder_restore_failed_notif'] = __( 'folder could not be restored', 'plathix' );
		$strings['folder_purge_failed_notif']  = __( 'folder could not be deleted permanently', 'plathix' );
		/* translators: короткая подпись «файлы» в счётчике корзины «N Ф / N П» (напр. рус. «Ф»). */
		$strings['trash_files_short']         = __( 'F', 'plathix' );
		/* translators: короткая подпись «папки» в счётчике корзины «N Ф / N П» (напр. рус. «П»). */
		$strings['trash_folders_short']       = __( 'D', 'plathix' );
		/* translators: aria-подпись иконки «файлы» в счётчике корзины (доступность). */
		$strings['trash_files_label']         = __( 'Files', 'plathix' );
		/* translators: aria-подпись иконки «папки» в счётчике корзины (доступность). */
		$strings['trash_folders_label']       = __( 'Folders', 'plathix' );

		return $strings;
	}

	/**
	 * Фаза 2: создание trash-term на init + retention (мета-хуки + recurring-cron очистки).
	 * plathix/modules/boot стреляет на plugins_loaded синхронно ДО init.
	 *
	 * @param JobDispatcher|null $jobs         Платформенный диспетчер (инъекция фазы 2). Без него
	 *                                          cron не планируется — мета-хуки и восстановление
	 *                                          работают, но авто-очистки нет (graceful, не фатал).
	 * @param mixed              $rate_limiter Не используется Trash (контракт хука plathix/modules/boot).
	 * @param mixed              $loader       Не используется Trash (контракт хука).
	 */
	public function boot(?JobDispatcher $jobs = null, mixed $rate_limiter = null, mixed $loader = null): void
	{
		// JS-UI корзины ([internal]): кнопки + overlays + панели папок.
		// [internal] (тот же корень, что #31): admin_enqueue_scripts не срабатывает в
		// редакторе Elementor. wp_enqueue_media — нативный WP-хук media-picker-контекста.
		// [internal]: единая точка регистрации — Plathix\Infrastructure\MediaModalEnqueue.
		MediaModalEnqueue::register( [ $this, 'enqueue_scripts' ], 20, 20 );

		add_action( 'init', [ $this, 'ensure_trash_terms' ] );
		// [internal] (Слой 2): дублирующая подписка на ленивый self-heal с пути чтения
		// дерева (Taxonomy::ensure_ready → do_action). Если чужой фатал оборвал init до нашего
		// init-колбэка, trash-term всё равно создастся при первом чтении дерева. Идемпотентно
		// (ensure_trash_terms сам проверяет get_term_by перед вставкой).
		add_action( 'plathix/taxonomy/ensure_system_terms', [ $this, 'ensure_trash_terms' ] );

		// Настройка срока хранения корзины ([internal] B) — таб на странице Settings.
		( new TrashSettings() )->register();

		// Retention ([internal]): отсчёт по нашей мете _plathix_trash_time, чтобы порог
		// (до 180д) переопределял нативный EMPTY_TRASH_DAYS без правки wp-config. Хуки ловят ЛЮБОЙ
		// путь трэша/restore (REST, PublicApi, нативный WP-admin, CLI), в отличие от слота trash_runner.
		add_action( 'trashed_post', [ $this, 'on_trashed_post' ] );
		add_action( 'untrashed_post', [ $this, 'on_untrashed_post' ] );

		// [internal] ([internal], Нога 1): центральный guard против эскалации
		// wp_trash_post() на уже-trashed посте в permanent delete ([internal]). Регистрация
		// на plugins_loaded (эта фаза) безопасна — guard читает текущий post_status
		// существующего поста, не зависит от готовности post-type/taxonomy registration.
		add_filter( 'pre_trash_post', [ $this, 'block_trash_of_already_trashed_post' ], 10, 3 );

		// Recurring-очистка. Раннер подписан всегда (обработать job).
		add_action( self::RETENTION_JOB, [ new TrashCleanupJobRunner(), 'run' ] );

		// [internal]: planning (dispatch_recurring) НЕ вызывается здесь. boot() стреляет на
		// plugins_loaded — синхронно ДО init, до готовности Action Scheduler data store (AS сам
		// инициализирует $store на init, priority 1 — vendor/woocommerce/action-scheduler/classes/
		// abstracts/ActionScheduler.php). Вызов dispatch_recurring() на этом хуке — no-op
		// (as_has_scheduled_action/as_schedule_recurring_action called incorrectly, подтверждено
		// на двух живых стендах). Planning для новых установок — Activator.php (тот же паттерн,
		// что JOB_CLEANUP_TEMP/JOB_ORPHAN_CLEANUP/JOB_IMPORT_CHECKPOINT_CLEANUP). Self-heal для
		// уже активированных установок — ensure_retention_schedule() на init, priority 20 (после AS).
		if ( $jobs instanceof JobDispatcher ) {
			$this->jobs = $jobs;
			add_action( 'init', [ $this, 'ensure_retention_schedule' ], 20 );
		}
	}

	/**
	 * Self-heal регистрация retention-job для установок, активированных ДО этого фикса ([internal]):
	 * их Activator-путь уже отработал без этого dispatch_recurring() вызова и не повторится сам.
	 * Приоритет 20 на init — гарантированно после инициализации Action Scheduler data store (AS
	 * вешает $store/$logger/$runner на init приоритет 1). dispatch_recurring() сам идемпотентен
	 * (as_has_scheduled_action guard) — повторный вызов на каждом запросе безопасен, тот же принцип,
	 * что уже применяется в этом модуле для ensure_trash_terms (двойная подписка init +
	 * plathix/taxonomy/ensure_system_terms).
	 */
	public function ensure_retention_schedule(): void
	{
		$this->jobs?->dispatch_recurring( self::RETENTION_JOB, self::RETENTION_JOB_INTERVAL );
	}

	/**
	 * Снимает RETENTION_JOB при деактивации ([internal]). Подписан на generic
	 * plathix/jobs/unschedule (Deactivator::run_for_site()), симметрично тому, как
	 * platform-owned JOB_* хуки снимаются в самом Deactivator — но эта константа
	 * принадлежит модулю, не JobDispatcher, поэтому модуль сам её снимает.
	 */
	public function unschedule_retention_job(int $blog_id): void
	{
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			// [internal]: args-shape обязан совпадать с тем, что dispatch_recurring() реально
			// положил в очередь ([['blog_id' => $blog_id]]) — пустой [] не находит запись
			// (Deactivator.php несёт то же самое объяснение подробно).
			as_unschedule_all_actions( self::RETENTION_JOB, [ [ 'blog_id' => $blog_id ] ], JobDispatcher::group_for_blog( $blog_id ) );
		}
	}

	/**
	 * Энкьюит бандл UI корзины ([internal]): кнопки + overlays + панели папок.
	 * По образцу FolderColor/Module — зависит от plathix-sidebar, грузится defer там, где поднят сайдбар.
	 */
	public function enqueue_scripts(): void {
		if ( ! wp_script_is( 'plathix-sidebar', 'enqueued' ) ) {
			return;
		}

		$asset_file = defined( 'PLATHIX_PATH' ) ? PLATHIX_PATH . 'assets/js/trash.asset.php' : '';
		$asset      = ( $asset_file && file_exists( $asset_file ) )
			? include $asset_file
			: [ 'dependencies' => [], 'version' => defined( 'PLATHIX_VERSION' ) ? PLATHIX_VERSION : '1' ];

		wp_enqueue_script(
			'plathix-trash',
			defined( 'PLATHIX_ASSETS_URL' ) ? PLATHIX_ASSETS_URL . 'js/trash.js' : '',
			array_unique( array_merge( [ 'plathix-sidebar' ], (array) ( $asset['dependencies'] ?? [] ) ) ),
			$asset['version'],
			[
				'in_footer' => true,
				'strategy'  => 'defer',
			]
		);

		// CSS кнопок корзины уехал из sidebar.css в trash/trash.css ([internal]).
		wp_enqueue_style(
			'plathix-trash',
			defined( 'PLATHIX_ASSETS_URL' ) ? PLATHIX_ASSETS_URL . 'css/trash.css' : '',
			[ 'plathix-sidebar' ],
			$asset['version']
		);
	}

	/**
	 * При трэше attachment: снять нативную _wp_trash_meta_time (чтобы wp_scheduled_delete не удалил
	 * файл на EMPTY_TRASH_DAYS-й день) и записать нашу _plathix_trash_time (отсчёт retention).
	 * update (не add) — повторный трэш восстановленного файла обновляет метку.
	 *
	 * [internal]: декремент рекурсивного счётчика отсюда удалён — на этом же хуке
	 * его теперь применяет единственный владелец Core\FolderCountLifecycle (для ВСЕХ
	 * путей trash одинаково, без is_processing-маркера и его слепых зон — [internal]).
	 * Этот модуль владеет только retention-метами.
	 *
	 * @param int $post_id
	 */
	public function on_trashed_post($post_id): void
	{
		$post_id = (int) $post_id;
		if ( get_post_type( $post_id ) !== 'attachment' ) {
			return;
		}
		delete_post_meta( $post_id, '_wp_trash_meta_time' );
		update_post_meta( $post_id, self::TRASH_TIME_META, time() );
	}

	/**
	 * При восстановлении attachment: убрать нашу _plathix_trash_time (симметрия — мета-сирота не
	 * остаётся; ловит restore и через нативный WP-admin, не только bulk_restore).
	 *
	 * [internal]: инкремент счётчика отсюда удалён — см. on_trashed_post() выше.
	 *
	 * @param int $post_id
	 */
	public function on_untrashed_post($post_id): void
	{
		$post_id = (int) $post_id;
		if ( get_post_type( $post_id ) !== 'attachment' ) {
			return;
		}
		delete_post_meta( $post_id, self::TRASH_TIME_META );
	}

	/**
	 * [internal] ([internal], #656, #805): центральный guard против TOCTOU-класса —
	 * wp_trash_post() на посте, уже находящемся в trash, эскалирует в WP core до
	 * wp_delete_post($id, true) (permanent delete). Три Plathix-caller'а (bulk_trash/
	 * bulk_restore/TrashCleanupJobRunner) закрыли это локально per-attachment локом
	 * (MediaTrashLock, #656/#683), но инвариант жил только в их дисциплине — новый caller
	 * (плагинный или сторонний/core Media Library) мог обойти его, просто вызвав
	 * wp_trash_post() напрямую. pre_trash_post — единственная точка, через которую core САМ
	 * пропускает КАЖДЫЙ вызов wp_trash_post() независимо от caller'а ([internal] Нога 1).
	 *
	 * Возврат false прерывает trash до мутации post_status (short-circuit самого WP core,
	 * не Plathix-специфичный early-return) — permanent-delete эскалация внутри
	 * wp_trash_post() физически не достигается. Обычный (не-уже-trashed) trash пропускается
	 * без изменений: возвращается ИСХОДНЫЙ $check ($check остаётся null у любого другого
	 * подписчика фильтра до этого — гейт не гасит остальную цепочку pre_trash_post).
	 *
	 * @param mixed    $check           Текущее значение short-circuit (null, если ещё никто
	 *                                  не решил). WP core проверяет null !== $check.
	 * @param \WP_Post $post            Пост-кандидат на trash.
	 * @param string   $previous_status Статус поста до trash (не используется этим guard'ом —
	 *                                  предикат зависит только от ТЕКУЩЕГО post_status).
	 * @return mixed
	 */
	public function block_trash_of_already_trashed_post($check, \WP_Post $post, string $previous_status) {
		if ( $post->post_status === 'trash' ) {
			return false;
		}

		return $check;
	}

	/**
	 * Добавляет trash-slug в платформенный реестр системных slug (дефолт Free = только uncategorized).
	 *
	 * @param array<int, string> $slugs
	 * @return array<int, string>
	 */
	public function add_trash_slug(array $slugs): array
	{
		$slugs[] = self::TRASH_SLUG;

		return $slugs;
	}

	/**
	 * Резолвит term_id папки-корзины для ядра через фильтр `plathix/folder/trash_id`
	 * ([internal], [internal]). Владелец trash-идентичности — этот модуль; ядро зовёт
	 * {@see \Plathix\Core\TrashFolder::id} и не знает про TRASH_SLUG. Логика 1:1 с прежним
	 * FolderRepository::get_trash_term_id. Без модуля подписчика нет → фильтр отдаёт дефолт 0.
	 */
	public function resolve_trash_id(int $id, string $taxonomy): int
	{
		$term = get_term_by( 'slug', self::TRASH_SLUG, $taxonomy );

		return $term instanceof \WP_Term ? (int) $term->term_id : 0;
	}

	/**
	 * Резолвит id папок, скрытых из живого дерева (soft-trash), для ядра через фильтр
	 * `plathix/folder/hidden_ids` ([internal]). Модуль — владелец меты `_plathix_folder_trashed`;
	 * ядро зовёт {@see \Plathix\Core\HiddenFolders::ids}. Без модуля подписчика нет → фильтр отдаёт [].
	 *
	 * @param array<int, int> $ids
	 * @return array<int, int>
	 */
	public function resolve_hidden_folder_ids(array $ids, string $taxonomy): array
	{
		return ( new FolderRepository() )->get_trashed_ids( $taxonomy );
	}

	/**
	 * Создаёт/чинит trash-term во всех включённых таксономиях (перенесено из платформенного
	 * FolderRepository::ensure_system_terms trash-блока по канону A7). Idempotent.
	 */
	public function ensure_trash_terms(): void
	{
		foreach ( Taxonomy::get_enabled_taxonomies() as $taxonomy ) {
			$trash = get_term_by( 'slug', self::TRASH_SLUG, $taxonomy );
			if ( ! $trash instanceof \WP_Term ) {
				wp_insert_term(
					'Trash',
					$taxonomy,
					[
						'slug'   => self::TRASH_SLUG,
						'parent' => FolderId::ROOT,
					]
				);
			} elseif ( (int) $trash->parent !== FolderId::ROOT ) {
				wp_update_term(
					(int) $trash->term_id,
					$taxonomy,
					[
						'parent' => FolderId::ROOT,
					]
				);
			}
		}

		FolderRepository::clear_runtime_cache();
	}
}
