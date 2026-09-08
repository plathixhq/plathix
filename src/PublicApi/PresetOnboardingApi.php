<?php

declare(strict_types=1);

namespace Plathix\PublicApi;

use Plathix\Modules\Preset\PresetOnboarding;

final class PresetOnboardingApi
{
	public static function shouldShowWizard(): bool
	{
		return PresetOnboarding::shouldShowWizard();
	}

	public static function markSkipped(): void
	{
		PresetOnboarding::markSkipped();
	}

	public static function reset(): void
	{
		PresetOnboarding::reset();
	}
}
