<?php

declare(strict_types=1);

namespace Plathix\Helpers;

class Sanitize
{
	public static function folderName(string $name): string {
		return sanitize_text_field(wp_strip_all_tags($name));
	}

	/**
	 * @return list<int>
	 */
	public static function ids(mixed $ids): array {
		return array_values(array_filter(array_map('absint', (array) $ids)));
	}

	/**
	 * @return list<int>
	 */

	public static function idsFromCsvOrArray(mixed $value): array {
		if ( is_string($value) ) {
			$value = preg_split('/\s*,\s*/', $value) ?: [];
		}

		return self::ids($value);
	}

	/**
	 * @param mixed $value
	 * @return mixed
	 */

	public static function deepText(mixed $value, int $maxDepth = 2): mixed {
		if ( ! is_array($value) || $maxDepth <= 0 ) {
			return sanitize_text_field(is_array($value) ? '' : (string) $value);
		}

		$out = [];
		foreach ( $value as $key => $item ) {
			$out[sanitize_key( (string) $key)] = self::deepText($item, $maxDepth - 1);
		}

		return $out;
	}

	/**
	 * @return array<string, array<string, bool>>
	 */

	public static function iconAllowedHtml(): array {
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

	public static function iconMarkup(string $html): string {
		return wp_kses( $html, self::iconAllowedHtml() );
	}

	/**
	 * @param mixed $value
	 */

	public static function toScalarString(mixed $value): string {
		return is_scalar($value) ? (string) $value : '';
	}
}
