<?php

declare(strict_types=1);

namespace Plathix\Modules\SearchFilters;

use Plathix\Contracts\ModuleInterface;
use Plathix\Infrastructure\MediaModalEnqueue;

/**
 * Модуль поиска по папкам и сортировки.
 *
 * PRO-отключаемая фича: без этого модуля дерево папок работает через stub-поля
 * в core store; с модулем — search-entry.js патчит store реальной логикой.
 */
final class Module implements ModuleInterface
{
	public function register(): void
	{
		add_action( 'plathix/modules/boot', [ $this, 'boot' ] );
	}

	public function boot(): void
	{
		add_filter( 'plathix/sidebar/i18n', [ $this, 'add_i18n' ] );
		add_filter( 'plathix/sidebar/config', [ $this, 'add_config' ] );
		// [internal]: admin_enqueue_scripts не срабатывает в редакторе Elementor (тот
		// рендерится через admin_action_elementor, минуя admin-header.php) — тот же корень,
		// что уже нашёл и исправил [internal] для Assets::enqueue_sidebar_for_media_modal().
		// [internal]: единая точка регистрации — Plathix\Infrastructure\MediaModalEnqueue.
		// ВАЖНО: priority=20 на обоих хуках НЕ произвольный — гарантирует выполнение
		// wp_enqueue_media-колбэка ПОСЛЕ Assets::enqueue_sidebar_for_media_modal()
		// (приоритет по умолчанию 10) на этом же хуке — иначе
		// wp_script_is('plathix-sidebar', 'enqueued') guard там увидит false. Не менять
		// priority без проверки этой межмодульной зависимости.
		MediaModalEnqueue::register( [ $this, 'enqueue_scripts' ], 20, 20 );
	}

	/**
	 * @param array<string, string> $i18n
	 * @return array<string, string>
	 */
	public function add_i18n(array $i18n): array
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
	public function add_config(array $config): array
	{
		$config['searchOnlyAt'] = (int) apply_filters( 'plathix/search/only_threshold', 500 );

		return $config;
	}

	public function enqueue_scripts(): void
	{
		if ( ! wp_script_is( 'plathix-sidebar', 'enqueued' ) ) {
			return;
		}

		$asset_file = defined( 'PLATHIX_PATH' ) ? PLATHIX_PATH . 'assets/js/search.asset.php' : '';
		$asset      = ( $asset_file && file_exists( $asset_file ) )
			? include $asset_file
			: [ 'dependencies' => [], 'version' => defined( 'PLATHIX_VERSION' ) ? PLATHIX_VERSION : '1' ];

		wp_enqueue_script(
			'plathix-search',
			defined( 'PLATHIX_ASSETS_URL' ) ? PLATHIX_ASSETS_URL . 'js/search.js' : '',
			array_unique( array_merge( [ 'plathix-sidebar' ], (array) ( $asset['dependencies'] ?? [] ) ) ),
			$asset['version'],
			[
				'in_footer' => true,
				'strategy'  => 'defer',
			]
		);

		// CSS поиска уехал из sidebar.css в search/search.css ([internal]).
		wp_enqueue_style(
			'plathix-search',
			defined( 'PLATHIX_ASSETS_URL' ) ? PLATHIX_ASSETS_URL . 'css/search.css' : '',
			[ 'plathix-sidebar' ],
			$asset['version']
		);
	}
}
