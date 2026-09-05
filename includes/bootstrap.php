<?php

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define('PLATHIX_VERSION', '1.0.0');
// PLATHIX_FILE определяется в главном plathix.php (guard-блок) ДО этого require и указывает
// на __FILE__ главного файла плагина — здесь переопределять нельзя: __FILE__ в этом контексте
// был бы путём к bootstrap.php, что сломало бы register_activation_hook/plugin_dir_path и
// весь WP-контракт "путь плагина = путь его главного файла".
define('PLATHIX_PATH', plugin_dir_path(PLATHIX_FILE));
define('PLATHIX_URL', plugin_dir_url(PLATHIX_FILE));
define('PLATHIX_BASENAME', plugin_basename(PLATHIX_FILE));
define('PLATHIX_ASSETS_URL', PLATHIX_URL . 'assets/');
define('PLATHIX_ASSETS_PATH', PLATHIX_PATH . 'assets/');

define('PLATHIX_TAXONOMY', 'plathix_folder');
define('PLATHIX_MAX_DEPTH', 0); // 0 = unlimited; set via plathix/folder_depth_limit filter to enforce a cap
define('PLATHIX_TAX_PREFIX', 'plathix_folder_');
// WordPress limits taxonomy names to 32 characters.
// With the fixed prefix below, the effective post type slug budget is 14 chars.
define('PLATHIX_TERM_POSITION', 'plathix_position');
define('PLATHIX_TERM_COLOR', 'plathix_color');
define('PLATHIX_TEMP_DIR', 'plathix-temp');

// [internal]. Раньше здесь была развилка `dist/vendor/autoload.php` → иначе
// `vendor/autoload.php`: предполагалось, что изолированный (префиксованный) vendor лежит
// отдельным каталогом и подхватывается в рантайме. Развилка убрана как неверный владелец —
// выбор автолоада принадлежит СБОРКЕ, а не точке входа плагина:
//
// 1. `dist/vendor` не создавался никогда и ни одним путём (скрипт `build:vendor-scope` не
//    вызывался ни из bin/*.sh, ни из CI), то есть ветка была мёртвой с первого коммита;
// 2. ветка была ЭКСКЛЮЗИВНОЙ (if/elseif): подхват `dist/vendor` отключил бы основной
//    `vendor/autoload.php` вместе с `autoload.files`, а там живёт entrypoint Action
//    Scheduler — его потребители есть и во Free, и в PRO;
// 3. неполный `dist/vendor` не падал бы, а тихо отключал половину зависимостей (fail-open).
//
// Изоляция vendor теперь выполняется на стадии сборки артефакта
// (`scope_runtime_vendor()` в bin/build-test-zip.sh): в ZIP едет ровно один `vendor/`,
// уже префиксованный. Здесь — единственный источник автолоада, без ветвлений.
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
		// Onboarding first-run модалка (большой PRO-мастер) уехала в PRO ([internal]).
		// Малый Free-first-run визард (picker-overlay) — свой модуль
		// Plathix\Modules\FreeFirstRun ([internal], [internal]), ниже. Setup-блок
		// «Finish setup» остался во Free (переехал в Modules\Dashboard, [internal]).
		// Docs (раздел «Документация»: страница + 4 таба) уехал в PRO ([internal]):
		// регистрируется там под тем же хуком plathix/modules/register.
		// Инструментов из справочника во Free нет → раздела документации во Free нет вовсе.
		( new \Plathix\Modules\Preset\Module() )->register();
		( new \Plathix\Modules\FreeFirstRun\Module() )->register();
		( new \Plathix\Modules\Replace\Module() )->register();
		// Корзина (системная папка Trash в дереве): создание term + slug-реестр —
		// надстройка-просмотр ([internal]). Действия trash/restore = платформа.
		( new \Plathix\Modules\Trash\Module() )->register();
		// Gallery уехал в PRO (PRO, [internal]): регистрируется там под тем
		// же хуком plathix/modules/register. Без PRO шорткод/блок/страница галереи отсутствуют.
		( new \Plathix\Modules\Svg\Module() )->register();
		// Danger Zone (полная очистка данных) — автономный модуль: UI-таб через фильтр
		// settings_tabs поздним приоритетом ([internal] / migration-loop DataWipe T1).
		( new \Plathix\Modules\DataWipe\Module() )->register();
		( new \Plathix\Modules\Import\Module() )->register();
		( new \Plathix\Modules\Rest\Module() )->register();
		// Cli уехал в PRO ([internal]): 11 команд регистрируются add-on'ом через тот же
		// хук plathix/modules/register. Без PRO CLI-команд plathix нет (WP_CLI-guard + нет подписчика).
		// ApiKey (REST-аутентификация по сервис-токенам plxst_) уехал в PRO ([internal]):
		// регистрируется add-on'ом через plathix/modules/register. Без PRO REST-доступа по API-ключу нет.
		// Access (тонкая per-role/per-user политика прав поверх cap-дефолта движка) уехал в PRO
		// ([internal]): регистрируется add-on'ом через plathix/modules/register. Без PRO
		// подписчика фильтра plathix/user/access_level нет → движок Free отдаёт грубый cap-дефолт, не падает.
		( new \Plathix\Modules\Upload\Module() )->register();
		( new \Plathix\Modules\Settings\Module() )->register();
		( new \Plathix\Modules\Multilingual\Module() )->register();
		// ZIP-download (скачать папку архивом: REST-генерация + AJAX-отдача + JS) уехал в PRO
		// (PRO, [internal]): бывшие Modules\Download + Modules\ZipDownload
		// регистрируются add-on'ом (PlathixPro\Modules\ZipDownload) через plathix/modules/register.
		( new \Plathix\Modules\SearchFilters\Module() )->register();
		// FolderUpload (загрузка с локального диска с воссозданием структуры папок) уехал в PRO
		// (PRO, [internal]): регистрируется add-on'ом через
		// plathix/modules/register. Без PRO загрузки папок с диска нет (кнопка скрыта флагом,
		// dropzone не байндится, overlay не рендерится).
		( new \Plathix\Modules\FolderColor\Module() )->register();
		// FolderInfo (размеры папок: кнопка тулбара + REST /folders/{id}/size + свой JS-бандл)
		// уехал в PRO ([internal]): регистрируется add-on'ом через plathix/modules/register
		// с инлайн-DI (new FolderSizeCalculator() + Cache::make()). Без PRO features.folderInfo не
		// выставляется → кнопка/размер скрыты, stub folderInfoLine в core store, дерево цело.
		// Access Override UI (форма на странице профиля юзера: per-user переопределение уровня
		// доступа) уехал в PRO ([internal], 2026-08-11): регистрируется
		// add-on'ом (PlathixPro\Modules\Access\UserProfileOverride) через plathix/modules/register.
		// Движок AccessResolver/AccessLevel остаётся здесь как платформа. Без PRO UI физически
		// не существует (не просто скрыт флагом) — WordPress.org Guideline 5, [internal].
		( new \Plathix\Modules\Favorites\Module() )->register();
	}
);

register_activation_hook(PLATHIX_FILE, [ 'Plathix\\Activator', 'run' ]);
register_deactivation_hook(PLATHIX_FILE, [ 'Plathix\\Deactivator', 'run' ]);

/**
 * Загрузка переводов Free ([internal], оставлено намеренно после повторной WP.org
 * находки — [internal]). load_plugin_textdomain() автоматически избыточен только для
 * плагинов, ЛИСТИНГ КОТОРЫХ в каталоге WordPress.org уже подтверждён — в этом случае
 * переводы подключаются централизованно с translate.wordpress.org. Plathix на момент
 * этого комментария ещё не listed (submission на review), и все текущие живые
 * инсталляции (прод-клиенты и стенд) деплоятся вручную (unzip), не через каталог —
 * без этого вызова .mo не подключался к рантайму независимо от полноты
 * languages/plathix-ru_RU.po ([internal] воспроизвёл регресс живьём). Когда плагин
 * будет реально listed на WordPress.org, это условие изменится и вызов можно будет
 * убрать — до тех пор удаление регрессирует [internal].
 */
add_action(
	'init',
	static function (): void {
		load_plugin_textdomain( 'plathix', false, dirname( PLATHIX_BASENAME ) . '/languages' );
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		Plathix\Plugin::get_instance()->boot();
	}
);
