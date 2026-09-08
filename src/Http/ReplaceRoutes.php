<?php

declare(strict_types=1);

namespace Plathix\Http;

use Plathix\Infrastructure\RateLimiter;

final class ReplaceRoutes
{
	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'registerRoutes' ] );
	}

	public function registerRoutes(): void {
		$controller = new ReplaceRestController( new RateLimiter( \Plathix\Infrastructure\Cache::make() ) );

		register_rest_route(
			Rest::NAMESPACE,
			'/attachments/(?P<id>\d+)/replace',
			[
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $controller, 'replaceAttachmentMedia' ],
					'permission_callback' => [ Rest::class, 'canEdit' ],
					'args'                => [
						'id'        => [ 'validate_callback' => static fn (mixed $value): bool => is_numeric( $value ) && (int) $value > 0 ],
						'post_type' => [ 'type' => 'string', 'default' => 'attachment', 'sanitize_callback' => 'sanitize_key' ],
					],
				],
			]
		);
	}
}
