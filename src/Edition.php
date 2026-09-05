<?php

declare(strict_types=1);

namespace Plathix;

/**
 * Признак редакции плагина (Free/PRO) — единственный источник истины для PRO-gating в UI.
 *
 * Free сам не верифицирует лицензию — только читает уже существующий статус лицензии
 * `plathix_license_status`. Реальную сетевую верификацию ключа делает PRO
 * (`PlathixPro\Modules\License\Module`): вызовы к license-серверу, cron-ревалидация,
 * grace-окно на сетевой сбой, устаревание подтверждения (STATUS_STALE, [internal]) — и
 * пишет результат в те же опции. Без активной лицензии (или без загруженного PRO-кода)
 * статус пуст и is_pro() возвращает false — это и есть Free-режим.
 *
 * Storage-контракт: имя опции и значение активной лицензии ('active') совпадают с тем,
 * что использует ProPage::render_license_card (чтение) и Modules\Pro\ProLicenseActions::handle_activate
 * (запись, [internal] #103) — не менять их в отрыве.
 */
final class Edition
{
	public const STATUS_OPTION = 'plathix_license_status';
	public const STATUS_ACTIVE = 'active';

	/**
	 * Подтверждение устарело — доступ сохраняется, но требуется перепроверка ([internal]).
	 *
	 * НЕ означает «лицензия недействительна»: устаревание означает лишь, что мы давно не
	 * спрашивали сервер. Гасит PRO только явный invalid от LS. Отдельное значение нужно, чтобы
	 * UI не выдавал устаревание за отзыв (ProPage::render_license_card рисовал бы красный
	 * Invalid) и чтобы PRO мог отличить «пора перепроверить» от «активно и свежо».
	 */
	public const STATUS_STALE = 'stale';

	/** Ключ лицензии; во Free — источник истины (пишет/удаляет ProLicenseActions). */
	public const KEY_OPTION = 'plathix_license_key';

	/** Дата истечения подписки. Free НЕ пишет — только читает/удаляет; пишет PRO (License\Module::EXPIRES_OPTION). */
	public const EXPIRES_OPTION = 'plathix_license_expires';

	/** Метка последнего успешного подтверждения сервером. Free НЕ пишет — только читает; пишет PRO (License\Module::LAST_CHECK_OPTION). */
	public const LAST_CHECK_OPTION = 'plathix_license_last_check';

	/**
	 * true только при активной лицензии PRO И реально загруженном PRO-коде.
	 *
	 * Две независимые проверки (обе обязательны):
	 * 1. Статус лицензии `plathix_license_status === 'active'` (пишет PRO-верификатор).
	 * 2. PRO-код присутствует в памяти — маркер-фильтр `plathix/edition/pro_active`, который
	 *    PRO-плагин навешивает на `__return_true` при загрузке.
	 *
	 * Зачем (2): при ДЕАКТИВАЦИИ PRO-плагина опция статуса залипает 'active' в БД
	 * (deactivate ≠ отзыв лицензии, статус намеренно сохраняется для реактивации без
	 * повторного ввода ключа). Без проверки присутствия is_pro() ложно вернул бы true,
	 * хотя PRO-кода нет. Фильтр не навешан при деактивации → is_pro() честно false,
	 * а статус в БД цел (крон-ревалидация PRO восстановит его при реактивации).
	 */
	public static function is_pro(): bool
	{
		$status = get_option( self::STATUS_OPTION, '' );

		// STATUS_STALE даёт доступ наравне с STATUS_ACTIVE ([internal]): устаревание — это
		// «давно не подтверждали», а не «отозвано». Гасит только явный invalid от LS, который
		// стирает статус целиком через PRO revoke_local(). Иначе честный сайт с редким
		// трафиком (очередь Action Scheduler исполняется по HTTP-запросам) терял бы PRO
		// без единого действия со своей стороны.
		if ( self::STATUS_ACTIVE !== $status && self::STATUS_STALE !== $status ) {
			return false;
		}

		// [internal]: статус без ключа недостижим честным путём — ProLicenseActions пишет ключ
		// и статус в одном флоу, а удаляет их одним блоком; оба писателя 'active' в PRO
		// требуют сетевого ответа на непустой ключ. Значит active+пустой ключ — это либо
		// ручная запись в БД, либо обрыв миграции; и то и другое ревалидировать нечем, потому
		// что крон-ревалидация без ключа не может обратиться к серверу.
		// get_option, НЕ get_site_option: обе опции site-scoped, иначе на мультисайте
		// погаснут честные сайты.
		if ( '' === (string) get_option( self::KEY_OPTION, '' ) ) {
			return false;
		}

		return (bool) apply_filters( 'plathix/edition/pro_active', false );
	}
}
