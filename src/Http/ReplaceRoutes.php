<?php

declare(strict_types=1);

namespace Plathix\Http;

use Plathix\Infrastructure\RateLimiter;

/**
 * Регистрация REST-маршрута `POST /attachments/{id}/replace`.
 *
 * Компонент feature-модуля `Modules\Rest` (module-standard §7 v3, финальная модель):
 * REST — отдельный отключаемый модуль из компонентов, а НЕ платформенный слой.
 * Цепляется к тонкому `Http\Rest` (namespace + permission), регистрируется двухфазным
 * `Modules\Rest\Module` как самодостаточный route-класс.
 *
 * Физическое размещение в `src/Http/` (namespace `Plathix\Http`) — legacy-tolerated
 * (решение «по касанию», не форсированный переезд): на runtime-контракт и двухфазность
 * не влияет, поэтому переезд не форсируется.
 *
 * ВНИМАНИЕ ([internal]). Прежняя редакция этого докблока описывала отменённую модель v2:
 * будто REST консолидирован в платформенном слое, а модули своего REST не имеют. §7 был
 * пересмотрен в тот же день (v3): модули СВОЙ REST НЕСУТ — так работают
 * `Modules\Favorites` во Free и все 8 маршрутов PRO. Не восстанавливать формулировку v2:
 * именно она породила #348 с требованием обратной консолидации.
 * Дословная фраза v2 здесь намеренно не цитируется — её отсутствие в файле проверяет
 * `tests/RestRouteOwnershipTest::testModulesAreAllowedToOwnTheirRest()`.
 */
final class ReplaceRoutes
{
	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		$controller = new ReplaceRestController( new RateLimiter( \Plathix\Infrastructure\Cache::make() ) );

		register_rest_route(
			Rest::NAMESPACE,
			'/attachments/(?P<id>\d+)/replace',
			[
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $controller, 'replace_attachment_media' ],
					'permission_callback' => [ Rest::class, 'can_edit' ],
					'args'                => [
						'id'        => [ 'validate_callback' => static fn (mixed $value): bool => is_numeric( $value ) && (int) $value > 0 ],
						'post_type' => [ 'type' => 'string', 'default' => 'attachment', 'sanitize_callback' => 'sanitize_key' ],
					],
				],
			]
		);
	}
}
