<?php

declare(strict_types=1);

namespace Plathix\Infrastructure;

/**
 * [internal]: единая точка резолва identity-key из raw user_id — cross-module extension point
 * (симметрично `plathix/user/access_level` в Plathix\User\AccessResolver), позволяющий PRO
 * (service-токены) развести machine-трафик и impersonated-admin трафик по разной identity, не
 * открывая Free зависимость на PRO-классы. Без подписчика возвращает исходный `$user_id`.
 *
 * Общий stateless helper для RateLimiter и JobStatusRepository — обе стороны сравнения/ключа
 * обязаны резолвиться через один и тот же фильтр, иначе non-token трафик того же admin
 * (например payload, записанный до деплоя этого пакета) перестанет совпадать с текущим ключом.
 *
 * [internal]: `matchesOwner()` — единственная реализация owner-identity сравнения для job
 * payload'ов, устраняет дублирование (было: 3 независимые inline-копии в
 * JobStatusRepository::get(), PRO Module::can_poll_job(), PRO DownloadController::
 * stream_job_zip() — одна разошлась, пропустив isset($payload['user_id'])-guard). Fail-closed
 * по умолчанию: отсутствие `user_id` в payload НЕ пропускает проверку (как было в двух из трёх
 * копий), а трактуется как несовпадающий владелец. `blog_id`-проверка НЕ входит сюда — это
 * multisite-isolation, отдельная от identity семантика, остаётся отдельной веткой на каждом
 * call site.
 */
final class IdentityKeyResolver
{
	public static function resolve(int $user_id): string {
		return (string) apply_filters( 'plathix/infrastructure/current_identity_key', (string) $user_id, $user_id );
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	public static function matchesOwner(array $payload): bool {
		$payload_user_id = (int) ( $payload['user_id'] ?? 0 );

		return apply_filters(
			'plathix/infrastructure/resolve_owner_identity',
			self::resolve( $payload_user_id ),
			$payload_user_id,
			isset( $payload['created_by_token_id'] ) ? (string) $payload['created_by_token_id'] : null
		) === self::resolve( get_current_user_id() );
	}
}
