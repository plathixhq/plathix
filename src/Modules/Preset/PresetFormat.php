<?php

declare(strict_types=1);

namespace Plathix\Modules\Preset;

final class PresetFormat
{

	public const FILENAME = 'preset.plx.md';

	public const FORMAT_VERSION = 2;

	public const ALLOWED_PREVIEWS = [ 'preview.webp', 'preview.png', 'preview.jpg', 'preview.jpeg' ];

	public static function escapeName(string $name): string {
		$escaped = str_replace('\\', '\\\\', $name);
		$escaped = str_replace('"', '\\"', $escaped);

		return '"' . $escaped . '"';
	}

	/**
	 * @param string $raw
	 */

	public static function unescapeName(string $raw): ?string {
		$length = strlen($raw);
		if ( $length < 2 || $raw[0] !== '"' || $raw[ $length - 1 ] !== '"' ) {
			return null;
		}

		$inner  = substr($raw, 1, -1);
		$result = '';
		$i      = 0;
		$len    = strlen($inner);

		while ( $i < $len ) {
			$char = $inner[ $i ];

			if ( $char !== '\\' ) {

				if ( $char === '"' ) {
					return null;
				}

				$result .= $char;
				$i++;
				continue;
			}

			if ( $i + 1 >= $len ) {
				return null;
			}

			$next = $inner[ $i + 1 ];
			if ( $next !== '\\' && $next !== '"' ) {
				return null;
			}

			$result .= $next;
			$i      += 2;
		}

		return $result;
	}
}
