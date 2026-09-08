<?php

declare(strict_types=1);

namespace Plathix\Infrastructure;

final class AllowedMimeTypes
{
	private const TYPES = [

		'ai'     => 'application/postscript',
		'eps'    => 'application/postscript',
		'sketch' => 'application/octet-stream',
		'fig'    => 'application/octet-stream',
		'cdr'    => 'application/cdr',
		'indd'   => 'application/x-indesign',
		'xd'     => 'application/octet-stream',

		'md'     => 'text/markdown',
		'epub'   => 'application/epub+zip',
		'djvu'   => 'image/vnd.djvu',
		'tex'    => 'application/x-tex',
		'log'    => 'text/plain',

		'json'   => 'application/json',
		'yml'    => 'text/yaml',
		'xml'    => 'application/xml',

		'woff'   => 'font/woff',
		'woff2'  => 'font/woff2',
		'ttf'    => 'font/ttf',
		'otf'    => 'font/otf',
	];

	public static function registerHooks(): void
	{
		add_filter( 'upload_mimes', [ self::class, 'addMimes' ] );
	}

	/**
	 * @param array<string, string> $mimes
	 * @return array<string, string>
	 */
	public static function addMimes(array $mimes): array
	{
		return array_merge( $mimes, self::TYPES );
	}

	/** @return array<string, string> */
	public static function getTypes(): array
	{
		return self::TYPES;
	}
}
