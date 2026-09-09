<?php

declare(strict_types=1);

namespace Plathix\Modules\Preset;

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
		update_option( self::OPTION_KEY, self::STATE_SKIPPED, false );
	}

	/** Records the wizard as completed (preset was chosen and applied). */
	public static function markCompleted(): void {
		update_option( self::OPTION_KEY, self::STATE_COMPLETED, false );
	}

	/** Resets state so the wizard is shown again on next page load. */
	public static function reset(): void {
		delete_option( self::OPTION_KEY );
	}
}
