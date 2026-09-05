<?php

declare(strict_types=1);

namespace Plathix\Infrastructure;

/**
 * Единственный источник факта состояния WP-Cron / Action Scheduler ([internal], M11).
 *
 * Раньше идентичная по сути логика ("DISABLE_WP_CRON включён и нет завершённых задач за
 * последний час → считать зависшим") была независимо продублирована в
 * `Plathix\Infrastructure\Health\HealthCheckRegistry::check_cron()` и
 * `Plathix\Modules\Settings\CronHealthService::cron_health()`. Оба потребителя читают
 * этот класс как общую DI-зависимость (WP Architecture skeptic pass при паковке
 * [internal]: намеренно НЕ помещён внутрь HealthCheckRegistry — тот
 * узко задуман как health-агрегатор для Dashboard/SystemInfo, Settings-страница не
 * входит в этот периметр; ни один из текущих потребителей не должен зависеть от
 * другого, project_module_autonomy_invariant).
 *
 * Различает "stalled" (реально завис — очередь непуста, но ничего не завершалось) от
 * "idle" (нечего было выполнять — здоровое состояние, не сигнал проблемы). До этого
 * фикса оба места давали ложный RED на здоровом idle-cron.
 */
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
			// DISABLE_WP_CRON включён, а Action Scheduler недоступен вовсе — нет
			// работающего механизма выполнения задач. Сохранено 1:1 с прежним поведением
			// ([internal]: этот случай раньше проваливался в финальный stalled-ответ).
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
	 * Чистое решение "idle или stalled" — без I/O, тестируется unit-тестом напрямую
	 * (прецедент {@see \Plathix\Infrastructure\Health\HealthCheckRegistry::is_stuck()}).
	 *
	 * @return array{idle: bool, stalled: bool}
	 */
	private static function decide(bool $has_recent_complete, bool $has_pending): array {
		if ( $has_recent_complete ) {
			return [ 'idle' => false, 'stalled' => false ];
		}

		if ( ! $has_pending ) {
			// Ничего не выполнялось за последний час, но и нечего было выполнять —
			// idle, не stalled ([internal], M11: раньше это давало ложный RED).
			return [ 'idle' => true, 'stalled' => false ];
		}

		return [ 'idle' => false, 'stalled' => true ];
	}
}
