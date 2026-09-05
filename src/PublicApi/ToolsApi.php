<?php

declare(strict_types=1);

namespace Plathix\PublicApi;

use Plathix\Modules\Tools\ToolsPage;

/**
 * Стабильная граница Free для ссылки на страницу Tools — потребители вне модуля Tools не
 * должны `use`-ить internal-класс `ToolsPage` ради одной константы slug'а
 * ([internal]: строгая унитарность, 0 исключений).
 */
final class ToolsApi
{
	/** URL страницы Tools в админке. */
	public function pageUrl(): string
	{
		return admin_url( 'admin.php?page=' . ToolsPage::PAGE_SLUG );
	}

	/** Slug страницы Tools — для потребителей, которым нужен именно slug (напр. add_query_arg). */
	public function pageSlug(): string
	{
		return ToolsPage::PAGE_SLUG;
	}
}
