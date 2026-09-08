<?php

declare(strict_types=1);

namespace Plathix\Infrastructure;

final class CronStatusResolver
{
	/**
	 * @return array{disabled: bool, idle: bool, stalled: bool}
	 */
	public function resolve(): array {
		$disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;

		if ( ! $disabled ) {
			return [ 'disabled' => false, 'idle' => false, 'stalled' => false ];
		}

		if ( ! function_exists( 'as_get_scheduled_actions' ) || ! class_exists( '\\ActionScheduler_Store' ) ) {

			return [ 'disabled' => true, 'idle' => false, 'stalled' => true ];
		}

		$recent_complete = as_get_scheduled_actions(
			[
				'status'   => \ActionScheduler_Store::STATUS_COMPLETE,
				'date'     => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ),
				'per_page' => 1,
			]
		);
		$pending = ! empty( $recent_complete ) ? [] : as_get_scheduled_actions(
			[
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 1,
			]
		);

		[ 'idle' => $idle, 'stalled' => $stalled ] = self::decide( ! empty( $recent_complete ), ! empty( $pending ) );

		return [ 'disabled' => true, 'idle' => $idle, 'stalled' => $stalled ];
	}

	/**
	 * @return array{idle: bool, stalled: bool}
	 */

	private static function decide(bool $has_recent_complete, bool $has_pending): array {
		if ( $has_recent_complete ) {
			return [ 'idle' => false, 'stalled' => false ];
		}

		if ( ! $has_pending ) {

			return [ 'idle' => true, 'stalled' => false ];
		}

		return [ 'idle' => false, 'stalled' => true ];
	}
}
