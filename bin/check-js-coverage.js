#!/usr/bin/env node

const fs = require('fs');
const path = require('path');

const ROOT_DIR = path.resolve(__dirname, '..');
const JS_ROOT = path.join(ROOT_DIR, 'resources', 'js');
const webpackConfig = require(path.join(ROOT_DIR, 'webpack.config.js'));

const ENTRY_EXTENSIONS = ['.js', '.jsx', '.mjs', '.cjs'];
const IGNORE_SEGMENTS = new Set(['__tests__']);
const IGNORE_SUFFIXES = ['.test.js', '.spec.js', '.d.ts'];
const IGNORE_FILES = new Set([
    'sidebar/store/test-base.js',         // test-only base module, not a webpack entry
    'admin-ui/copy-utils.js',             // shared util imported by co-located modules in src/Modules/; not a direct entry
]);

function normalizeEntries(entryConfig) {
    return Object.values(entryConfig)
        .flatMap((value) => Array.isArray(value) ? value : [value])
        .map((value) => path.resolve(ROOT_DIR, value));
}

function shouldIgnoreFile(filePath) {
    const relativePath = path.relative(JS_ROOT, filePath);
    const parts = relativePath.split(path.sep);

    if (parts.some((segment) => IGNORE_SEGMENTS.has(segment))) {
        return true;
    }

    if (IGNORE_FILES.has(relativePath.split(path.sep).join('/'))) {
        return true;
    }

    return IGNORE_SUFFIXES.some((suffix) => relativePath.endsWith(suffix));
}

function listFiles(dirPath) {
    const results = [];

    for (const entry of fs.readdirSync(dirPath, { withFileTypes: true })) {
        const fullPath = path.join(dirPath, entry.name);

        if (entry.isDirectory()) {
            results.push(...listFiles(fullPath));
            continue;
        }

        if (!entry.isFile()) {
            continue;
        }

        if (!ENTRY_EXTENSIONS.some((ext) => fullPath.endsWith(ext))) {
            continue;
        }

        if (shouldIgnoreFile(fullPath)) {
            continue;
        }

        results.push(fullPath);
    }

    return results;
}

function resolveImport(fromFile, request) {
    if (!request.startsWith('.')) {
        return null;
    }

    const basePath = path.resolve(path.dirname(fromFile), request);
    const candidates = [
        basePath,
        ...ENTRY_EXTENSIONS.map((ext) => `${basePath}${ext}`),
        ...ENTRY_EXTENSIONS.map((ext) => path.join(basePath, `index${ext}`)),
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

function collectReachableFiles(entryFiles) {
    const visited = new Set();
    const queue = [...entryFiles];

    while (queue.length > 0) {
        const currentFile = queue.shift();
        if (visited.has(currentFile)) {
            continue;
        }

        visited.add(currentFile);

        const source = fs.readFileSync(currentFile, 'utf8');
        const requests = extractImports(source);

        for (const request of requests) {
            const resolved = resolveImport(currentFile, request);
            if (!resolved || visited.has(resolved) || shouldIgnoreFile(resolved)) {
                continue;
            }

            queue.push(resolved);
        }
    }

    return visited;
}

const entryFiles = normalizeEntries(webpackConfig.entry || {});
const reachableFiles = collectReachableFiles(entryFiles);
const allJsFiles = listFiles(JS_ROOT);
const uncoveredFiles = allJsFiles
    .filter((filePath) => !reachableFiles.has(filePath))
    .map((filePath) => path.relative(ROOT_DIR, filePath))
    .sort();

if (uncoveredFiles.length > 0) {
    console.error('Uncovered runtime JS files in resources/js:');
    for (const filePath of uncoveredFiles) {
        console.error(`- ${filePath}`);
    }
    process.exit(1);
}

console.log(`JS coverage OK: ${allJsFiles.length} runtime files reachable from ${entryFiles.length} entrypoints.`);
