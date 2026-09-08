<?php

declare(strict_types=1);

namespace Plathix\Infrastructure;

final class RateLimiter
{
	private const PREFIX = 'plathix_rate_';

	private const ACTION_LIMITS = [
		'create_folder' => [ 'max' => 30, 'window' => 60, 'strategy' => self::WINDOW_FIXED ],
		'deleteFolder' => [ 'max' => 60, 'window' => 60, 'strategy' => self::WINDOW_FIXED ],
		'update_folder' => [ 'max' => 60, 'window' => 60, 'strategy' => self::WINDOW_FIXED ],
	];

	public function __construct(
		private readonly Cache $cache
	) {
	}

	public function attemptAction(string $action, int $user_id): bool {
		if ( ! isset( self::ACTION_LIMITS[ $action ] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal developer error (typo in action name), message goes to log/stack trace, never to browser output; esc_html not available in this infrastructure namespace
			throw new \InvalidArgumentException( "Unknown rate-limit action: {$action}" );
		}

		$limit = self::ACTION_LIMITS[ $action ];

		return $this->attempt( $action, $user_id, $limit['max'], $limit['window'], $limit['strategy'] );
	}

	public const WINDOW_SLIDING = 'sliding';

	public const WINDOW_FIXED = 'fixed';

	/**
	 * @param 'sliding'|'fixed' $window_strategy
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
			return $this->attemptFixed( $key, $max, $window );
		}

		$current = (int) $this->cache->get( $key );

		if ( $current >= $max ) {
			return false;
		}

		$this->cache->set( $key, $current + 1, $window );

		return true;
	}

	private function attemptFixed(string $key, int $max, int $window): bool {
		$now   = time();
		$stored = $this->cache->get( $key );

		if ( ! is_array( $stored ) || ( (int) ( $stored['r'] ?? 0 ) ) <= $now ) {
			$this->cache->set( $key, [ 'c' => 1, 'r' => $now + $window ], $window );
			return true;
		}

		$count    = (int) ( $stored['c'] ?? 0 );
		$reset_at = (int) $stored['r'];

		if ( $count >= $max ) {
			return false;
		}

		$this->cache->set( $key, [ 'c' => $count + 1, 'r' => $reset_at ], max( 1, $reset_at - $now ) );

		return true;
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
	 */

	private function buildDedupeArgs(array $args, int $user_id): array {
		$user_args = array_merge(
			$args,
			[
				'user_id' => $user_id,
				'blog_id' => get_current_blog_id(),
			]
		);
		$user_args = JobDispatcher::addDedupeIdentity( $user_args );
		JobDispatcher::ksortRecursive( $user_args );

		return $user_args;
	}

	/** @param array<string, mixed> $args */
	public function canDispatchHeavyJob(string $job_hook, array $args, int $user_id): ?string {
		if ( ! function_exists( 'as_get_scheduled_actions' ) || ! class_exists( '\ActionScheduler_Store' ) ) {
			return null;
		}

		$group     = JobDispatcher::groupForBlog( get_current_blog_id() );
		$user_args = $this->buildDedupeArgs( $args, $user_id );
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
