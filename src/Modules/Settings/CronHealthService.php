<?php

declare(strict_types=1);

namespace Plathix\Modules\Settings;

use Plathix\Infrastructure\CronStatusResolver;

final class CronHealthService
{
	/** @return array{label:string,ok:bool,ok_text:string,bad_text:string} */
	public function cronHealth(): array {
		$status = ( new CronStatusResolver() )->resolve();

		return [ 'label' => '', 'ok' => ! $status['stalled'], 'ok_text' => '', 'bad_text' => '' ];
	}

	public function isStalled(): bool {
		return ! $this->cronHealth()['ok'];
	}
}
