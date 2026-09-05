<?php

declare(strict_types=1);

namespace Plathix\Core;

/**
 * Determines whether a frontend request is a page-builder editor context that
 * should receive the media sidebar.
 *
 * Accepts raw parameters instead of reading superglobals so the logic can be
 * tested without WordPress being present.
 */
final class BuilderDetect
{
	/**
	 * @param string[] $post_types Post types the sidebar serves (Free: всегда ['attachment'], CTAN-201).
	 * @param array<string, string> $query Incoming query params (normally $_GET).
	 */
	public static function is_frontend_builder_request(
		bool $is_admin,
		array $post_types,
		array $query
	): bool {
		if ( $is_admin ) {
			return false;
		}

		if ( ! in_array( 'attachment', $post_types, true ) ) {
			return false;
		}

		// Elementor: ?elementor-preview=<id> (iframe) or ?action=elementor
		if ( (string) ( $query['elementor-preview'] ?? '' ) !== '' ) {
			return true;
		}
		if ( ( $query['action'] ?? '' ) === 'elementor' ) {
			return true;
		}

		// Beaver Builder: opens on the frontend page with ?fl_builder
		if ( array_key_exists( 'fl_builder', $query ) ) {
			return true;
		}

		// Bricks: ?bricks=run
		if ( ( $query['bricks'] ?? '' ) === 'run' ) {
			return true;
		}

		// Brizy: ?brizy-edit or ?brizy-edit-iframe
		if ( array_key_exists( 'brizy-edit', $query ) || array_key_exists( 'brizy-edit-iframe', $query ) ) {
			return true;
		}

		// Divi frontend builder: ?et_fb (any non-empty value)
		if ( (string) ( $query['et_fb'] ?? '' ) !== '' ) {
			return true;
		}

		// Oxygen: ?ct_builder
		if ( array_key_exists( 'ct_builder', $query ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Admin-контекст: открыт ли редактор стороннего page builder'а внутри wp-admin.
	 *
	 * [internal] ([internal]): логика перенесена из RequestContext::is_page_builder() —
	 * таблица маркеров сторонних билдеров принадлежит классу-детектору, не state-классу
	 * screen-резолюции. Admin- и frontend-детекция сведены в один класс, но намеренно
	 * НЕ в один метод: гейты is_admin противоположны, а механизмы матчинга не изоморфны
	 * (здесь — значения ?page=/?action=; во frontend-версии — наличие ключей и
	 * спец-значения вроде bricks=run). Общий метод потребовал бы таблицу-DSL с типом
	 * матча ради двух потребителей.
	 *
	 * Вход приходит уже нормализованным от обёртки RequestContext::is_page_builder()
	 * (wp_unslash/sanitize_key применяются на границе с $_GET) — сам метод остаётся
	 * чистым и тестируемым без WordPress.
	 *
	 * @param bool   $isAdmin           Результат is_admin() на границе.
	 * @param string $elementorPreview  Значение $_GET['elementor-preview'] после wp_unslash ('' если нет).
	 * @param string $action            Значение $_GET['action'] после sanitize_key ('' если нет).
	 * @param string $page              Значение $_GET['page'] после sanitize_key ('' если нет).
	 */
	public static function isAdminBuilderRequest(
		bool $isAdmin,
		string $elementorPreview,
		string $action,
		string $page
	): bool {
		if ( ! $isAdmin ) {
			return false;
		}

		// Elementor: real editor opens via ?action=elementor or ?elementor-preview=,
		// NOT via ?page=elementor* (those are dashboard/settings pages, not editors).
		if ( $elementorPreview !== '' ) {
			return true;
		}
		if ( $action === 'elementor' ) {
			return true;
		}

		// Other page builders that use a dedicated ?page= slug for the editor itself.
		return in_array( $page, [ 'bricks', 'bricks_builder', 'oxy_builder', 'ct_builder', 'fl-builder', 'et_theme_builder', 'et-fb', 'divi' ], true );
	}
}
