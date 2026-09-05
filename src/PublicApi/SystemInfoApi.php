<?php

declare(strict_types=1);

namespace Plathix\PublicApi;

use Plathix\Modules\SystemInfo\SystemInfoPage;

/**
 * Стабильная граница Free для ссылки на страницу System Info — потребители вне модуля
 * SystemInfo не должны `use`-ить internal-класс `SystemInfoPage` ради одной константы slug'а
 * ([internal]: строгая унитарность, 0 исключений).
 */
final class SystemInfoApi
{
	/** URL страницы System Info в админке. */
	public function pageUrl(): string
	{
		return admin_url( 'admin.php?page=' . SystemInfoPage::PAGE_SLUG );
	}
}
