<?php

declare(strict_types=1);

namespace Plathix\Core;

use Plathix\Modules\Preset\PresetsPage;

final class PresetPageContract
{
	public const PAGE_SLUG = PresetsPage::PAGE_SLUG;
	public const SCRATCH_ACTION = PresetsPage::SCRATCH_ACTION;
	public const APPLY_ACTION = PresetsPage::APPLY_ACTION;
}
