<?php

declare(strict_types=1);

namespace Plathix\Infrastructure;

final class JobLockService
{
	/**
	 * Acquire a dispatch-level lock for the given fingerprint.
	 *
	 * @return array{acquired: bool, option_fallback: bool}
	 */
	public function acquireDispatch(string $fingerprint): array {
		$lock_name = 'plx_d_' . md5( $fingerprint );

		$lock_result = DbAdvisoryLock::acquire( $lock_name, 3 );

		$acquired = $lock_result;

		return [ 'acquired' => $acquired, 'option_fallback' => false ];
	}

	/**
	 * @param bool $option_fallback
	 */

	public function releaseDispatch(string $fingerprint, bool $option_fallback): void {
		$lock_name = 'plx_d_' . md5( $fingerprint );
		DbAdvisoryLock::release( $lock_name );
	}

	/**
	 * @return array{mode: string, opt_key: string|null}
	 */

	public function acquireOrder(string $lock_name): array {
		$lock = DbAdvisoryLock::acquire( $lock_name, 3 );

		if ( $lock ) {
			return [ 'mode' => 'mysql', 'opt_key' => null ];
		}

		return [ 'mode' => 'none', 'opt_key' => null ];
	}

	/**
	 * Release a branch-level order lock acquired via acquireOrder().
	 *
	 * @param array{mode: string, opt_key: string|null} $lock_result
	 */
	public function releaseOrder(string $lock_name, array $lock_result): void {
		if ( $lock_result['mode'] === 'mysql' ) {
			DbAdvisoryLock::release( $lock_name );
		}
	}

	public function orderLockName(string $taxonomy, int $parent_id): string {
		$raw = 'plathix_ord_' . get_current_blog_id() . '_' . $taxonomy . '_' . $parent_id;

		return strlen( $raw ) <= 64 ? $raw : 'plx_o_' . md5( get_current_blog_id() . '|' . $taxonomy . '|' . $parent_id );
	}

	/**
	 * Acquire a single-flight execution lock for the given job fingerprint. Unlike
	 * acquireDispatch()/acquireOrder(), uses GET_LOCK(%s, 0) — immediate non-blocking
	 * attempt, no wait — because the critical section this guards (a heavy job's actual
	 * work, e.g. full ZIP generation) can run for minutes, and the caller may be a
	 * synchronous HTTP request that must not block on a concurrent runner.
	 *
	 * @return array{acquired: bool}
	 */
	public function acquireExecution(string $fingerprint): array {
		$lock_name = 'plx_x_' . md5( $fingerprint );

		$lock_result = DbAdvisoryLock::acquire( $lock_name, 0 );

		return [ 'acquired' => $lock_result ];
	}

	/**
	 * Release an execution lock acquired via acquireExecution(). Safe to call even if
	 * the lock was never acquired (RELEASE_LOCK on a name this session doesn't hold is a
	 * no-op per MySQL semantics) — callers may call this unconditionally in a finally
	 * block without tracking whether acquire succeeded.
	 */
	public function releaseExecution(string $fingerprint): void {
		$lock_name = 'plx_x_' . md5( $fingerprint );
		DbAdvisoryLock::release( $lock_name );
	}
}
