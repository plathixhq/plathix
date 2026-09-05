<?php

declare(strict_types=1);

namespace Plathix\Modules\Pro;

/**
 * License-state политика PRO — чистые расчёты по лицензионному ключу и сроку.
 *
 * Только локальная логика формата и срока (regex + арифметика дат). Сетевая
 * верификация ключа на license-сервере — НЕ здесь, она в PRO-плагине
 * (PlathixPro\Modules\License\Module, подписчик хука plathix/license/activate).
 * Вынесено из ProPage ([internal] #103): license-state — отдельная ось,
 * потребляется рендером ProPage и admin-post обработкой ProLicenseActions.
 *
 * Методы public static — контракт сохранён 1:1 с бывшими ProPage::* (тесты зовут статикой).
 */
final class LicensePolicy
{
	/**
	 * Порог «подписка скоро истекает» (дней) — при days <= порога карточка PRO предупреждает.
	 * Это UI-порог license-слоя, НЕ срок возврата: срок возврата — коммерческое условие,
	 * он живёт в commerce.json (CommerceData::refundDays(), [internal] #565; сюда
	 * попал в #103 «за компанию» — license-математика его никогда не потребляла).
	 */
	public const EXPIRY_SOON_DAYS = 14;

	/**
	 * Проверка формата лицензионного ключа — ТОЛЬКО формат (regex + длина), НЕ сеть.
	 * Сетевая валидация — в PRO-подписчике. Логику не менять ([internal]).
	 */
	public static function is_valid_key_format(string $key): bool {
		return 1 === preg_match( '/^[A-Za-z0-9\-]{8,128}$/', $key );
	}

	/**
	 * Сколько полных суток до истечения подписки (null — если срока нет / не распарсился).
	 *
	 * @param string   $expiry_iso ISO-дата истечения или '' (lifetime).
	 * @param int|null $now        Текущий unix-ts (для тестов); по умолчанию time().
	 */
	public static function days_until_expiry(string $expiry_iso, ?int $now = null): ?int {
		if ( '' === $expiry_iso ) {
			return null;
		}

		$expiry_ts = strtotime( $expiry_iso );
		if ( false === $expiry_ts ) {
			return null;
		}

		$now = $now ?? time();

		// floor до полных суток: «сегодня истекает» = 0, а не −1; завтра = 1.
		return (int) floor( ( $expiry_ts - $now ) / DAY_IN_SECONDS );
	}

	/**
	 * Классификация срока в состояние карточки: lifetime / expired / soon / ok.
	 *
	 * @param int|null $days Результат days_until_expiry (null = lifetime).
	 */
	public static function expiry_state(?int $days): string {
		if ( null === $days ) {
			return 'lifetime';
		}
		if ( $days < 0 ) {
			return 'expired';
		}
		if ( $days <= self::EXPIRY_SOON_DAYS ) {
			return 'soon';
		}
		return 'ok';
	}
}
