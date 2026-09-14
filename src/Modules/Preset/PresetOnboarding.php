<?php

declare(strict_types=1);

namespace Plathix\Modules\Preset;

use Plathix\Infrastructure\Logger;
use Plathix\Infrastructure\OptionWrite;

final class PresetOnboarding
{
	private const OPTION_KEY = 'plathix_preset_onboarding';

	public const STATE_SKIPPED   = 'skipped';
	public const STATE_COMPLETED = 'completed';

	public static function shouldShowWizard(): bool {
		return self::getState() === null;
	}

	public static function getState(): ?string {
		$value = get_option( self::OPTION_KEY, null );
		if ( $value === self::STATE_SKIPPED || $value === self::STATE_COMPLETED ) {
			return $value;
		}
		return null;
	}

	public static function markSkipped(): void {
		if ( ! OptionWrite::ifChanged( self::OPTION_KEY, self::STATE_SKIPPED ) ) {
			Logger::error( 'preset_onboarding_skip_write_failed', [] );
		}
	}

	/** Records the wizard as completed (preset was chosen and applied). */
	public static function markCompleted(): void {
		if ( ! OptionWrite::ifChanged( self::OPTION_KEY, self::STATE_COMPLETED ) ) {
			Logger::error( 'preset_onboarding_complete_write_failed', [] );
		}
	}

	/** Resets state so the wizard is shown again on next page load. */
	public static function reset(): void {
		if ( ! OptionWrite::deleted( self::OPTION_KEY ) ) {
			Logger::error( 'preset_onboarding_reset_failed', [] );
		}
	}
}
