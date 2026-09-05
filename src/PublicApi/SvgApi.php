<?php

declare(strict_types=1);

namespace Plathix\PublicApi;

use Plathix\Modules\Svg\SvgSettings;

/**
 * Стабильная граница Free для чтения SVG-политики — потребители вне модуля Svg
 * (Dashboard, SystemInfo) не должны `use`-ить internal-класс `SvgSettings` напрямую
 * ([internal]: строгая унитарность, 0 исключений; [internal]).
 */
final class SvgApi
{
	/** Текущая SVG-политика: 'sanitize' | 'block' | 'ignore'. */
	public function currentPolicy(): string
	{
		return SvgSettings::current_policy();
	}

	/** true, если текущая политика — «Sanitize on upload». */
	public function isPolicySanitize(): bool
	{
		return SvgSettings::POLICY_SANITIZE === $this->currentPolicy();
	}

	/** Человекочитаемая метка текущей SVG-политики ([internal]). */
	public function currentPolicyLabel(): string
	{
		return match ( $this->currentPolicy() ) {
			SvgSettings::POLICY_SANITIZE => __( 'Sanitize on upload', 'plathix' ),
			SvgSettings::POLICY_BLOCK => __( 'Blocked site-wide', 'plathix' ),
			SvgSettings::POLICY_IGNORE => __( 'Not managed by Plathix', 'plathix' ),
			default => $this->currentPolicy(),
		};
	}
}
