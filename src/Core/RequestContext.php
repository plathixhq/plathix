<?php

declare(strict_types=1);

namespace Plathix\Core;

use Plathix\Loader;

final class RequestContext
{
	private static string $post_type = '';
	private static string $screen_id = '';
	private static bool $is_active = false;
	private static bool $initialized = false;

	public function __construct(Loader $loader) {
		$loader->add_action( 'current_screen', $this, 'init', 5 );
	}

	public static function get_post_type(): string {
		if ( ! self::$is_active ) {
			throw new \LogicException(
				'RequestContext::get_post_type() called when is_active=false. Use TaxonomyResolver::fromPostType( $post_type ) outside admin UI.'
			);
		}

		return self::$post_type;
	}

	public static function get_screen_id(): string {
		return self::$screen_id;
	}

	public static function is_active(): bool {
		return self::$is_active;
	}

	public function init(): void {
		if ( self::$initialized ) {
			return;
		}

		self::$initialized = true;
		self::$post_type = self::resolve_post_type_from_screen();
		self::$is_active = self::$post_type !== '';

		global $current_screen;
		self::$screen_id = ( $current_screen instanceof \WP_Screen ) ? (string) $current_screen->id : '';
	}

	/**
	 * [internal] ([internal]): тип записи берётся у WP_Screen, а не собирается из
	 * $pagenow + $_GET.
	 *
	 * Этот резолвер висит на `current_screen` (приоритет 5), то есть экран к моменту вызова
	 * уже существует и заполнен — `WP_Screen::$post_type` содержит ровно тот тип, который
	 * WordPress определил сам, включая случаи, которые связка $pagenow+$_GET не покрывает.
	 * Ветки на $pagenow остаются фолбэком: они срабатывают, только если экран недоступен
	 * (ранний вызов вне админского цикла).
	 */
	private static function resolve_post_type_from_screen(): string {
		global $pagenow, $current_screen;

		if ( is_admin() && $current_screen instanceof \WP_Screen ) {
			$screen_post_type = (string) $current_screen->post_type;

			if ( $screen_post_type !== '' ) {
				return $screen_post_type;
			}

			if ( $current_screen->base === 'upload' ) {
				return 'attachment';
			}
		}

		return match ( true ) {
			is_admin() && $pagenow === 'upload.php' => 'attachment',
			is_admin() && $pagenow === 'post.php' => get_post_type( absint( wp_unslash( $_GET['post'] ?? 0 ) ) ) ?: 'post', // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- fallback for when WP_Screen is unavailable; read-only navigation parameter, no form processing and no DB write
			is_admin() && $pagenow === 'post-new.php' => sanitize_key( (string) wp_unslash( $_GET['post_type'] ?? 'post' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- fallback for when WP_Screen is unavailable; read-only navigation parameter, no form processing and no DB write
			// CTAN-402: ветка экранов списков записей удалена — единственный Free-потребитель
			// (Assets::enqueue) гейтится резолвером, который такие экраны не резолвит; тип для
			// них определяет PRO-обвязка собственных поверхностей.
			self::is_page_builder() => 'attachment',
			default => '',
		};
	}

	public static function is_page_builder_request(): bool {
		return self::is_page_builder();
	}

	/**
	 * [internal] ([internal]): граница с суперглобалами. Извлечение и нормализация
	 * значений остаются здесь ровно в прежней форме (bit-parity), логика детекции —
	 * в BuilderDetect::isAdminBuilderRequest() с явными параметрами.
	 */
	private static function is_page_builder(): bool {
		return BuilderDetect::isAdminBuilderRequest(
			is_admin(),
			(string) wp_unslash( $_GET['elementor-preview'] ?? '' ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Recommended -- non-empty check only, value is never output; read-only screen-resolution from WP navigation params, no form processing, no DB write
			sanitize_key( (string) wp_unslash( $_GET['action'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen-resolution from WP navigation params ($pagenow context); no form processing, no DB write
			sanitize_key( (string) wp_unslash( $_GET['page'] ?? '' ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen-resolution from WP navigation params ($pagenow context); no form processing, no DB write
		);
	}

	public static function reset_for_test(): void {
		self::$post_type = '';
		self::$screen_id = '';
		self::$is_active = false;
		self::$initialized = false;
	}
}
