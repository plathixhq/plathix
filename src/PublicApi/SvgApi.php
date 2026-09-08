<?php

declare(strict_types=1);

namespace Plathix\PublicApi;

use Plathix\Modules\Svg\SvgSettings;
use Plathix\Modules\Svg\SvgSupport;

final class SvgApi
{

	public function currentUserCanUploadSvg(): bool
	{
		return ( new SvgSupport() )->currentUserAllowed();
	}

	public function currentPolicy(): string
	{
		return SvgSettings::currentPolicy();
	}

	public function isPolicySanitize(): bool
	{
		return SvgSettings::POLICY_SANITIZE === $this->currentPolicy();
	}

	public function currentPolicyLabel(): string
	{
		return match ( $this->currentPolicy() ) {
			SvgSettings::POLICY_SANITIZE => __( 'Sanitize on upload', 'plathix' ),
			SvgSettings::POLICY_BLOCK => __( 'Blocked site-wide', 'plathix' ),
			SvgSettings::POLICY_IGNORE => __( 'Not managed by Plathix', 'plathix' ),
			default => $this->currentPolicy(),
		};
	}

	public function isSafeMode(): bool
	{
		return SvgSettings::isSafeMode();
	}
}
