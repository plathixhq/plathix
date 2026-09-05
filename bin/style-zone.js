#!/usr/bin/env node

/**
 * [internal]: зона линтинга CSS вычисляется из кода, а не из списка путей.
 *
 * Причина существования этого файла: `lint:css` был прибит к каталогу `resources/css`, а
 * владение CSS в проекте модульное (`module-frontend-standard.md`, «Правило размещения»).
 * 12 и 17 июля стили уехали к модулям, 11 августа завели линтер и указали ему старое место —
 * 8 файлов с 1226 нарушениями не проверялись никогда. Список путей устареет при следующем
 * выносе ровно так же, поэтому зона выводится из двух источников, оба живут в коде:
 *
 *   1. граф импортов webpack от entry-точек — стиль попадает в бандл только через
 *      `import '….css'`; механизм заимствован у `bin/check-js-coverage.js`, где та же
 *      задача («покрыт ли весь код проверкой») решена для JS;
 *   2. `wp_enqueue_style( …, PLATHIX_ASSETS_URL . 'css/<имя>.css' )` — standalone-стили без
 *      JS-владельца (`plathix.css`, `attachment-fields.css`), которые в граф не входят.
 *
 * Единственный модуль на двух потребителей — тест `StyleZoneCoverageTest` и обёртка
 * `bin/lint-css.js`: два экземпляра логики разъехались бы, и тест начал бы доказывать не то,
 * что проверяет линтер.
 */

const fs = require('fs');
const path = require('path');

const ROOT_DIR = path.resolve(__dirname, '..');
const SOURCE_EXTENSIONS = ['.js', '.jsx', '.mjs', '.cjs'];

/** Каталоги, где лежат исходники стилей. `assets/` — сборочный выход, не исходник. */
const SOURCE_ROOTS = ['resources', 'src'];

function normalizeEntries(entryConfig) {
    return Object.values(entryConfig)
        .flatMap((value) => (Array.isArray(value) ? value : [value]))
        .map((value) => path.resolve(ROOT_DIR, value));
}

/**
 * Только относительные пути. Внешние пакеты (`photoswipe/style.css`) отсеиваются здесь же:
 * они не наши, линтить их нельзя, и отдельный список исключений для этого не нужен.
 */
function resolveImport(fromFile, request) {
    if (!request.startsWith('.')) {
        return null;
    }

    const basePath = path.resolve(path.dirname(fromFile), request);
    const candidates = [
        basePath,
        ...SOURCE_EXTENSIONS.map((ext) => `${basePath}${ext}`),
        ...SOURCE_EXTENSIONS.map((ext) => path.join(basePath, `index${ext}`)),
    ];

    for (const candidate of candidates) {
        if (fs.existsSync(candidate) && fs.statSync(candidate).isFile()) {
            return candidate;
        }
    }

    return null;
}

/** Три формы записи, взяты из `bin/check-js-coverage.js` — уже проверены на этом дереве. */
function extractImports(source) {
    const requests = new Set();
    const patterns = [
        /import\s+(?:[^'"]+?\s+from\s+)?['"]([^'"]+)['"]/g,
        /export\s+[^'"]+?\s+from\s+['"]([^'"]+)['"]/g,
        /import\(\s*['"]([^'"]+)['"]\s*\)/g,
    ];

    for (const pattern of patterns) {
        let match;
        while ((match = pattern.exec(source)) !== null) {
            requests.add(match[1]);
        }
    }

    return [...requests];
}

/** Источник 1: обход графа импортов от webpack-entry, сбор `.css` по дороге. */
function collectFromImportGraph() {
    const webpackConfig = require(path.join(ROOT_DIR, 'webpack.config.js'));
    const styles = new Set();
    const visited = new Set();
    const queue = normalizeEntries(webpackConfig.entry);

    while (queue.length > 0) {
        const currentFile = queue.shift();
        if (visited.has(currentFile) || !fs.existsSync(currentFile)) {
            continue;
        }
        visited.add(currentFile);

        for (const request of extractImports(fs.readFileSync(currentFile, 'utf8'))) {
            if (request.endsWith('.css')) {
                if (!request.startsWith('.')) {
                    continue; // внешний пакет — не наш стиль
                }
                const stylePath = path.resolve(path.dirname(currentFile), request);
                if (fs.existsSync(stylePath)) {
                    styles.add(path.relative(ROOT_DIR, stylePath));
                }
                continue;
            }

            const next = resolveImport(currentFile, request);
            if (next) {
                queue.push(next);
            }
        }
    }

    return styles;
}

function listPhpFiles(dirPath) {
    const results = [];
    if (!fs.existsSync(dirPath)) {
        return results;
    }

    for (const entry of fs.readdirSync(dirPath, { withFileTypes: true })) {
        const fullPath = path.join(dirPath, entry.name);
        if (entry.isDirectory()) {
            results.push(...listPhpFiles(fullPath));
        } else if (entry.isFile() && fullPath.endsWith('.php')) {
            results.push(fullPath);
        }
    }

    return results;
}

/**
 * Источник 2: `wp_enqueue_style`/`wp_register_style` со сборочным URL.
 *
 * PHP энкьюит собранный файл (`PLATHIX_ASSETS_URL . 'css/plathix.css'`), а линтить надо
 * исходник — соответствие `assets/css/<имя>.css` → `resources/css/<имя>.css` проверяется
 * существованием файла, а не предполагается.
 */
function collectFromPhpEnqueue() {
    const styles = new Set();
    // Обе формы пути: Free энкьюит `PLATHIX_ASSETS_URL . 'css/x.css'` (константа уже
    // содержит `assets/`), PRO — `PLATHIX_PRO_URL . 'assets/css/x.css'`. Паттерн только на
    // одну форму терял бы половину стилей соседнего репозитория при переносе модуля.
    const pattern = /(?:wp_enqueue_style|wp_register_style)\s*\([^)]*?['"](?:assets\/)?css\/([a-z0-9-]+\.css)['"]/gs;

    for (const phpFile of listPhpFiles(path.join(ROOT_DIR, 'src'))) {
        const source = fs.readFileSync(phpFile, 'utf8');
        let match;
        while ((match = pattern.exec(source)) !== null) {
            const candidate = path.join(ROOT_DIR, 'resources', 'css', match[1]);
            if (fs.existsSync(candidate)) {
                styles.add(path.relative(ROOT_DIR, candidate));
            }
        }
    }

    return styles;
}

/** Все `.css` в исходных каталогах — для обратной проверки «в зоне, но никем не подключён». */
function listAllSourceStyles() {
    const results = [];

    function walk(dirPath) {
        if (!fs.existsSync(dirPath)) {
            return;
        }
        for (const entry of fs.readdirSync(dirPath, { withFileTypes: true })) {
            const fullPath = path.join(dirPath, entry.name);
            if (entry.isDirectory()) {
                if (entry.name === 'node_modules' || entry.name === 'SRC') {
                    continue; // SRC — дизайн-макеты, в плагин не собираются
                }
                walk(fullPath);
            } else if (entry.isFile() && fullPath.endsWith('.css')) {
                results.push(path.relative(ROOT_DIR, fullPath));
            }
        }
    }

    SOURCE_ROOTS.forEach((root) => walk(path.join(ROOT_DIR, root)));
    return results.sort();
}

/** Зона линтинга: объединение обоих источников, отсортировано для стабильного вывода. */
function styleZone() {
    return [...new Set([...collectFromImportGraph(), ...collectFromPhpEnqueue()])].sort();
}

module.exports = { styleZone, listAllSourceStyles, ROOT_DIR };

if (require.main === module) {
    styleZone().forEach((file) => console.log(file));
}
