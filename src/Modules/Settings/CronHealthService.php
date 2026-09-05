<?php

declare(strict_types=1);

namespace Plathix\Modules\Settings;

use Plathix\Infrastructure\CronStatusResolver;

/**
 * Проверяет состояние WP-Cron / Action Scheduler.
 *
 * Используется на странице Settings для предупреждения о зависшем планировщике.
 *
 * Делегирует факт в {@see CronStatusResolver} ([internal], M11) — единственный
 * источник истины, разделяемый с `HealthCheckRegistry`. "idle" (нечего было выполнять)
 * трактуется как здоровое состояние (`ok: true`), не как зависание — раньше эта
 * страница (как и Dashboard/System Info) давала ложный RED на здоровом idle-cron.
 */
final class CronHealthService
{
	/** @return array{label:string,ok:bool,ok_text:string,bad_text:string} */
	public function cron_health(): array {
		$status = ( new CronStatusResolver() )->resolve();

		return [ 'label' => '', 'ok' => ! $status['stalled'], 'ok_text' => '', 'bad_text' => '' ];
	}

	/** Возвращает true если Action Scheduler завис (WP-Cron отключён и нет активности). */
	public function is_stalled(): bool {
		return ! $this->cron_health()['ok'];
	}
}
