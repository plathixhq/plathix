<?php

declare(strict_types=1);

namespace Plathix\Infrastructure;

use Plathix\Loader;

final class JobDispatcher
{
	// ZIP-генерация — контекстная фича (уехала в PRO, [internal]). Хук-строка
	// остаётся стабильным контрактом Free↔PRO: PRO dispatch'ит job на неё и вешает свой
	// раннер через `add_action`. Free-платформа сам zip-раннер НЕ несёт.
	public const JOB_ZIP_GENERATE   = 'plathix_job_zip_generate';
	public const JOB_IMPORT         = 'plathix_job_import';
	public const JOB_CLEANUP_TEMP   = 'plathix_job_cleanup_temp';
	public const JOB_ORPHAN_CLEANUP = 'plathix_job_orphan_cleanup';
	public const JOB_REORDER        = 'plathix_job_reorder';
	public const JOB_IMPORT_CHECKPOINT_CLEANUP = 'plathix_job_import_checkpoint_cleanup';
	// [internal]: reconcile recursive-счётчиков папок с живой SQL-истиной (форма R1,
	// daily — self-healing, не горячий путь; см. FolderCountReconcileJobRunner).
	public const JOB_FOLDER_COUNT_RECONCILE = 'plathix_job_folder_count_reconcile';
	public const JOB_FOLDER_COUNT_RECONCILE_INTERVAL = DAY_IN_SECONDS;

	// Единственный источник интервала планирования JOB_CLEANUP_TEMP ([internal]): раньше
	// Activator::run_for_site() и Jobs\CleanupJobRunner::CLEANUP_INTERVAL держали одно и то
	// же значение двумя независимыми литералами, синхронизировать которые можно было только
	// вручную. JobDispatcher уже владеет именем job'ы (JOB_CLEANUP_TEMP выше) и уже создаёт
	// Jobs\CleanupJobRunner как зависимость — логичный единственный владелец интервала.
	public const JOB_CLEANUP_TEMP_INTERVAL = HOUR_IN_SECONDS * 2;

	public const MAX_ATTEMPTS       = 5;

	// [internal] ([internal]): три различимых исхода run_guarded() — bool стирал
	// разницу между "лок занят прямо сейчас" и "работа уже сделана кем-то раньше";
	// вызывающая сторона (например ZipRequestHandler) обязана реагировать на них по-разному
	// (RETRY-семантика неявна только для BUSY, ALREADY_DONE — это честный успех).
	public const RESULT_EXECUTED     = 'executed';
	public const RESULT_ALREADY_DONE = 'already_done';
	public const RESULT_BUSY         = 'busy';

	private JobLockService $lock_service;
	private JobStatusRepository $status_repository;
	private Jobs\ImportJobRunner $import_runner;
	private Jobs\ReorderJobRunner $reorder_runner;
	private Jobs\OrphanCleanupJobRunner $orphan_runner;
	private Jobs\CleanupJobRunner $cleanup_runner;
	private Jobs\ImportCheckpointCleanupJobRunner $import_checkpoint_cleanup_runner;
	private Jobs\FolderCountReconcileJobRunner $folder_count_reconcile_runner;

	public function __construct() {
		$this->lock_service     = new JobLockService();
		$this->status_repository = new JobStatusRepository();
		$this->import_runner    = new Jobs\ImportJobRunner();
		$this->reorder_runner   = new Jobs\ReorderJobRunner( $this->lock_service );
		$this->orphan_runner    = new Jobs\OrphanCleanupJobRunner();
		$this->cleanup_runner   = new Jobs\CleanupJobRunner();
		$this->import_checkpoint_cleanup_runner = new Jobs\ImportCheckpointCleanupJobRunner();
		$this->folder_count_reconcile_runner    = new Jobs\FolderCountReconcileJobRunner( $this->lock_service );
	}

	public function register_handlers(?Loader $loader = null): void {
		if ( $loader ) {
			$loader->add_action( self::JOB_IMPORT, $this, 'handle_import' );
			$loader->add_action( self::JOB_REORDER, $this, 'handle_reorder' );
			$loader->add_action( self::JOB_CLEANUP_TEMP, $this, 'handle_cleanup' );
			$loader->add_action( self::JOB_ORPHAN_CLEANUP, $this, 'handle_orphan_cleanup' );
			$loader->add_action( self::JOB_IMPORT_CHECKPOINT_CLEANUP, $this, 'handle_import_checkpoint_cleanup' );
			$loader->add_action( self::JOB_FOLDER_COUNT_RECONCILE, $this, 'handle_folder_count_reconcile' );
			$this->announce_extensible_handlers();
			return;
		}

		add_action( self::JOB_IMPORT, [ $this, 'handle_import' ] );
		add_action( self::JOB_REORDER, [ $this, 'handle_reorder' ] );
		add_action( self::JOB_CLEANUP_TEMP, [ $this, 'handle_cleanup' ] );
		add_action( self::JOB_ORPHAN_CLEANUP, [ $this, 'handle_orphan_cleanup' ] );
		add_action( self::JOB_IMPORT_CHECKPOINT_CLEANUP, [ $this, 'handle_import_checkpoint_cleanup' ] );
		add_action( self::JOB_FOLDER_COUNT_RECONCILE, [ $this, 'handle_folder_count_reconcile' ] );
		$this->announce_extensible_handlers();
	}

	/**
	 * Расширяемая точка регистрации job-раннеров ([internal]).
	 *
	 * Внешние модули (PRO) подписываются, чтобы навесить СВОЙ раннер на свою job-строку.
	 * Диспетчер передаёт себя — модуль получает доступ к платформенным утилитам очереди
	 * (`get_temp_dir`/`make_fingerprint`/`release_fingerprint`/`run_in_blog_context`,
	 * все public). Так очередь остаётся generic-платформой и не хардкодит контекстные фичи.
	 *
	 * NB: реальную регистрацию раннера контекстной фичи PRO вешает через прямой
	 * `add_action('<job_hook>', ...)` в своей фазе boot — Action Scheduler исполняет job
	 * в отдельном фоновом запросе, где boot Free+PRO проходит полностью. Этот хук —
	 * generic extension point (не только под zip), стреляет из `register_handlers`.
	 */
	private function announce_extensible_handlers(): void {
		do_action( 'plathix/jobs/register_handlers', $this );
	}

	/**
	 * Путь к временной директории Plathix.
	 *
	 * Делегирует единому резолверу {@see TempDirectory::path()}; сигнатура и
	 * возвращаемое значение сохранены для всех существующих вызывающих сторон.
	 */
	public function get_temp_dir(): string {
		return ( new TempDirectory() )->path();
	}

	/**
	 * [internal]: единая точка правды для heavy-job dedupe/cap gate. Каждый вызывающий код
	 * (REST/AJAX/Public API/будущие) обязан проходить через один и тот же
	 * RateLimiter::can_dispatch_heavy_job() — раньше это было обязанностью каждого call site
	 * вызвать явно (два из трёх это делали, третий (ImportExportApi) — нет, [internal]).
	 * Перенос сюда делает пропуск gate невозможным физически: новый call site не может
	 * обойти его, не переписав сам dispatch(). REST/AJAX пути (ImportRequestHandler,
	 * ImportAjaxHandler) СОХРАНЯЮТ свой явный pre-check — он даёт точную HTTP-семантику
	 * (разные 429-коды/сообщения для per_user/server_cap) и остаётся первым слоем; этот
	 * gate внутри dispatch() — defense-in-depth второй слой (fail-safe для любого call
	 * site, который явный pre-check не сделал). Метод can_dispatch_heavy_job() read-only
	 * (только as_get_scheduled_actions), повторный вызов безопасен, без побочных эффектов.
	 *
	 * @param array<string, mixed> $args
	 */
	public function dispatch(string $job, array $args = [], int $delay = 0): int {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			Logger::warning( 'job_dispatch_skipped_no_action_scheduler', [ 'job' => $job ] );
			return 0;
		}

		if ( isset( $args['user_id'] ) ) {
			$dispatch_reason = ( new RateLimiter( Cache::make() ) )->can_dispatch_heavy_job(
				$job,
				$args,
				(int) $args['user_id']
			);

			if ( null !== $dispatch_reason ) {
				return 0;
			}
		}

		$args['blog_id'] = get_current_blog_id();
		$args            = $this->add_dedupe_identity( $args );
		$fingerprint     = $this->make_fingerprint( $job, $args );

		$lock = $this->lock_service->acquire_dispatch( $fingerprint );

		if ( ! $lock['acquired'] ) {
			return (int) get_transient( Keys::transient( 'aid_' . $fingerprint ) );
		}

		$action_id = 0;

		try {
			// [internal]: если предыдущий dispatch того же fingerprint ещё жив в Action
			// Scheduler (pending/running — например run_guarded() провалился, но больше не
			// удаляет transient безусловно, см. run_guarded() ниже), переиспользуем его
			// action_id вместо того, чтобы ставить новый и молча перезаписывать transient.
			// Без этой проверки double-submit после видимого fail (пользователь жмёт "ещё раз")
			// терял бы привязку старого action_id ровно так же, как чинит этот пакет — просто
			// сдвинутый на один action_id вбок.
			$existing_action_id = (int) get_transient( Keys::transient( 'aid_' . $fingerprint ) );
			if ( $existing_action_id > 0 && $this->is_action_still_live( $existing_action_id ) ) {
				return $existing_action_id;
			}

			$this->ksort_recursive( $args );
			// [internal]: unique=false — AS unique-guard дедуплицирует по hook+group БЕЗ учёта
			// args (vendor/woocommerce/action-scheduler/.../ActionScheduler_DBStore.php:
			// build_where_clause_for_insert(), WHERE только status+hook+group_id), поэтому один
			// pending/running job этого hook ложно блокировал постановку ЛЮБОГО другого
			// юзера/папки того же hook. Единственный корректный дедуп-барьер —
			// JobLockService::acquire_dispatch($fingerprint) выше (строка 138), уже
			// дедуплицирующий по полному fingerprint (job+args+user+blog). Проверка выше (AS
			// status-check) — дополнительный, более узкий слой: не про AS-уровневый hook+group
			// dedup, а про сохранение fingerprint→action_id связи для одного и того же запроса.
			$action_id = (int) as_schedule_single_action(
				time() + max( 0, $delay ),
				$job,
				[ $args ],
				$this->as_group(),
				false
			);

			if ( $action_id > 0 ) {
				set_transient( Keys::transient( 'aid_' . $fingerprint ), $action_id, DAY_IN_SECONDS );
			} else {
				$action_id = (int) get_transient( Keys::transient( 'aid_' . $fingerprint ) );
			}
		} finally {
			$this->lock_service->release_dispatch( $fingerprint, $lock['option_fallback'] );
		}

		return $action_id;
	}

	/** @param array<string, mixed> $args */
	public function release_fingerprint(string $job, array $args): void {
		$fingerprint = $this->make_fingerprint( $job, $args );
		delete_transient( Keys::transient( 'aid_' . $fingerprint ) );
	}

	/**
	 * [internal]: отменяет уже запланированный AS-action, находящийся в очереди
	 * (status=pending), по тем же raw $args, которыми был вызван dispatch(). Нужен
	 * вызывающим сторонам с inline fast-path (job выполнен синхронно, но AS всё ещё
	 * содержит ту же задачу pending) — без этого AS-worker позже выполнит job повторно.
	 *
	 * Раскладка args идентична dispatch() в ДВУХ измерениях сразу, и оба обязательны:
	 * 1. ОБОГАЩЕНИЕ — blog_id/_dedupe_identity добавлены (enriched_for_fingerprint());
	 * 2. ОБЁРТКА — args передаются как `[ $args ]`, список аргументов колбэка хука, ровно так
	 *    их принимает as_schedule_single_action() и так же сериализует AS при сохранении.
	 *
	 * AS ищет action по точному совпадению сериализованных args (ActionScheduler_DBStore:
	 * `AND a.args = %s`, значение — wp_json_encode($action->get_args())). [internal]
	 * ([internal]): обогащение здесь было, а обёртка — нет, поэтому JSON не совпадал,
	 * pending-action не находился и оставался в очереди навсегда. Последствие было шире
	 * лишнего повторного прогона: as_schedule_single_action($unique = true) дедуплицирует по
	 * hook+group БЕЗ args, поэтому один такой невыключенный pending блокировал постановку
	 * задач того же хука для всех папок и всех пользователей сайта.
	 *
	 * @param array<string, mixed> $raw_args
	 */
	public function unschedule(string $job, array $raw_args): void {
		if ( ! function_exists( 'as_unschedule_action' ) ) {
			return;
		}

		as_unschedule_action( $job, [ $this->enriched_for_fingerprint( $raw_args ) ], $this->as_group() );
	}

	/**
	 * [internal] ([internal]): платформенная оркестрация guarded-execution — заменяет ручную
	 * 8-callable-проводку (acquire_execution_lock/has_job_result/resolve_and_store_job_result/
	 * release_fingerprint/release_execution_lock), которую PRO ZipJobRunner держал вручную.
	 * Консолидирует три исправленных дефекта (skeptic-разбор, [internal]):
	 *
	 * Д1 (stale dedupe-transient): fingerprint вычисляется ОДИН раз здесь и передаётся как
	 * значение — не пересчитывается в каждом guard-методе от текущего ambient-состояния.
	 * Д2 (мёртвый make_fingerprint-callable у потребителя): потребитель больше не считает
	 * fingerprint сам — платформа делает это внутри, потребитель получает только $work().
	 * Д3 (multisite cross-site AS-claim обходит guard'ы): switch_to_blog() вызывается ПЕРВЫМ,
	 * до вычисления fingerprint — устраняет расхождение имени лока/transient-ключа между
	 * раннером, стартовавшим на другом сайте сети (AS-таблицы общие), и владельцем job'а.
	 *
	 * [internal] ([internal]): switch_to_blog()/restore_current_blog() больше не дублируются
	 * здесь inline — весь метод вызывает единого владельца правила, run_in_blog_context()
	 * (см. её докблок), передавая своё тело как callback. $restoreNow вызывается ровно в тех
	 * же трёх точках потока, где раньше стоял прямой restore_current_blog() — порядок ниже
	 * не изменился, изменилась только форма реализации switch/restore.
	 *
	 * Порядок (не копия ZipJobRunner — тот содержал Д3):
	 * 1. switch_to_blog(raw_args['blog_id'] ?? current) — ПЕРВЫМ (внутри run_in_blog_context());
	 * 2. однократно: enrich + fingerprint + action_id → локальные значения;
	 * 3. acquire_execution по precomputed fingerprint — занят → RESULT_BUSY, лог warning;
	 * 4. has_job_result по precomputed action_id — есть → release lock → RESULT_ALREADY_DONE;
	 * 5. $work($enriched_args) в try БЕЗ catch — исключение пробрасывается наружу НЕТРОНУТЫМ
	 *    (AS обязан пометить action failed; вызывающая сторона сама решает, что делать —
	 *    ловить снаружи или дать упасть). $work обязан почистить СВОИ временные артефакты в
	 *    СОБСТВЕННОМ finally — PHP гарантирует, что оно отработает ДО платформенного finally.
	 *    Успешный $work возвращает array — payload результата (те же правила, что раньше были
	 *    у resolve_and_store_job_result: null-значения отфильтровываются, `_created_at`
	 *    добавляется здесь);
	 * 6. finally: release_fingerprint по precomputed fingerprint — ДО $restoreNow()
	 *    (transient site-scoped, restore должен идти после снятия своей же site-метки);
	 *    $restoreNow(); release_execution — ПОСЛЕДНИМ (MySQL-лок не site-scoped,
	 *    имя precomputed — конкурент не должен увидеть свободный лок раньше, чем temp/stage
	 *    артефакты $work и dedupe-транзиент уже подчищены).
	 *
	 * Только для fingerprint-диспатченных single actions (dispatch()) — НЕ для recurring
	 * jobs (dispatch_recurring() не создаёт aid_-transient, has_job_result всегда вернёт
	 * false, семантика неприменима).
	 *
	 * @param array<string, mixed> $raw_args Args, как их видел вызывающий код (сырые ИЛИ уже
	 *   AS-обогащённые — enrich идемпотентен, [internal]).
	 * @param callable(array<string, mixed>): array<string, mixed> $work Выполняет саму работу
	 *   job'а с уже обогащёнными args; возвращает result-payload для записи. Исключение —
	 *   rethrow, работа считается несделанной (result НЕ пишется, execution lock освобождается
	 *   в finally как обычно).
	 * @return self::RESULT_* Один из трёх исходов.
	 */
	public function run_guarded(string $job, array $raw_args, callable $work): string {
		$blog_id = (int) ( $raw_args['blog_id'] ?? get_current_blog_id() );

		/** @var self::RESULT_* $result */
		$result = $this->run_in_blog_context(
			$blog_id,
			function (callable $restore_now) use ($job, $raw_args, $work): string {
				$enriched_args = $this->enriched_for_fingerprint( $raw_args );
				$fingerprint   = $this->make_fingerprint( $job, $enriched_args );
				$action_id     = (int) get_transient( Keys::transient( 'aid_' . $fingerprint ) );

				if ( ! $this->lock_service->acquire_execution( $fingerprint )['acquired'] ) {
					Logger::warning( 'job_guarded_execution_lock_busy', [ 'job' => $job ] );
					$restore_now();

					return self::RESULT_BUSY;
				}

				if ( $action_id > 0 && false !== get_option( Keys::job_result( $action_id ) ) ) {
					Logger::warning( 'job_guarded_already_completed_by_concurrent_runner', [ 'job' => $job ] );
					$this->lock_service->release_execution( $fingerprint );
					$restore_now();

					return self::RESULT_ALREADY_DONE;
				}

				try {
					$result_payload = $work( $enriched_args );

					if ( $action_id > 0 ) {
						$this->store_job_result( $action_id, $result_payload );
					}

					// [internal] ([internal]): удаляем transient только на success-пути,
					// сразу после того, как результат уже записан. Раньше это жило в finally
					// ниже БЕЗУСЛОВНО (в т.ч. при throw из $work()) — AS-ретрай того же failed
					// action получал $action_id=0 из уже стёртого transient и НИКОГДА не мог
					// записать свой результат, даже при успешном повторном прогоне. dispatch()
					// теперь также проверяет AS-статус выжившего transient перед постановкой
					// нового action (см. dispatch(), is_action_still_live()) — то же решение
					// закрывает и double-submit-сценарий, не только single-retry.
					delete_transient( Keys::transient( 'aid_' . $fingerprint ) );

					return self::RESULT_EXECUTED;
				} finally {
					$restore_now();
					$this->lock_service->release_execution( $fingerprint );
				}
			}
		);

		return $result;
	}

	/**
	 * Единый формат записи job_result: null-значения отфильтрованы, `_created_at` —
	 * TTL-метка для CleanupJobRunner::purge_stale_job_results() ([internal]). Извлечено
	 * из run_guarded() ([internal], [internal]) — тот же формат нужен потребителям
	 * вне run_guarded()'s execution-guard-механики (ImportManager::handle_job_import(),
	 * у которого нет inline-race, для которой run_guarded() спроектирован).
	 *
	 * @param array<string, mixed> $payload
	 */
	private function store_job_result(int $action_id, array $payload): void {
		$filtered = array_filter( $payload, static fn ($value): bool => $value !== null );
		$filtered['_created_at'] = time();
		update_option( Keys::job_result( $action_id ), $filtered, false );
	}

	/**
	 * Общее обогащение raw args для fingerprint-вычислений (unschedule/execution-lock).
	 * Извлечено из unschedule() — та же последовательность blog_id → add_dedupe_identity →
	 * ksort_recursive, что dispatch() применяет перед as_schedule_single_action().
	 *
	 * @param array<string, mixed> $raw_args
	 * @return array<string, mixed>
	 */
	private function enriched_for_fingerprint(array $raw_args): array {
		$args = $raw_args;
		// [internal]: ??= вместо перезаписи — AS-payload уже несёт blog_id блога-инициатора;
		// воркер другого сайта сети (AS-таблицы общие) не должен подменять его своим
		// current_blog, иначе fingerprint/лок/transient уезжают в чужой site-scope (Д3).
		$args['blog_id'] ??= get_current_blog_id();
		$args              = $this->add_dedupe_identity( $args );
		$this->ksort_recursive( $args );

		return $args;
	}

	/** @param array<string, mixed> $args */
	public function get_action_id(string $job, array $args): int {
		$fingerprint = $this->make_fingerprint( $job, $args );

		return (int) get_transient( Keys::transient( 'aid_' . $fingerprint ) );
	}

	/**
	 * Публичный вход для потребителей вне run_guarded()'s execution-guard-механики
	 * ([internal], [internal]) — тот же формат записи (null-фильтр + `_created_at`),
	 * без single-flight lock/already-done/blog-context, которые Import не задействует
	 * (нет inline-race, для которой run_guarded() спроектирован).
	 *
	 * @param array<string, mixed> $payload
	 */
	public function store_result_for_action(int $action_id, array $payload): void {
		$this->store_job_result( $action_id, $payload );
	}

	/** @param array<string, mixed> $args */
	public function dispatch_recurring(string $job, int $interval_seconds, array $args = []): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			Logger::warning( 'job_dispatch_recurring_skipped_no_action_scheduler', [ 'job' => $job ] );
			return;
		}

		$args['blog_id'] = get_current_blog_id();

		if ( ! as_has_scheduled_action( $job, [ $args ], $this->as_group() ) ) {
			as_schedule_recurring_action(
				time() + $interval_seconds,
				$interval_seconds,
				$job,
				[ $args ],
				$this->as_group()
			);
		}
	}

	public static function group_for_blog(int $blog_id): string {
		return 'plathix_' . $blog_id;
	}

	/** @return array{status: string, result: mixed, attempts: int} */
	public function get_status(int $action_id): array {
		return $this->status_repository->get( $action_id );
	}

	/** @param array<string, mixed> $args */
	public function handle_import(array $args = []): void {
		$this->import_runner->run(
			$args,
			[ $this, 'run_in_blog_context' ],
			[ $this, 'release_fingerprint' ]
		);
	}

	/** @param array<string, mixed> $args */
	public function handle_reorder(array $args = []): void {
		$this->reorder_runner->run(
			$args,
			[ $this, 'run_in_blog_context' ],
			[ $this, 'release_fingerprint' ]
		);
	}

	/** @param array<string, mixed> $args */
	public function handle_orphan_cleanup(array $args = []): void {
		$this->orphan_runner->run( $args, [ $this, 'run_in_blog_context' ] );
	}

	/** @param array<string, mixed> $args */
	public function handle_folder_count_reconcile(array $args = []): void {
		$this->folder_count_reconcile_runner->run( $args, [ $this, 'run_in_blog_context' ] );
	}

	/** @param array<string, mixed> $args */
	public function handle_cleanup(array $args = []): void {
		$blog_id = (int) ( $args['blog_id'] ?? get_current_blog_id() );

		$this->run_in_blog_context(
			$blog_id,
			function () {
				$this->cleanup_runner->run( [ $this, 'get_temp_dir' ] );
			}
		);
	}

	/** @param array<string, mixed> $args */
	public function handle_import_checkpoint_cleanup(array $args = []): void {
		$this->import_checkpoint_cleanup_runner->run( $args, [ $this, 'run_in_blog_context' ] );
	}


	private function as_group(): string {
		return 'plathix_' . get_current_blog_id();
	}

	/**
	 * [internal] ([internal]): проверяет, не завершён ли ещё $action_id в Action
	 * Scheduler (pending/running). Тот же access-паттерн, что уже используют
	 * `RateLimiter::can_dispatch_heavy_job()`, `HealthCheckRegistry::running_and_stuck()` и
	 * `JobStatusRepository::get()` — не новый механизм, established способ читать AS-статус.
	 * Fail-open (true), если классы Action Scheduler недоступны — dispatch() продолжает
	 * ставить новый action, как и раньше, без этой проверки.
	 */
	private function is_action_still_live(int $action_id): bool {
		if ( ! class_exists( '\ActionScheduler' ) || ! class_exists( '\ActionScheduler_Store' ) ) {
			return false;
		}

		try {
			$status = \ActionScheduler::store()->get_status( $action_id );
		} catch ( \Throwable $e ) {
			return false;
		}

		return in_array( $status, [ \ActionScheduler_Store::STATUS_PENDING, \ActionScheduler_Store::STATUS_RUNNING ], true );
	}

	/**
	 * [internal]: если `$args` несёт `user_id`, добавляет `_dedupe_identity` — вычисляемый
	 * identity-key, читаемый `RateLimiter::can_dispatch_heavy_job()` для AS dedupe-lookup.
	 * `user_id` остаётся нетронутым (raw int) — его читают `wp_set_current_user()`
	 * (`ImportJobRunner`) и `can_poll_job()`/`JobStatusRepository` (ownership-сравнение через
	 * `IdentityKeyResolver::resolve()` НА ЭТАПЕ сравнения), обе точки сломались бы, если бы
	 * `user_id` сам стал identity-key строкой.
	 *
	 * [internal] ([internal]): также добавляет `created_by_token_id` — raw ID service-токена,
	 * АКТИВНОГО НА ЭТОМ ЗАПРОСЕ (не identity-key строка), если фильтр
	 * `plathix/infrastructure/current_service_token_id` (подписчик — PRO `ApiKeyAuthenticator`)
	 * вернул non-empty string. Нужен read-стороне (`JobStatusRepository::get()`), чтобы
	 * различать "каким токеном создан job" от "какой токен активен прямо сейчас на запросе
	 * читателя" — `IdentityKeyResolver::resolve()` сам по себе не видит payload и не может
	 * дать этот ответ. Free не знает о PRO/токенах напрямую — весь мост через WP filter.
	 *
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
	 */
	private function add_dedupe_identity(array $args): array {
		// [internal] ([internal]): идемпотентность обязательна — обогащение зовётся и на
		// сырых args (dispatch), и на уже-обогащённых (AS-payload в unschedule/execution-lock/
		// has_job_result/resolve-путях). Identity-группа (_dedupe_identity + created_by_token_id)
		// фиксируется АТОМАРНО в момент первичного обогащения — маркер первичности: отсутствие
		// _dedupe_identity. Ambient-состояние повторного вызова (другой активный токен воркера,
		// отсутствие токена в cron, поздний токен при пере-обогащении токен-less job'а) НЕ должно
		// ни перетирать, ни ДОБАВЛЯТЬ identity-поля — иначе fingerprint расходится с
		// dispatch-fingerprint и guard-контур слепнет (skeptic-разбор Д1/Д3; отдельный isset-guard
		// на каждое поле недостаточен: поздняя дозапись токена меняет fingerprint так же, как
		// перезапись).
		if ( isset( $args['user_id'] ) && ! isset( $args['_dedupe_identity'] ) ) {
			$args['_dedupe_identity'] = IdentityKeyResolver::resolve( (int) $args['user_id'] );

			$token_id = apply_filters( 'plathix/infrastructure/current_service_token_id', null );
			if ( is_string( $token_id ) && $token_id !== '' ) {
				$args['created_by_token_id'] = $token_id;
			}
		}

		return $args;
	}

	/** @param array<string, mixed> $args */
	public function make_fingerprint(string $job, array $args): string {
		$blog_id = get_current_blog_id();

		array_walk_recursive(
			$args,
			static function (mixed &$value): void {
				$value = (string) $value;
			}
		);

		$this->ksort_recursive( $args );

		return 'plx_jfp_' . hash( 'sha256', $blog_id . '|' . $job . '|' . wp_json_encode( $args ) );
	}

	/**
	 * Единый владелец правила "переключиться в блог-владелец job'а, восстановить после"
	 * ([internal], [internal]). И recurring-путь (5 handle_*
	 * методов, callback без параметров — restore происходит один раз, в finally ниже),
	 * и single-dispatch путь (run_guarded()) вызывают этот метод.
	 *
	 * $callback принимает $restoreNow — вызвать явно в нужный момент СВОЕГО flow, если
	 * restore должен произойти в конкретной точке относительно других side-effects
	 * callback'а (run_guarded() пользуется этим для сохранения Д3-порядка: delete_transient
	 * → restore → release_execution — без этого параметра restore мог бы произойти только
	 * после ВСЕГО callback, что переставило бы этот порядок). Restore идемпотентен — если
	 * $restoreNow уже вызван вручную, finally ниже не восстанавливает повторно.
	 *
	 * @template T
	 * @param callable(callable(): void): T $callback
	 * @return T
	 */
	public function run_in_blog_context(int $blog_id, callable $callback): mixed {
		$multisite = is_multisite();
		$restored  = false;

		if ( $multisite ) {
			switch_to_blog( $blog_id );
		}

		$restore_now = static function () use (&$restored, $multisite): void {
			if ( $multisite && ! $restored ) {
				restore_current_blog();
				$restored = true;
			}
		};

		try {
			return $callback( $restore_now );
		} finally {
			$restore_now();
		}
	}

	/** @param array<mixed> $arr */
	private function ksort_recursive(array &$arr): void {
		ksort( $arr );

		foreach ( $arr as &$value ) {
			if ( is_array( $value ) ) {
				$this->ksort_recursive( $value );
			}
		}
	}
}
