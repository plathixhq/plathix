<?php

declare(strict_types=1);

namespace Plathix\Modules\Favorites;

use Plathix\Http\Rest;
use Plathix\Http\RestController;
use Plathix\Http\RestControllerHelpers;
use Plathix\Infrastructure\MediaModalEnqueue;
use Plathix\User\Preferences;

final class Module
{
	use RestControllerHelpers;

	public function register(): void
	{
		add_action('plathix/modules/boot', [$this, 'boot']);
	}

	public function boot(): void
	{
		// [internal] (тот же корень, что #31): admin_enqueue_scripts не срабатывает в
		// редакторе Elementor. wp_enqueue_media — нативный WP-хук media-picker-контекста.
		// [internal]: единая точка регистрации — Plathix\Infrastructure\MediaModalEnqueue.
		MediaModalEnqueue::register( [ $this, 'enqueue_scripts' ], 20, 20 );

		$controller = new FavoritesController(
			static fn(): int => \get_current_user_id(),
			static function (int $user_id, array $ids, string $post_type): void {
				Preferences::set_favorites($user_id, $ids, $post_type);
			},
		);

		add_action(
			'rest_api_init',
			static function () use ($controller): void {
				register_rest_route(
					Rest::NAMESPACE,
					'/favorites',
					[
						[
							'methods'             => \WP_REST_Server::EDITABLE,
							'callback'            => [ $controller, 'update_favorites' ],
							// Единый гейт ([internal], fix M6): через RestController::check — учитывает
							// access_level и service_token_allows_post_type, а не голый upload_files.
							'permission_callback' => static fn(\WP_REST_Request $request): bool =>
								RestController::check( 'assign', self::request_scalar( $request->get_param( 'post_type' ) ) ),
							'args'                => [
								'post_type' => [
									'type'              => 'string',
									'default'           => 'attachment',
									'sanitize_callback' => 'sanitize_key',
								],
								'favorites' => [
									'required' => false,
									'type'     => 'array',
									'items'    => [ 'type' => 'integer' ],
								],
							],
						],
					]
				);
			}
		);
	}

	public function enqueue_scripts(): void
	{
		if ( ! wp_script_is( 'plathix-sidebar', 'enqueued' ) ) {
			return;
		}

		$asset_file = defined( 'PLATHIX_PATH' ) ? PLATHIX_PATH . 'assets/js/favorites.asset.php' : '';
		$asset      = ( $asset_file && file_exists( $asset_file ) )
			? include $asset_file
			: [ 'dependencies' => [], 'version' => defined( 'PLATHIX_VERSION' ) ? PLATHIX_VERSION : '1' ];

		wp_enqueue_script(
			'plathix-favorites',
			defined( 'PLATHIX_ASSETS_URL' ) ? PLATHIX_ASSETS_URL . 'js/favorites.js' : '',
			array_unique( array_merge( [ 'plathix-sidebar' ], (array) ( $asset['dependencies'] ?? [] ) ) ),
			$asset['version'],
			[
				'in_footer' => true,
				'strategy'  => 'defer',
			]
		);

		wp_enqueue_style(
			'plathix-favorites',
			defined( 'PLATHIX_ASSETS_URL' ) ? PLATHIX_ASSETS_URL . 'css/favorites.css' : '',
			[ 'plathix-sidebar' ],
			$asset['version']
		);
	}
}
