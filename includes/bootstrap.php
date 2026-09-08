<?php

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define('PLATHIX_VERSION', '1.0.0');

define('PLATHIX_PATH', plugin_dir_path(PLATHIX_FILE));
define('PLATHIX_URL', plugin_dir_url(PLATHIX_FILE));
define('PLATHIX_BASENAME', plugin_basename(PLATHIX_FILE));
define('PLATHIX_ASSETS_URL', PLATHIX_URL . 'assets/');
define('PLATHIX_ASSETS_PATH', PLATHIX_PATH . 'assets/');

define('PLATHIX_TAXONOMY', 'plathix_folder');
define('PLATHIX_MAX_DEPTH', 0); // 0 = unlimited; set via plathix/folder/depth_limit filter to enforce a cap
define('PLATHIX_TAX_PREFIX', 'plathix_folder_');
// WordPress limits taxonomy names to 32 characters.
// With the fixed prefix below, the effective post type slug budget is 14 chars.
define('PLATHIX_TERM_POSITION', 'plathix_position');
define('PLATHIX_TERM_COLOR', 'plathix_color');
define('PLATHIX_TEMP_DIR', 'plathix-temp');

//

//

$plathix_vendor_autoload = PLATHIX_PATH . 'vendor/autoload.php';

if ( file_exists($plathix_vendor_autoload) ) {
	require_once $plathix_vendor_autoload;
}

spl_autoload_register(
	static function (string $class): void {
		if ( ! str_starts_with($class, 'Plathix\\') ) {
			return;
		}

		$relative = substr($class, strlen('Plathix\\'));
		$path     = PLATHIX_PATH . 'src/' . str_replace('\\', '/', $relative) . '.php';

		// Modules live under src/Modules/ — the generic mapping above already resolves them
		// because Plathix\Modules\Preset\... → src/Modules/Preset/....
		// Legacy sub-namespaces that moved into src/Modules/ need a specific fallback:
		if ( ! file_exists($path) && str_starts_with($relative, 'Preset\\') ) {
			$path = PLATHIX_PATH . 'src/Modules/' . str_replace('\\', '/', $relative) . '.php';
		}

		if ( file_exists($path) ) {
			require_once $path;
		}
	}
);

add_action(
	'plathix/modules/register',
	static function (): void {
		( new \Plathix\Modules\Admin\Module() )->register();
		( new \Plathix\Modules\Dashboard\Module() )->register();
		( new \Plathix\Modules\Tools\Module() )->register();
		( new \Plathix\Modules\SystemInfo\Module() )->register();
		( new \Plathix\Modules\Pro\Module() )->register();
		( new \Plathix\Modules\ListScreen\Module() )->register();
		( new \Plathix\Modules\AttachmentMeta\Module() )->register();

		( new \Plathix\Modules\Preset\Module() )->register();
		( new \Plathix\Modules\FreeFirstRun\Module() )->register();
		( new \Plathix\Modules\Replace\Module() )->register();

		( new \Plathix\Modules\Trash\Module() )->register();

		( new \Plathix\Modules\Svg\Module() )->register();

		( new \Plathix\Modules\DataWipe\Module() )->register();
		( new \Plathix\Modules\Import\Module() )->register();
		( new \Plathix\Modules\Rest\Module() )->register();

		( new \Plathix\Modules\Upload\Module() )->register();
		( new \Plathix\Modules\Settings\Module() )->register();
		( new \Plathix\Modules\Multilingual\Module() )->register();

		( new \Plathix\Modules\SearchFilters\Module() )->register();

		( new \Plathix\Modules\FolderColor\Module() )->register();

		( new \Plathix\Modules\Favorites\Module() )->register();
	}
);

register_activation_hook(PLATHIX_FILE, [ 'Plathix\\Activator', 'run' ]);
register_deactivation_hook(PLATHIX_FILE, [ 'Plathix\\Deactivator', 'run' ]);

add_action(
	'plugins_loaded',
	static function (): void {
		Plathix\Plugin::getInstance()->boot();
	}
);
