<?php

declare(strict_types=1);

namespace Plathix\Helpers;

class Sanitize
{
	public static function folder_name(string $name): string {
		return sanitize_text_field(wp_strip_all_tags($name));
	}

	/**
	 * @return list<int>
	 */
	public static function ids(mixed $ids): array {
		return array_values(array_filter(array_map('absint', (array) $ids)));
	}

	/**
	 * Рекурсивно sanitize_text_field каждого листа, СОХРАНЯЯ структуру массива ([internal]).
	 *
	 * Скаляр → sanitize_text_field; массив → тот же массив с sanitized-листьями (ключи через
	 * sanitize_key). Прежний `sanitize_text_field((string) $raw)` на array-фильтр (`?tax[]=a&tax[]=b`)
	 * давал `'Array'` + warning — структура фильтра терялась. Здесь она сохраняется.
	 *
	 * $max_depth защищает от патологической вложенности (`?a[b][c][d]…` DoS): за пределом глубины
	 * значение приводится к строке через sanitize (не рекурсируем дальше). WP query-фильтры
	 * одноуровневые (`key[]=v`) или максимум `key[sub]=v`, поэтому дефолт 2 достаточен.
	 *
	 * @param mixed $value
	 * @return mixed sanitized-скаляр (string) или массив той же структуры с sanitized-листьями
	 */
	public static function deep_text(mixed $value, int $max_depth = 2): mixed {
		if ( ! is_array($value) || $max_depth <= 0 ) {
			return sanitize_text_field(is_array($value) ? '' : (string) $value);
		}

		$out = [];
		foreach ( $value as $key => $item ) {
			$out[sanitize_key( (string) $key)] = self::deep_text($item, $max_depth - 1);
		}

		return $out;
	}

	/**
	 * Allowlist тегов/атрибутов для icon_markup(). Вынесен в отдельный метод (не inline
	 * в icon_markup()), чтобы контракт "какие теги/атрибуты разрешены" был доказуем unit-тестом
	 * напрямую — tests/stubs.php определяет wp_kses()/wp_kses_allowed_html() как безусловные
	 * global passthrough-стабы, поэтому Brain\Monkey/Patchwork не может перехватить сам вызов
	 * wp_kses() в этом test suite ([internal] packaging).
	 *
	 * @return array<string, array<string, bool>>
	 */
	public static function icon_allowed_html(): array {
		static $allowed = null;
		if ( $allowed === null ) {
			$allowed = array_merge(
				wp_kses_allowed_html( 'post' ),
				[
					'svg'      => [ 'xmlns' => true, 'width' => true, 'height' => true, 'viewbox' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'stroke-linecap' => true, 'stroke-linejoin' => true, 'aria-hidden' => true, 'class' => true ],
					'path'     => [ 'd' => true, 'fill' => true, 'stroke' => true ],
					'line'     => [ 'x1' => true, 'y1' => true, 'x2' => true, 'y2' => true, 'stroke' => true ],
					'polyline' => [ 'points' => true ],
					'circle'   => [ 'cx' => true, 'cy' => true, 'r' => true, 'fill' => true, 'stroke' => true ],
					'rect'     => [ 'x' => true, 'y' => true, 'width' => true, 'height' => true, 'rx' => true, 'fill' => true ],
				]
			);
		}
		return $allowed;
	}

	/**
	 * Санитизирует inline SVG-markup явным allowlist тегов/атрибутов (не wp_kses_post() —
	 * тот не включает svg/path/circle и т.п.). Источник значений — расширяемый через
	 * apply_filters() дескриптор (напр. plathix/admin/menu_pages,
	 * plathix/sidebar/toolbar_extra), не гарантированно литеральный ([internal]).
	 */
	public static function icon_markup(string $html): string {
		return wp_kses( $html, self::icon_allowed_html() );
	}
}
