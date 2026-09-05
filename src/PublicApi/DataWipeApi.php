<?php

declare(strict_types=1);

namespace Plathix\PublicApi;

use Plathix\Modules\DataWipe\DangerZoneTab;

/**
 * Стабильная граница Free для идентификатора таба Danger Zone — потребители вне модуля
 * DataWipe не должны `use`-ить internal-класс `DangerZoneTab` ради одной константы
 * ([internal]: строгая унитарность, 0 исключений).
 */
final class DataWipeApi
{
	/** Идентификатор таба Danger Zone на странице настроек. */
	public function tabSlug(): string
	{
		return DangerZoneTab::TAB;
	}
}
