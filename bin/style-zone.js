#!/usr/bin/env node




const fs = require('fs');
const path = require('path');

const ROOT_DIR = path.resolve(__dirname, '..');
const SOURCE_EXTENSIONS = ['.js', '.jsx', '.mjs', '.cjs'];


const SOURCE_ROOTS = ['resources', 'src'];

function normalizeEntries(entryConfig) {
    return Object.values(entryConfig)
        .flatMap((value) => (Array.isArray(value) ? value : [value]))
        .map((value) => path.resolve(ROOT_DIR, value));
}



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
                    continue;
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



function collectFromPhpEnqueue() {
    const styles = new Set();



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
                    continue;
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


function styleZone() {
    return [...new Set([...collectFromImportGraph(), ...collectFromPhpEnqueue()])].sort();
}

module.exports = { styleZone, listAllSourceStyles, ROOT_DIR };

if (require.main === module) {
    styleZone().forEach((file) => console.log(file));
}
