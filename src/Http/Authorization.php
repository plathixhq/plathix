<?php

declare(strict_types=1);

namespace Plathix\Http;

use Plathix\User\AccessLevel;
use Plathix\User\AccessResolver;

/**
 * Публичный контракт авторизации операций над папками ([internal], CTAN-101).
 *
 * Единый владелец операционной оси view/assign/manage → AccessLevel (карта перенесена из
 * RestController::$cap_map) и полного гейта «операция × post_type → bool», которым пользуется
 * Free REST (`RestController::check`) и PRO-маршруты (`plathix-pro/v1`) — security-логика не
 * дублируется между репозиториями. Free AJAX ({@see AjaxGuard::require()}) НЕ вызывает
 * {@see self::capability()} напрямую — у него собственный порядок проверок (nonce первым,
 * type-gate, access-level, cap-резолв); общий с этим классом примитив — только
 * {@see \Plathix\User\AccessLevel::resolve_cap()} ([internal], [internal]).
 *
 * Порядок в {@see self::authorize()} ОБЯЗАТЕЛЕН (paradigm-skeptic р.4, спека §B):
 * фильтр `plathix/rest/post_type_allowed` получает СЫРОЕ значение (после sanitize_key, БЕЗ
 * нормализации '' → 'attachment') — сохранение наблюдаемого контракта хука для подписчиков
 * (PRO ApiKey сужает права сервис-токена по типу); нормализация выполняется ПОСЛЕ фильтра.
 */
final class Authorization
{
	/**
	 * Операция → минимальный AccessLevel, по типу контента. Ключ 'attachment' также
	 * покрывает пустой post_type (нормализация в cap_entry() — '' и 'attachment'
	 * маппятся на одну и ту же запись до чтения массива; отдельный ключ '' был
	 * недостижим и убран, [internal] п.7). '_cpt' — единая карта прав для ЛЮБОГО
	 * прочего типа: PRO платные content-типы (ContentTypesRestPermission.php) все
	 * получают эту одну карту через тот же cap_entry(); per-CPT дифференциация прав
	 * сейчас не запрошена ни в Free, ни в PRO — если появится такая потребность, это
	 * отдельная product-задача, не эта чистка. Cap-строка резолвится единым
	 * {@see AccessLevel::resolve_cap()} ([internal]).
	 *
	 * @var array<string, array<string, AccessLevel>>
	 */
	private static array $cap_map = [
		'attachment' => [
			'view' => AccessLevel::View,
			'assign' => AccessLevel::Upload,
			'manage' => AccessLevel::Full,
		],
		'_cpt' => [
			'view' => AccessLevel::View,
			'assign' => AccessLevel::Upload,
			'manage' => AccessLevel::Full,
		],
	];

	/**
	 * Полный контракт для транспортов, НЕ имеющих собственного type-гейта до этого вызова
	 * (PRO-маршруты: permission_callback = «тип ∈ PRO-опция» && authorize()).
	 *
	 * НЕ содержит гейта по списку активных типов — он остаётся у вызывающего транспорта
	 * (Free: attachment-гейт в RestController::check; PRO: проверка своей опции).
	 */
	public static function authorize(string $operation, string $post_type): bool {
		$post_type = sanitize_key( $post_type );

		// Restriction-канал сервис-токенов (семантика сужения, default true — см. докблок
		// класса). СЫРОЕ значение, до нормализации — р.4.
		if ( ! apply_filters( 'plathix/rest/post_type_allowed', true, $post_type ) ) {
			return false;
		}

		return self::capability( $operation, $post_type );
	}

	/**
	 * Cap+satisfies-ось (шаги после фильтра): нормализация '' → 'attachment', карта операции,
	 * current_user_can И AccessResolver->satisfies — второе обязательно: голый
	 * current_user_can не видит `plathix/user/access_level`-override (PRO RolePolicy понижает
	 * уровень юзера/роли; прецедент бага — AjaxGuard::require_cap, [internal]).
	 */
	public static function capability(string $operation, string $post_type): bool {
		[ $wp_cap, $min_level ] = self::cap_entry( $operation, sanitize_key( $post_type ) );
		$user_level             = AccessResolver::for_current_user();

		return current_user_can( $wp_cap )
			&& $user_level !== AccessLevel::None
			&& $user_level->satisfies( $min_level );
	}

	/**
	 * Перенос RestController::get_cap_entry ([internal] сохранён: cap — через
	 * AccessLevel::resolve_cap, единственный источник правила).
	 *
	 * @return array{0: string, 1: AccessLevel}
	 */
	public static function cap_entry(string $operation, string $post_type): array {
		$map_key = match ( true ) {
			$post_type === '' || $post_type === 'attachment' => 'attachment',
			isset( self::$cap_map[ $post_type ] ) => $post_type,
			default => '_cpt',
		};

		$min_level = self::$cap_map[ $map_key ][ $operation ] ?? self::$cap_map['attachment']['view'];

		return [ $min_level->resolve_cap( $post_type ), $min_level ];
	}
}
