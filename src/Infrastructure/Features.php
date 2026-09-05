<?php

declare(strict_types=1);

namespace Plathix\Infrastructure;

final class Features
{
	/** @var array<string, bool> */
	private static array $flags = [];

	public static function is_enabled(string $feature): bool {
		if ( ! isset(self::$flags[ $feature ]) ) {
			$default = match ( $feature ) {
				'gallery'      => true,
				'import'       => true,
				// shortcode_builder УДАЛЁН ([internal]): билдер = PRO-фича, во Free его нет.
				// PRO гейтит своим фильтром plathixpro/gallery/shortcode_builder_enabled.
				// SVG-фича «активна» (штатные upload/sanitize-фильтры) только в политике sanitize.
				// block/ignore дают false: boot различит их отдельно по plathix_svg_policy ([internal]).
				'svg'          => \Plathix\Modules\Svg\SvgSettings::current_policy() === \Plathix\Modules\Svg\SvgSettings::POLICY_SANITIZE,
				'lazy_tree'    => (bool) get_option('plathix_lazy_tree', false),
				'folder_icons' => false,
				'share_links'  => false,
				// [internal] ([internal]): dnd/upload_sync ранее не имели PHP-источника —
				// JS getFeatures() дефолтил их в true через `!== false` на отсутствующем ключе.
				// Дефолт true здесь сохраняет то же наблюдаемое поведение, но даёт реальный канал
				// выключения через apply_filters('plathix/feature/dnd'|'upload_sync', ...).
				'dnd'          => true,
				'upload_sync'  => true,
				// replace_media УДАЛЁН ([internal], [internal]): dead-код — 0 вызовов
				// is_enabled('replace_media') в кодовой базе. Реальный Replace-модуль
				// (AttachmentReplaceUi.php) работает автономно, не через Features.
				default        => false,
			};

			self::$flags[ $feature ] = (bool) apply_filters('plathix/feature/' . $feature, $default);
		}

		return self::$flags[ $feature ];
	}
}
