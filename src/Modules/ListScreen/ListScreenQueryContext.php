<?php

declare(strict_types=1);

namespace Plathix\Modules\ListScreen;

/**
 * [internal]: единый источник санитизированного query-контекста list-screen экрана
 * (upload.php list-mode; PRO переиспользует для своих экранов), вместо независимых allowlist'ов
 * (`ListScreenFragmentsController::build_get_args()`, `SearchSortFields`,
 * `FolderColumn::filter_url()`), которые расходились между собой и дважды приводили к
 * потере query-контекста ([internal], [internal]).
 *
 * `orderby` сохраняет compound-значения (несколько ключей через пробел, напр.
 * `menu_order title`) — подтверждённый WP core факт: `WP_Query::parse_orderby()`
 * разбивает `orderby` по пробелу (`explode(' ', ...)`). Санитизация всей строки целиком
 * через `sanitize_key()` (прежний баг в SearchSortFields/FolderColumn) молча портит такие
 * значения (`"menu_order title"` → `"menu_ordertitle"`).
 */
class ListScreenQueryContext
{
	/**
	 * @return array<string, string>
	 */
	public static function fromRequest(): array {
		$context = [];

		$orderby = self::sanitizeOrderby( (string) wp_unslash( $_GET['orderby'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read-only nav param, sanitized in sanitizeOrderby() below, echoed only into esc_attr'd/esc_url'd output by callers
		if ( '' !== $orderby ) {
			$context['orderby'] = $orderby;
		}

		$order = strtoupper( sanitize_key( (string) wp_unslash( $_GET['order'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only nav param, sanitized below, echoed only into esc_attr'd/esc_url'd output by callers
		if ( in_array( $order, [ 'ASC', 'DESC' ], true ) ) {
			$context['order'] = $order;
		}

		foreach ( [ 's' => 'sanitize_text_field', 'post_mime_type' => 'sanitize_text_field' ] as $key => $sanitizer ) {
			$value = (string) wp_unslash( $_GET[ $key ] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read-only nav param, sanitized on the next line, echoed only into esc_attr'd/esc_url'd output by callers
			$value = $sanitizer( $value );
			if ( '' !== $value ) {
				$context[ $key ] = $value;
			}
		}

		foreach ( [ 'm', 'author' ] as $key ) {
			$value = absint( wp_unslash( $_GET[ $key ] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read-only nav param, sanitized via absint(), echoed only into esc_attr'd/esc_url'd output by callers
			if ( $value > 0 ) {
				$context[ $key ] = (string) $value;
			}
		}

		$post_status = sanitize_key( (string) wp_unslash( $_GET['post_status'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read-only nav param, sanitized via sanitize_key(), echoed only into esc_attr'd/esc_url'd output by callers
		if ( '' !== $post_status ) {
			$context['post_status'] = $post_status;
		}

		return $context;
	}

	/**
	 * [internal] ([internal]): переиспользуется `ListScreenFragmentsController::parse_request()`
	 * — тот сайт читает `$_REQUEST` (не `$_GET`) через уже собственную типизацию, поэтому
	 * мигрируется точечно только это поле (единственное реально затронутое compound-багом),
	 * не вся функция целиком (разный shape источника данных — риск для остального,
	 * покрытого существующими тестами #236, не оправдан).
	 */
	public static function sanitizeOrderby(string $raw): string {
		if ( '' === trim( $raw ) ) {
			return '';
		}

		$tokens = array_filter(
			array_map( 'sanitize_key', preg_split( '/\s+/', trim( $raw ) ) ?: [] ),
			static fn(string $token): bool => '' !== $token
		);

		return implode( ' ', $tokens );
	}
}
