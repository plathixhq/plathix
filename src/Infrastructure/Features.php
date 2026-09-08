<?php

declare(strict_types=1);

namespace Plathix\Infrastructure;

final class Features
{
	/** @var array<string, bool> */
	private static array $flags = [];

	public static function isEnabled(string $feature): bool {
		if ( ! isset(self::$flags[ $feature ]) ) {
			$default = match ( $feature ) {
				'gallery'      => true,
				'import'       => true,

				'svg'          => false,
				'lazy_tree'    => (bool) get_option('plathix_lazy_tree', false),
				'folder_icons' => false,
				'share_links'  => false,

				'dnd'          => true,
				'upload_sync'  => true,

				default        => false,
			};

			self::$flags[ $feature ] = (bool) apply_filters('plathix/feature/' . $feature, $default);
		}

		return self::$flags[ $feature ];
	}
}
