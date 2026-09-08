<?php

declare(strict_types=1);

namespace Plathix\Modules\Preset;

/**
 * Manages the first-run wizard state for the Preset System.
 *
 * Spec §24: Wizard is shown on first install or when onboarding-complete flag is missing.
 * States: null (never shown) | 'skipped' | 'completed'
 */
final class PresetOnboarding
{
	private const OPTION_KEY = 'plathix_preset_onboarding';

	public const STATE_SKIPPED   = 'skipped';
	public const STATE_COMPLETED = 'completed';

	/** Returns true when the wizard should be shown (spec §24.1). */
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

	/** Records the wizard as skipped. No preset is applied, no folders are created (spec §24.2). */
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
