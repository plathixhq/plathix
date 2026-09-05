#!/usr/bin/env node

/**
 * Обёртка `lint:css`: запускает `wp-scripts lint-style` по зоне, вычисленной из кода.
 *
 * Зона берётся из `bin/style-zone.js` — того же модуля, что читает `StyleZoneCoverageTest`.
 * Единственный источник на линтер и тест: генерируемый файл со списком разъехался бы с
 * деревом, а glob не выражает «подключён ли стиль» и захватывал бы мёртвые файлы.
 */

const { spawnSync } = require('child_process');
const path = require('path');
const { styleZone, ROOT_DIR } = require('./style-zone.js');

const zone = styleZone();

if (zone.length === 0) {
    console.error('lint:css: зона пуста — вероятно сломан webpack.config.js или обход графа.');
    process.exit(1);
}

const binary = path.join(ROOT_DIR, 'node_modules', '@wordpress', 'scripts', 'bin', 'wp-scripts.js');
const result = spawnSync(process.execPath, [binary, 'lint-style', ...zone, ...process.argv.slice(2)], {
    cwd: ROOT_DIR,
    stdio: 'inherit',
});

process.exit(result.status === null ? 1 : result.status);
