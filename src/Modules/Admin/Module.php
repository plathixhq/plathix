<?php

declare(strict_types=1);

namespace Plathix\Modules\Admin;

use Plathix\Admin\AdminMenuManager;
use Plathix\Contracts\ModuleInterface;

/**
 * Admin module — после полной атомизации владеет только меню Plathix (AdminMenuManager).
 * Все admin-фичи вынесены в свои модули:
 * - ShortcodesListPage → Modules\Gallery ([internal])
 * - ToolsPage → Modules\Tools ([internal])
 * - SystemInfoPage → Modules\SystemInfo ([internal])
 * - ProPage → Modules\Pro ([internal])
 * - FolderColumn + ListScreenFragmentsController → Modules\ListScreen ([internal])
 * - FolderMetaBox + AttachmentSideMetaBox + AttachmentDetails → Modules\AttachmentMeta ([internal])
 */
class Module implements ModuleInterface
{
	public function register(): void
	{
		( new AdminMenuManager() )->register();

		// Подписчик plathix/gallery/should_enqueue_builder УДАЛЁН ([internal]): enqueue-владение
		// билдера переехало в PRO — PRO сам распознаёт медиатеку (upload.php), не полагаясь на
		// Free. Free больше не решает «грузить ли билдер» (билдер = PRO-фича).
	}
}
