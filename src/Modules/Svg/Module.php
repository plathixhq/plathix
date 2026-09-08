<?php

declare(strict_types=1);

namespace Plathix\Modules\Svg;

use Plathix\Contracts\ModuleInterface;

final class Module implements ModuleInterface
{

	public function register(): void
	{
		add_action( 'plathix/modules/boot', [ $this, 'boot' ] );
		add_filter( 'plathix/feature/svg', [ $this, 'filterSvgFeatureEnabled' ] );
	}

	/**
	 * @param mixed $default
	 */

	public function filterSvgFeatureEnabled($default): bool
	{
		return SvgSettings::currentPolicy() === SvgSettings::POLICY_SANITIZE;
	}

	public function boot(): void
	{

		( new SvgSettings() )->register();

		switch ( SvgSettings::currentPolicy() ) {
			case SvgSettings::POLICY_SANITIZE:
				( new SvgSupport() )->register();
				break;
			case SvgSettings::POLICY_BLOCK:
				add_filter( 'upload_mimes', [ $this, 'blockSvgMimes' ], PHP_INT_MAX );
				break;
			case SvgSettings::POLICY_IGNORE:

				break;
		}
	}

	/**
	 * @param array<string,string> $mimes
	 * @return array<string,string>
	 */

	public function blockSvgMimes(array $mimes): array
	{
		unset( $mimes['svg'], $mimes['svgz'] );

		return $mimes;
	}
}
