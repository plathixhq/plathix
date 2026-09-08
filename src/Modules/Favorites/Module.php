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

		MediaModalEnqueue::register( [ $this, 'enqueueScripts' ], 20, 20 );

		$controller = new FavoritesController(
			static fn(): int => \get_current_user_id(),
			static function (int $user_id, array $ids, string $post_type): void {
				Preferences::setFavorites($user_id, $ids, $post_type);
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
							'callback'            => [ $controller, 'updateFavorites' ],

							'permission_callback' => static fn(\WP_REST_Request $request): bool =>
								RestController::check( 'assign', self::requestScalar( $request->get_param( 'post_type' ) ) ),
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

	public function enqueueScripts(): void
	{
		if ( ! wp_script_is( 'plathix-sidebar', 'enqueued' ) ) {
			return;
		}

		$asset = \Plathix\Infrastructure\AssetManifest::read( 'js/favorites.asset.php' );

		wp_enqueue_script(
			'plathix-favorites',
			defined( 'PLATHIX_ASSETS_URL' ) ? PLATHIX_ASSETS_URL . 'js/favorites.js' : '',
			array_unique( array_merge( [ 'plathix-sidebar' ], $asset['dependencies'] ?? [] ) ),
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
