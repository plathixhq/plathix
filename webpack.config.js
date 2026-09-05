const defaultConfig = require('@wordpress/scripts/config/webpack.config');
const path = require('path');
// webpack резолвим ЧЕРЕЗ @wordpress/scripts, а не напрямую: он транзитивная зависимость и
// при pnpm-изоляции в node_modules корня отсутствует (`require('webpack')` → MODULE_NOT_FOUND).
const webpack = require(require.resolve('webpack', {
    paths: [require.resolve('@wordpress/scripts/config/webpack.config')],
}));

// Атрибуция bundled-библиотек (DH-106, [internal]). MIT требует сохранять уведомление об
// авторстве в поставляемых копиях — условие лицензии, и Guideline 4 WP.org проверяется
// ревьювером прямо. Проверено: ни один из 24 JS в поставке Free не нёс ни одной шапки.
//
// Список построен по СОДЕРЖИМОМУ бандлов, а не по package.json: `dependencies` объявляет и
// `photoswipe`, но во Free он никуда не импортируется, в бандлах отсутствует и в архив не
// попадает (Gallery уехал в PRO на [internal]) — атрибуция ему здесь не нужна.
const BUNDLED_LICENSES = {
    // Alpine.js 3.16.1 — MIT, Caleb Porzio, https://alpinejs.dev
    alpine: '! @license Alpine.js v3.16.1 | (c) Caleb Porzio | MIT License | https://alpinejs.dev',
};

// Какая библиотека в каком бандле — проверено `rg -l` по собранным файлам.
const BANNER_BY_ENTRY = {
    sidebar: [BUNDLED_LICENSES.alpine],
};

// Terser в @wordpress/scripts настроен как `output.comments: /translators:/i`
// (config/webpack.config.js:151) — сохраняет ТОЛЬКО комментарии со словом `translators` и
// срезает всё прочее, включая лицензионные `/*! ... */`. Явная регулярка перекрывает
// конвенцию «ведущий `!` = важный комментарий», поэтому BannerPlugin сам по себе бесполезен:
// он пишет баннер ДО минимизации, и terser его съедает (проверено прогоном в PRO).
//
// Расширяем регулярку, а не заменяем: `translators:` обязан продолжать выживать — это
// i18n-контракт WP, комментарии для переводчиков читает make-pot.
const minimizer = (defaultConfig.optimization?.minimizer || []).map((plugin) => {
    if (plugin?.constructor?.name !== 'TerserPlugin') {
        return plugin;
    }

    const options = plugin.options || {};
    // terser-опции живут в `options.minimizer.options`, а НЕ в `options.terserOptions`
    // (проверено интроспекцией). Чтение не оттуда молча теряет `compress.passes: 2` и
    // `mangle.reserved: ['__','_n','_nx','_x']`; последнее защищает имена i18n-функций WP
    // от мангла, его потеря = сломанные переводы в рантайме. Меняем строго одно поле.
    const inner = options.minimizer?.options || {};

    return new plugin.constructor({
        test: options.test,
        include: options.include,
        exclude: options.exclude,
        parallel: options.parallel,
        extractComments: options.extractComments,
        minify: options.minimizer?.implementation,
        terserOptions: {
            ...inner,
            output: {
                ...inner.output,
                comments: /translators:|@license/i,
            },
        },
    });
});

const plugins = (defaultConfig.plugins || [])
    // RtlCssPlugin отключён: RTL — отложенная фича ([internal], [internal]), сейчас
    // не enqueue-ится нигде. Включить обратно вместе с реализацией #88 (с выводом в
    // assets/css/, не в корень assets/ — см. scope issue).
    .filter((plugin) => plugin?.constructor?.name !== 'RtlCssPlugin')
    .map((plugin) => {
        if (plugin?.constructor?.name === 'MiniCssExtractPlugin') {
            return new plugin.constructor({
                ...plugin.options,
                filename: 'css/[name].css',
                chunkFilename: 'css/[name].css',
            });
        }

        return plugin;
    });

plugins.push(
    new webpack.BannerPlugin({
        banner: ({ chunk }) => {
            const lines = BANNER_BY_ENTRY[chunk.name];

            return lines ? lines.join('\n') : '';
        },
        entryOnly: false,
        // Только JS: иначе плагин пишет ПУСТУЮ первую строку в каждый CSS-ассет — у
        // CSS-чанка нет записи в BANNER_BY_ENTRY, banner() возвращает '', и webpack всё
        // равно ставит перевод строки (проверено в PRO: 22 лишних файла в diff).
        test: /\.js$/,
        // Фильтруем по имени ЧАНКА внутри banner(), а не опцией `test`: она матчится на имя
        // файла с учётом output-префикса (`js/[name].js`) — регулярка по `^js/...` не
        // срабатывала (проверено прогоном).
    })
);

// CSS-only entries (free-wizard, propage, tools — см. entry ниже) порождают пустой
// JS-выход: standalone.js импортирует только CSS, MiniCssExtractPlugin извлекает стиль,
// а js/[name].js эмитится 0-байтовым и ехал в поставку ([internal], [internal]).
// Удаляем такие эмиты из компиляции по факту «итоговый JS-ассет пуст» — БЕЗ списка имён:
// комментарии-списки врут (в PRO entry content-types помечен «CSS-only», а несёт живой
// адаптер), а новый CSS-only entry обязан подавляться сам, без ручного шага.
// Стадия строго REPORT (5000): DependencyExtractionWebpackPlugin эмитит .asset.php и
// строит его version-хеш на ANALYSE (4000) из буферов файлов чанка, включая CSS — потому
// хеш живой и меняется от правки стиля; удаление JS раньше ANALYSE = падение getAsset
// либо сдвиг хешей всех .asset.php (красный derived-sync). Сами .asset.php намеренно
// ОСТАЮТСЯ в эмите: из них страницы берут version для wp_enqueue_style — cache-busting
// CSS без bump версии плагина (регресс-класс AttachmentReplaceUiTest).
class RemoveEmptyJsEmitPlugin {
    apply(compiler) {
        compiler.hooks.thisCompilation.tap('RemoveEmptyJsEmitPlugin', (compilation) => {
            compilation.hooks.processAssets.tap(
                {
                    name: 'RemoveEmptyJsEmitPlugin',
                    stage: webpack.Compilation.PROCESS_ASSETS_STAGE_REPORT,
                },
                (assets) => {
                    for (const name of Object.keys(assets)) {
                        if (name.endsWith('.js') && assets[name].size() === 0) {
                            compilation.deleteAsset(name);
                        }
                    }
                }
            );
        });
    }
}

plugins.push(new RemoveEmptyJsEmitPlugin());

module.exports = {
    ...defaultConfig,
    optimization: {
        ...defaultConfig.optimization,
        minimizer,
    },
    entry: {
        'admin-ui': path.resolve(__dirname, 'resources/js/admin-ui.js'),
        // Preset JS атомизирован в свой entry ([internal]): grузится
        // только на странице Presets через PresetsPage::enqueue_scripts.
        // Исходник co-located с PHP-модулем ([internal]): src/Modules/Preset/assets/preset.js
        'admin-ui/preset': path.resolve(__dirname, 'src/Modules/Preset/assets/preset.js'),
        // Settings JS атомизирован в свой entry ([internal]): грузится
        // только на странице Settings через SettingsPage::enqueue_scripts.
        // Исходник co-located с PHP-модулем ([internal]): src/Modules/Settings/assets/settings.js
        'admin-ui/settings': path.resolve(__dirname, 'src/Modules/Settings/assets/settings.js'),
        // Dashboard JS атомизирован в свой entry ([internal]): грузится
        // только на Dashboard через HomeDashboardPage::render().
        // Исходник co-located с PHP-модулем ([internal]): src/Modules/Dashboard/assets/dashboard.js
        'admin-ui/dashboard': path.resolve(__dirname, 'src/Modules/Dashboard/assets/dashboard.js'),
        // SystemInfo JS атомизирован в свой entry ([internal]): грузится
        // только на SystemInfo через SystemInfoPage::enqueue_scripts.
        // Исходник co-located с PHP-модулем ([internal]): src/Modules/SystemInfo/assets/system-info.js
        'admin-ui/system-info': path.resolve(__dirname, 'src/Modules/SystemInfo/assets/system-info.js'),
        'admin-menu': path.resolve(__dirname, 'resources/js/admin-menu.js'),
        // Публикует escapeHtml/escapeAttr как window.PlathixEscape для PRO-потребителей
        // через runtime WP script dependency ([internal], [internal]).
        // Отдельный entry, не импорт: PRO webpack build не видит Free-исходники (см.
        // spec/_done/[internal]), функция читается через глобал.
        'lib/escape-shared': path.resolve(__dirname, 'resources/js/lib/escape-shared.js'),
        // Публикует restRequest/postType/parseJson/refreshNonce как window.PlathixTransport
        // для PRO-потребителей через runtime WP script dependency ([internal],
        // [internal]). Отдельный entry, не импорт: тот же паттерн, что
        // lib/escape-shared выше — PRO webpack build не видит Free-исходники.
        'lib/transport-shared': path.resolve(__dirname, 'resources/js/lib/transport-shared.js'),
        sidebar: path.resolve(__dirname, 'resources/js/sidebar/index.js'),
        // settings entry удалён ([internal]): весь import-JS переехал в resources/js/import/.
        // Enqueue теперь в Modules\Import\ImportEnqueueService, грузится только на Tools.
        import: path.resolve(__dirname, 'resources/js/import/index.js'),
        'media-upload': path.resolve(__dirname, 'resources/js/media-upload.js'),
        // Gallery-entry (blocks/gallery, lightbox, shortcode-builder) уехали в PRO-сборку
        // (plathix-pro/webpack.config.js, [internal]). Free их больше не собирает.
        'replace-media': path.resolve(__dirname, 'resources/js/replace/standalone.js'),
        // Фича смены папки прямо из split-control поля "Папка" (модалка + страница
        // вложения), атомизирована в свой Free entry, аналогично replace-media.
        'folder-switch': path.resolve(__dirname, 'resources/js/folder-switch/standalone.js'),
        // zip entry уехал в PRO ([internal]): бандл собирается в plathix-pro.
        'search': path.resolve(__dirname, 'resources/js/sidebar/search-entry.js'),
        // Фича цвета папки атомизирована в свой Free entry ([internal]): пикер +
        // показ + самомонтаж в контекст-меню. Правка цвета = только модуль color/. Вынос в PRO
        // (если продукт решит) = mv папки + перенос этого entry в PRO-webpack + условие монтажа.
        'color': path.resolve(__dirname, 'resources/js/sidebar/color/color-entry.js'),
        // Фича корзины папок атомизирована в свой Free entry ([internal]): кнопки
        // «Move to Trash»/«Restore» + overlays + панели папок. Правка trash-UI = только trash/.
        // Вынос в PRO (если продукт решит) = mv trash/ + этот entry в PRO-webpack.
        'trash': path.resolve(__dirname, 'resources/js/sidebar/trash/trash-entry.js'),
        // Фича избранных папок атомизирована в свой Free entry ([internal]): блок
        // избранных + store-логика. Правка favorites-UI = только favorites/. Вынос в PRO
        // (если продукт решит) = mv favorites/ + этот entry в PRO-webpack + условие монтажа.
        'favorites': path.resolve(__dirname, 'resources/js/sidebar/favorites/favorites-entry.js'),
        // Free first-run визард: CSS-only entry ([internal], #113). standalone.js
        // импортит свой free-wizard.css → MiniCss извлекает assets/css/free-wizard.css, который
        // FreeFirstRun\WizardAssets грузит только на Dashboard (вынос из общего admin-ui.css).
        // Имя entry == basename css (free-wizard) → build-assets docopy-цикл не задвоит.
        'free-wizard': path.resolve(__dirname, 'resources/js/free-wizard/standalone.js'),
        // ProPage: CSS-only entry ([internal], #113). standalone.js импортит свой
        // propage.css → MiniCss извлекает assets/css/propage.css, который Modules\Pro\ProPageAssets
        // грузит только на странице ProPage (вынос из общего admin-ui.css). У ProPage нет JS-entry
        // (серверная), поэтому CSS-only entry как free-wizard. Имя == basename css → docopy не задвоит.
        'propage': path.resolve(__dirname, 'resources/js/propage/standalone.js'),
        // Tools: CSS-only entry ([internal]), зеркало propage/free-wizard.
        // standalone.js импортит свой tools.css → MiniCss извлекает assets/css/tools.css,
        // который Modules\Tools\ToolsPage грузит только на странице Tools (вынос из
        // общего admin-ui.css). У ToolsPage нет JS-entry (серверная), поэтому
        // CSS-only entry. Имя == basename css → docopy не задвоит.
        'tools': path.resolve(__dirname, 'resources/js/tools/standalone.js'),
        // folder-info entry уехал в PRO ([internal]): бандл собирается в plathix-pro.
        // folder-upload entry уехал в PRO ([internal]): бандл собирается в plathix-pro.
    },
    output: {
        ...defaultConfig.output,
        path: path.resolve(__dirname, 'assets'),
        filename: 'js/[name].js',
        chunkFilename: 'js/[name].js',
        clean: {
            // [internal]: реальная директория называется img/, не images/ — regex никогда
            // не совпадал с ней, из-за чего прямой webpack-прогон удалял assets/img/placeholder.webp
            // (последний tier fallback preset-export preview, [internal]). fonts/ физически не
            // существует (мёртвая безвредная альтернатива), presets/ реально нужен (9 built-in
            // preset-папок) — оба оставлены нетронутыми, минимальный диф.
            keep: /^(fonts|img|presets)\//,
        },
    },
    plugins,
};
