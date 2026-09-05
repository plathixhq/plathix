<?php

declare(strict_types=1);

namespace Plathix\Infrastructure;

/**
 * Distributed lock for job deduplication, branch-level reorder guard, and single-flight
 * job execution.
 *
 * Three independent lock contracts:
 *   1. dispatch lock  — prevents double-scheduling the same job fingerprint.
 *   2. order lock     — prevents a stale reorder job from overwriting a concurrent DnD save.
 *   3. execution lock — prevents two runners (e.g. an inline fast-path and the Action
 *      Scheduler worker for the same job) from doing the same heavy work twice.
 *
 * Все три используют MySQL advisory-локи (GET_LOCK). Если GET_LOCK недоступен (вернул NULL:
 * авария сессии — kill коннекта/OOM — либо прокси без passthrough вроде ProxySQL/PlanetScale),
 * лок НЕ берётся и возвращается честный отказ (order → mode='none', dispatch/execution →
 * acquired=false). Вызывающий отдаёт reorder_locked/409 «попробуйте ещё раз» (sync) или тихо
 * отступает (async ReorderJobRunner / второй execution-раннер), что безопаснее ложного захвата.
 *
 * dispatch/order используют GET_LOCK(%s, 3) — короткая критическая секция (регистрация
 * job/запись reorder), уместно недолго подождать конкурента. execution намеренно использует
 * GET_LOCK(%s, 0) (немедленный неблокирующий отказ) — критическая секция здесь может длиться
 * минуты (полная генерация тяжёлого артефакта), а вызывающий может быть синхронным HTTP-
 * запросом (PHP_max_execution_time риск) — ждать чужого раннера внутри REST-обработчика
 * недопустимо, честный немедленный отказ безопаснее ([internal], [internal] расследование,
 * WP Architecture + WP Senior Dev skeptics).
 *
 * Прежний option-fallback (wp_options + readback) УДАЛЁН ([internal],
 * [internal]): его readback читал горячий процессный alloptions-кэш, а не БД, → был структурно
 * слеп к чужой параллельной краже (два процесса ложно держали один лок). Ветка активировалась
 * только при GET_LOCK=null, а такой инфры в проекте нет; воскрешать её нельзя — при реальном
 * managed-MySQL нужен корректный примитив (атомарный INSERT ON DUPLICATE / Redis SETNX), а не
 * readback-через-кэш.
 *
 * Модель отказа execution-lock (implicit-допущение, задокументировано явно — WP Senior Dev
 * skeptic): GET_LOCK — session-scoped, освобождается автоматически при закрытии MySQL-сессии.
 * В этом проекте нет persistent-соединений (mysqli_pconnect/connection pooling) — фатальный
 * крэш держателя лока внутри критической секции закрывает MySQL-сессию вместе с PHP-процессом,
 * лок освобождается сам. Если в будущем в стек добавится connection pooling/proxy без
 * passthrough (ProxySQL и т.п.) — это допущение перестанет быть верным, лок сможет держаться
 * дольше владеющего его PHP-процесса; recovery в этом случае — `SELECT IS_USED_LOCK(name)` →
 * thread id → `KILL <id>` с правами на MySQL-инстанс.
 */
final class JobLockService
{
	/**
	 * Acquire a dispatch-level lock for the given fingerprint.
	 *
	 * @return array{acquired: bool, option_fallback: bool}
	 */
	public function acquire_dispatch(string $fingerprint): array {
		$lock_name = 'plx_d_' . md5( $fingerprint );

		$lock_result = DbAdvisoryLock::acquire( $lock_name, 3 );

		// GET_LOCK недоступен (NULL) → честный отказ, НЕ ложный захват. Поле option_fallback
		// сохранено в контракте (всегда false) для release_dispatch(), fallback удалён.
		$acquired = $lock_result;

		return [ 'acquired' => $acquired, 'option_fallback' => false ];
	}

	/**
	 * Release a dispatch-level lock acquired via acquire_dispatch().
	 *
	 * @param bool $option_fallback Сохранён в сигнатуре для стабильности контракта call-site
	 *                              (JobDispatcher), всегда false после удаления option-fallback.
	 */
	public function release_dispatch(string $fingerprint, bool $option_fallback): void {
		$lock_name = 'plx_d_' . md5( $fingerprint );
		DbAdvisoryLock::release( $lock_name );
	}

	/**
	 * Acquire a branch-level order lock for the reorder job.
	 *
	 * Returns mode: 'mysql' (advisory lock held) | 'none' (lock not acquired — занят или
	 * GET_LOCK недоступен). Caller must call release_order() with the same lock_name and result.
	 *
	 * @return array{mode: string, opt_key: string|null}
	 */
	public function acquire_order(string $lock_name): array {
		$lock = DbAdvisoryLock::acquire( $lock_name, 3 );

		// GET_LOCK вернул '1' — лок взят. Иначе ('0' = занят/таймаут, NULL = недоступен) —
		// честный отказ 'none'; вызывающий отдаёт reorder_locked/409. Option-fallback удалён.
		if ( $lock ) {
			return [ 'mode' => 'mysql', 'opt_key' => null ];
		}

		return [ 'mode' => 'none', 'opt_key' => null ];
	}

	/**
	 * Release a branch-level order lock acquired via acquire_order().
	 *
	 * @param array{mode: string, opt_key: string|null} $lock_result
	 */
	public function release_order(string $lock_name, array $lock_result): void {
		if ( $lock_result['mode'] === 'mysql' ) {
			DbAdvisoryLock::release( $lock_name );
		}
	}

	/**
	 * Per-branch order-lock name (blog + taxonomy + parent), с md5-fallback при длине >64.
	 * Общий владелец для sync-reorder (FolderTreeService::normalize_order()) и async-джобы
	 * (ReorderJobRunner), чтобы обе стороны гарантированно делили один лок. set_order() —
	 * НЕ потребитель этого лока ([internal], [internal]): он берёт structure-лок
	 * (FolderTreeService::acquire_structure_lock()), потому что per-branch order-лок здесь
	 * строился по parent_id, прочитанному до захвата, и устаревал при конкурентном move.
	 */
	public function order_lock_name(string $taxonomy, int $parent_id): string {
		$raw = 'plathix_ord_' . get_current_blog_id() . '_' . $taxonomy . '_' . $parent_id;

		return strlen( $raw ) <= 64 ? $raw : 'plx_o_' . md5( get_current_blog_id() . '|' . $taxonomy . '|' . $parent_id );
	}

	/**
	 * Acquire a single-flight execution lock for the given job fingerprint. Unlike
	 * acquire_dispatch()/acquire_order(), uses GET_LOCK(%s, 0) — immediate non-blocking
	 * attempt, no wait — because the critical section this guards (a heavy job's actual
	 * work, e.g. full ZIP generation) can run for minutes, and the caller may be a
	 * synchronous HTTP request that must not block on a concurrent runner.
	 *
	 * @return array{acquired: bool}
	 */
	public function acquire_execution(string $fingerprint): array {
		$lock_name = 'plx_x_' . md5( $fingerprint );

		$lock_result = DbAdvisoryLock::acquire( $lock_name, 0 );

		return [ 'acquired' => $lock_result ];
	}

	/**
	 * Release an execution lock acquired via acquire_execution(). Safe to call even if
	 * the lock was never acquired (RELEASE_LOCK on a name this session doesn't hold is a
	 * no-op per MySQL semantics) — callers may call this unconditionally in a finally
	 * block without tracking whether acquire succeeded.
	 */
	public function release_execution(string $fingerprint): void {
		$lock_name = 'plx_x_' . md5( $fingerprint );
		DbAdvisoryLock::release( $lock_name );
	}
}
