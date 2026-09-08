#!/usr/bin/env node




const { spawnSync } = require('child_process');
const path = require('path');
const { styleZone, ROOT_DIR } = require('./style-zone.js');

const zone = styleZone();

if (zone.length === 0) {
    console.error('CSS lint zone is empty; check webpack.config.js and graph traversal.');
    process.exit(1);
}

const binary = path.join(ROOT_DIR, 'node_modules', '@wordpress', 'scripts', 'bin', 'wp-scripts.js');
const result = spawnSync(process.execPath, [binary, 'lint-style', ...zone, ...process.argv.slice(2)], {
    cwd: ROOT_DIR,
    stdio: 'inherit',
});

process.exit(result.status === null ? 1 : result.status);
