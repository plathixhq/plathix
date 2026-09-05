<?php

declare(strict_types=1);

namespace Plathix\PublicApi;

/**
 * Общий admin-page-гейт ([internal], cross-repo часть).
 *
 * Изначально механизм "это наша plathix-страница?" (hook-сравнение + $_GET['page']-fallback)
 * был скопирован независимо в 4 PRO Assets-классах и во Free {@see \Plathix\Modules\Pro\ProPageAssets}.
 * PRO-часть уже вынесена в {@see \PlathixPro\PublicApi\AdminPageGuard} ([internal], PRO-пакет).
 * Этот класс — Free-сторона того же выноса: PRO уже зависит от Free напрямую в нескольких
 * местах (module-autonomy инвариант допускает PRO→Free, запрещает обратное), поэтому общий
 * механизм физически может жить только здесь, не в PRO.
 *
 * Идентичная сигнатура PRO-версии — для консистентности стиля, не формальной совместимости
 * (разные namespace/репозитории, не связаны наследованием).
 */
final class AdminPageGuard
{
	/**
	 * @param string   $hook  Текущий admin-хук от WordPress.
	 * @param string[] $hooks Допустимые точные значения $hook.
	 * @param string[] $pages Допустимые значения $_GET['page'] (fallback, если hook не совпал).
	 */
	public static function matches(string $hook, array $hooks, array $pages): bool {
		if ( in_array( $hook, $hooks, true ) ) {
			return true;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen-resolution deciding whether the caller's CSS/JS loads on this admin page; sanitized (sanitize_key), no form processing, no DB write
		$page = isset( $_GET['page'] ) && is_string( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		return in_array( $page, $pages, true );
	}
}
