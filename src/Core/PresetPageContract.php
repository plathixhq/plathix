<?php

declare(strict_types=1);

namespace Plathix\Core;

use Plathix\Modules\Preset\PresetsPage;

/**
 * Стабильные platform-имена страницы/actions Preset (аналог FolderColumnContract).
 * Вынесено из Plathix\Modules\Preset\PresetsPage — та остаётся владельцем рендер-логики
 * страницы, а сами имена (slug/action-строки) как naming-контракт живут здесь, доступные
 * другим модулям (Dashboard, FreeFirstRun) без прямого use на Preset-модуль.
 */
final class PresetPageContract
{
	public const PAGE_SLUG = PresetsPage::PAGE_SLUG;
	public const SCRATCH_ACTION = PresetsPage::SCRATCH_ACTION;
	public const APPLY_ACTION = PresetsPage::APPLY_ACTION;
}
