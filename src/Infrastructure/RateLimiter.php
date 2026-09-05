<?php

declare(strict_types=1);

namespace Plathix\Infrastructure;

final class RateLimiter
{
	private const PREFIX = 'plathix_rate_';

	/**
	 * Single-source порогов rate-limit для действий, вынесенных из literal-вызовов в
	 * контроллерах ([internal]). Формат: action => [max, window_seconds, strategy].
	 *
	 * Пока здесь только `create_folder` ([internal]): его порог дублировался literal-числом
	 * в REST (FolderMutationController) и AJAX (AjaxRouter) с общим ключом кэша — правка в
	 * одном месте вела к рассинхрону. Теперь оба транспорта читают отсюда.
	 *
	 * - create_folder 30: с fixed-окном 30 создаваний/календарную минуту руками недостижимо.
	 * - delete_folder 60: живой delete рекурсивен (trash всего поддерева + меты/файлы) — дорогой
	 *   per-request, throttle обязателен ([internal], дыра найдена при #6). 60 — с запасом
	 *   над реальной ручной чисткой (чистят быстрее, чем создают).
	 * - update_folder 60: XOR rename/move/color; move перестраивает дерево (не O(1)) — лимит
	 *   оправдан по стоимости, rename идёт заодно.
	 *
	 * Остальные call-sites (move_items/batch/unassign/replace, AJAX-двойники) сознательно НЕ
	 * мигрированы: проверено — багом #6-класса не задеты (bulk/параллельные проскакивают
	 * неатомарный счётчик; AJAX rename/delete — легаси-путь, живой UI туда не ходит). Полная
	 * консолидация карты порогов — отдельный tech-debt ([internal]).
	 */
	private const ACTION_LIMITS = [
		'create_folder' => [ 'max' => 30, 'window' => 60, 'strategy' => self::WINDOW_FIXED ],
		'delete_folder' => [ 'max' => 60, 'window' => 60, 'strategy' => self::WINDOW_FIXED ],
		'update_folder' => [ 'max' => 60, 'window' => 60, 'strategy' => self::WINDOW_FIXED ],
	];

	public function __construct(
		private readonly Cache $cache
	) {
	}


	/**
	 * Применить throttle для действия из {@see self::ACTION_LIMITS} (single-source порога).
	 * Контроллеры зовут это вместо literal-параметров, чтобы порог/стратегия жили в одном
	 * месте. Неизвестное действие — исключение (защита от опечатки в имени).
	 */
	public function attempt_action(string $action, int $user_id): bool {
		if ( ! isset( self::ACTION_LIMITS[ $action ] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal developer error (typo in action name), message goes to log/stack trace, never to browser output; esc_html not available in this infrastructure namespace
			throw new \InvalidArgumentException( "Unknown rate-limit action: {$action}" );
		}

		$limit = self::ACTION_LIMITS[ $action ];

		return $this->attempt( $action, $user_id, $limit['max'], $limit['window'], $limit['strategy'] );
	}

	/** Скользящее окно (дефолт, legacy): TTL продлевается на каждом успешном инкременте. */
	public const WINDOW_SLIDING = 'sliding';

	/**
	 * Фиксированное окно: счётчик сбрасывается по границе окна, TTL НЕ продлевается на
	 * инкрементах. Нужно там, где непрерывная легитимная работа не должна «залипать» в
	 * скользящем окне ([internal], [internal]): при sliding «30/мин»
	 * на деле означало «30 за всю сессию без пауз >window», из-за чего юзер, раскладывая
	 * медиатеку в обычном темпе, ловил ложную 429. При fixed «30» — это 30 за КАЛЕНДАРНОЕ
	 * окно, руками недостижимо, а abuse-burst по-прежнему упирается.
	 */
	public const WINDOW_FIXED = 'fixed';

	/**
	 * Declared best-effort throttle ([internal], скептики WP-Architecture + WP-Senior-Dev).
	 *
	 * read-modify-write (get → check → set+1) НЕ атомизируется НАМЕРЕННО. Атомарный
	 * options-инкремент (как {@see Cache::bump_version} через INSERT ... ON DUPLICATE KEY UPDATE)
	 * убил бы TTL-окно: у опций нет протухания, счётчик стал бы вечным несбрасываемым — регресс
	 * без атомарности. Это defense-in-depth throttle ЗА capability (не auth-барьер): lost update
	 * при параллельном burst даёт лишь мягкое превышение порога, НЕ обход прав. Оставляем
	 * transient/object-cache; не «унифицировать» с атомарным примитивом при будущем рефакторе.
	 *
	 * @param 'sliding'|'fixed' $window_strategy Стратегия окна. Дефолт `sliding` сохраняет
	 *        историческое поведение — все существующие вызовы без параметра не меняются.
	 *        `fixed` включается per-call ([internal]), затрагивает только вызывающего.
	 */
	public function attempt(
		string $action,
		int $user_id,
		int $max = 30,
		int $window = 60,
		string $window_strategy = self::WINDOW_SLIDING
	): bool {
		$blog_id = is_multisite() ? get_current_blog_id() : 0;
		$key     = self::PREFIX . "{$blog_id}_{$action}_" . IdentityKeyResolver::resolve( $user_id );

		if ( $window_strategy === self::WINDOW_FIXED ) {
			return $this->attempt_fixed( $key, $max, $window );
		}

		$current = (int) $this->cache->get( $key );

		if ( $current >= $max ) {
			return false;
		}

		$this->cache->set( $key, $current + 1, $window );

		return true;
	}

	/**
	 * Фиксированное окно: значение хранит счётчик и абсолютную границу сброса
	 * (`['c' => int, 'r' => timestamp]`), TTL держится по остатку до границы и НЕ
	 * продлевается на инкрементах. Формат значения отличается от sliding (там голый int),
	 * поэтому ветки не пересекаются даже при смене стратегии на живом ключе.
	 */
	private function attempt_fixed(string $key, int $max, int $window): bool {
		$now   = time();
		$stored = $this->cache->get( $key );

		// Нет окна или прежнее истекло → начать новое окно с count=1.
		if ( ! is_array( $stored ) || ( (int) ( $stored['r'] ?? 0 ) ) <= $now ) {
			$this->cache->set( $key, [ 'c' => 1, 'r' => $now + $window ], $window );
			return true;
		}

		$count    = (int) ( $stored['c'] ?? 0 );
		$reset_at = (int) $stored['r'];

		if ( $count >= $max ) {
			return false;
		}

		// Инкремент в пределах текущего окна: граница `r` не двигается, TTL = остаток окна
		// (не пересоздаём окно, иначе получилось бы sliding).
		$this->cache->set( $key, [ 'c' => $count + 1, 'r' => $reset_at ], max( 1, $reset_at - $now ) );

		return true;
	}

	/**
	 * [internal]: lookup-args для AS dedupe-запроса. Должны побайтово совпадать (после ksort)
	 * с реально сохранённым args джобы — {@see JobDispatcher::add_dedupe_identity()} строит
	 * `_dedupe_identity` тем же алгоритмом (`IdentityKeyResolver::resolve()`) при dispatch;
	 * `user_id` остаётся raw в обоих местах (не может стать identity-key строкой — сравни
	 * с product invariants [internal]).
	 *
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
	 */
	private function build_dedupe_args(array $args, int $user_id): array {
		$user_args = array_merge(
			$args,
			[
				'user_id'          => $user_id,
				'_dedupe_identity' => IdentityKeyResolver::resolve( $user_id ),
				'blog_id'          => get_current_blog_id(),
			]
		);
		ksort( $user_args );

		return $user_args;
	}

	/** @param array<string, mixed> $args */
	public function can_dispatch_heavy_job(string $job_hook, array $args, int $user_id): ?string {
		if ( ! function_exists( 'as_get_scheduled_actions' ) || ! class_exists( '\ActionScheduler_Store' ) ) {
			return null;
		}

		$group     = 'plathix_' . get_current_blog_id();
		$user_args = $this->build_dedupe_args( $args, $user_id );
		$existing  = as_get_scheduled_actions(
			[
				'hook'     => $job_hook,
				'status'   => [ \ActionScheduler_Store::STATUS_PENDING, \ActionScheduler_Store::STATUS_RUNNING ],
				'group'    => $group,
				'args'     => [ $user_args ],
				'per_page' => 1,
			],
			'ids'
		);

		if ( ! empty( $existing ) ) {
			// If the pending job has been waiting for over 10 minutes, it is
			// likely stuck (AS runner not firing). Allow a fresh dispatch instead
			// of blocking the user indefinitely.
			$stale = false;
			if ( class_exists( '\ActionScheduler' ) ) {
				try {
					$action = \ActionScheduler::store()->fetch_action( (int) reset( $existing ) );
					if ( ! ( $action instanceof \ActionScheduler_NullAction ) ) {
						$date = $action->get_schedule()->get_date();
						if ( $date instanceof \DateTime && ( time() - $date->getTimestamp() ) > 10 * MINUTE_IN_SECONDS ) {
							$stale = true;
						}
					}
				} catch ( \Throwable ) {
					// Conservative fallback: don't unblock on error.
				}
			}

			if ( ! $stale ) {
				return 'per_user';
			}
		}

		// Server-cap на параллельные тяжёлые джобы per-hook. Дефолт несёт только
		// платформенные джобы Free (import); контекстные фичи (zip уехал в PRO,
		// [internal]) задают свой cap через фильтр `plathix/jobs/heavy_caps`.
		// Неизвестный job_hook → дефолт 3.
		$caps    = (array) apply_filters(
			'plathix/jobs/heavy_caps',
			[ JobDispatcher::JOB_IMPORT => 2 ]
		);
		$cap     = (int) ( $caps[ $job_hook ] ?? 3 );
		$running = as_get_scheduled_actions(
			[
				'hook'     => $job_hook,
				'status'   => \ActionScheduler_Store::STATUS_RUNNING,
				'group'    => $group,
				'per_page' => $cap + 1,
			],
			'ids'
		);

		return count( $running ) < $cap ? null : 'server_cap';
	}
}
