<?php

declare(strict_types=1);

namespace Plathix\PublicApi;

use Plathix\Modules\Settings\SettingsPage;

/**
 * Стабильная граница Free для ссылки на страницу Settings — потребители вне модуля Settings
 * не должны `use`-ить internal-класс `SettingsPage` ради одной константы slug'а
 * ([internal]: строгая унитарность, 0 исключений).
 */
final class SettingsApi
{
	/** URL страницы Settings в админке, опционально с конкретной вкладкой. */
	public function pageUrl(?string $tab = null): string
	{
		$url = admin_url( 'admin.php?page=' . SettingsPage::PAGE_SLUG );

		return null === $tab ? $url : $url . '&tab=' . $tab;
	}

	/** Slug страницы Settings — для потребителей, которым нужен именно slug (напр. add_query_arg). */
	public function pageSlug(): string
	{
		return SettingsPage::PAGE_SLUG;
	}
}
