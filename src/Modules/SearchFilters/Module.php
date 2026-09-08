<?php

declare(strict_types=1);

namespace Plathix\Modules\SearchFilters;

use Plathix\Contracts\ModuleInterface;
use Plathix\Infrastructure\MediaModalEnqueue;

final class Module implements ModuleInterface
{
	public function register(): void
	{
		add_action( 'plathix/modules/boot', [ $this, 'boot' ] );
	}

	public function boot(): void
	{
		add_filter( 'plathix/sidebar/i18n', [ $this, 'addI18n' ] );
		add_filter( 'plathix/sidebar/config', [ $this, 'addConfig' ] );

		MediaModalEnqueue::register( [ $this, 'enqueueScripts' ], 20, 20 );
	}

	/**
	 * @param array<string, string> $i18n
	 * @return array<string, string>
	 */
	public function addI18n(array $i18n): array
	{
		$i18n['search_folders'] = __( 'Search folders', 'plathix' );
		$i18n['search_to_browse'] = __( 'Type to browse folders', 'plathix' );
		$i18n['clear_search'] = __( 'Clear search', 'plathix' );

		return $i18n;
	}

	/**
	 * @param array<string, mixed> $config
	 * @return array<string, mixed>
	 */
	public function addConfig(array $config): array
	{
		$config['searchOnlyAt'] = (int) apply_filters( 'plathix/search/only_threshold', 500 );

		return $config;
	}

	public function enqueueScripts(): void
	{
		if ( ! wp_script_is( 'plathix-sidebar', 'enqueued' ) ) {
			return;
		}

		$asset = \Plathix\Infrastructure\AssetManifest::read( 'js/search.asset.php' );

		wp_enqueue_script(
			'plathix-search',
			defined( 'PLATHIX_ASSETS_URL' ) ? PLATHIX_ASSETS_URL . 'js/search.js' : '',
			array_unique( array_merge( [ 'plathix-sidebar' ], $asset['dependencies'] ?? [] ) ),
			$asset['version'],
			[
				'in_footer' => true,
				'strategy'  => 'defer',
			]
		);

		wp_enqueue_style(
			'plathix-search',
			defined( 'PLATHIX_ASSETS_URL' ) ? PLATHIX_ASSETS_URL . 'css/search.css' : '',
			[ 'plathix-sidebar' ],
			$asset['version']
		);
	}
}
