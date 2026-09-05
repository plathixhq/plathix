<?php

declare(strict_types=1);

namespace Plathix\Infrastructure;

/**
 * Расширяет список MIME-типов разрешённых WP для загрузки.
 * Дизайн-форматы, документы, данные и шрифты, которые WP не принимает из коробки.
 *
 * Намеренно исключены: php, html (XSS/RCE риск), svg/svgz (Modules\Svg),
 * webp/csv (WP поддерживает нативно), sql, py, ts (не медиа-контент).
 */
final class AllowedMimeTypes
{
	private const TYPES = [
		// Дизайн
		'ai'     => 'application/postscript',
		'eps'    => 'application/postscript',
		'sketch' => 'application/octet-stream',
		'fig'    => 'application/octet-stream',
		'cdr'    => 'application/cdr',
		'indd'   => 'application/x-indesign',
		'xd'     => 'application/octet-stream',
		// Документы
		'md'     => 'text/markdown',
		'epub'   => 'application/epub+zip',
		'djvu'   => 'image/vnd.djvu',
		'tex'    => 'application/x-tex',
		'log'    => 'text/plain',
		// Данные
		'json'   => 'application/json',
		'yml'    => 'text/yaml',
		'xml'    => 'application/xml',
		// Шрифты
		'woff'   => 'font/woff',
		'woff2'  => 'font/woff2',
		'ttf'    => 'font/ttf',
		'otf'    => 'font/otf',
	];

	public static function register_hooks(): void
	{
		add_filter( 'upload_mimes', [ self::class, 'add_mimes' ] );
	}

	/**
	 * @param array<string, string> $mimes
	 * @return array<string, string>
	 */
	public static function add_mimes(array $mimes): array
	{
		return array_merge( $mimes, self::TYPES );
	}

	/** @return array<string, string> */
	public static function get_types(): array
	{
		return self::TYPES;
	}
}
