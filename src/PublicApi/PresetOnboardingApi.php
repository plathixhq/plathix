<?php

declare(strict_types=1);

namespace Plathix\PublicApi;

use Plathix\Modules\Preset\PresetOnboarding;

/**
 * Стабильная граница Free для состояния first-run wizard'а — потребители вне модуля Preset
 * (в частности `Modules\FreeFirstRun`) не должны `use`-ить internal-класс `PresetOnboarding`
 * напрямую ([internal]: строгая унитарность, 0 исключений).
 *
 * Статический фасад: {@see PresetOnboarding} сам stateless static wrapper над
 * get_option/update_option, DI-конструктор здесь не нужен (нет ветвления логики для тестов —
 * поведение целиком определяется опцией в БД, как и у оригинала).
 */
final class PresetOnboardingApi
{
	public static function shouldShowWizard(): bool
	{
		return PresetOnboarding::should_show_wizard();
	}

	public static function markSkipped(): void
	{
		PresetOnboarding::mark_skipped();
	}

	public static function reset(): void
	{
		PresetOnboarding::reset();
	}
}
