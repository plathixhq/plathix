<?php

declare(strict_types=1);

namespace Plathix\Core;

final class FolderName
{
	public const ERROR_EMPTY = 'empty';
	public const ERROR_LINE_BREAK = 'line_break';
	public const ERROR_DANGEROUS_CHARS = 'dangerous_chars';
	public const ERROR_TOO_LONG_CHARS = 'too_long_chars';
	public const ERROR_TOO_LONG_BYTES = 'too_long_bytes';

	public static function normalize(string $name): string {
		return mb_substr( trim( preg_replace( '/\s+/', ' ', $name ) ?? '' ), 0, 200 );
	}

	/**
	 * @return list<string>
	 */

	public static function validate(string $normalized, ?int $mbLimit = null): array {
		$errors = [];

		if ( $normalized === '' ) {
			$errors[] = self::ERROR_EMPTY;
		}

		if ( preg_match( '/[\r\n]/', $normalized ) === 1 ) {
			$errors[] = self::ERROR_LINE_BREAK;
		}

		if ( preg_match( '/[\x00-\x1F\x7F\x{0080}-\x{009F}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', $normalized ) === 1 ) {
			$errors[] = self::ERROR_DANGEROUS_CHARS;
		}

		if ( null !== $mbLimit && mb_strlen( $normalized ) > $mbLimit ) {
			$errors[] = self::ERROR_TOO_LONG_CHARS;
		}

		if ( strlen( $normalized ) > 200 ) {
			$errors[] = self::ERROR_TOO_LONG_BYTES;
		}

		return $errors;
	}
}
