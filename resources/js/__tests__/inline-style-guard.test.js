const fs = require('fs');
const path = require('path');




const ROOT = path.resolve(__dirname, '..', '..', '..');
const SCAN_DIR = path.join(ROOT, 'resources', 'js');




const STYLE_PROP_PATTERN = /\.style\.(?!cssText\b)[a-zA-Z][a-zA-Z0-9]*\s*=(?![=])/g;
const STYLE_CSSTEXT_PATTERN = /\.style\.cssText\s*=(?![=])/g;



const BASELINE = {
	'folder-switch/folder-switch-ui.js': 7,
	'replace/replace-media-ui.js': 4,
	'admin-ui/shortcodes-copy.js': 2,
	'admin-ui/rail-toggle.js': 2,
	'sidebar/resize.js': 9,
	'sidebar/mount-manager.js': 1,
};

function scanFiles() {
	const files = [];

	function walk(dir) {
		for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
			if (entry.name === '__tests__') {
				continue;
			}
			const full = path.join(dir, entry.name);
			if (entry.isDirectory()) {
				walk(full);
			} else if (entry.isFile() && entry.name.endsWith('.js')) {
				files.push(full);
			}
		}
	}

	walk(SCAN_DIR);
	return files.sort();
}

function countInlineStyleAssignments() {
	const counts = {};

	for (const file of scanFiles()) {
		const contents = fs.readFileSync(file, 'utf8');
		const propHits = contents.match(STYLE_PROP_PATTERN) || [];
		const cssTextHits = contents.match(STYLE_CSSTEXT_PATTERN) || [];
		const hits = propHits.length + cssTextHits.length;

		if (hits > 0) {
			const rel = path.relative(SCAN_DIR, file).split(path.sep).join('/');
			counts[rel] = hits;
		}
	}

	return counts;
}

describe('inline style guard (JS)', () => {
	it('does not allow new .style.<prop> = / .style.cssText = assignments beyond baseline', () => {
		const actual = countInlineStyleAssignments();

		for (const [file, count] of Object.entries(actual)) {
			const allowed = BASELINE[file] || 0;




			expect(count).toBeLessThanOrEqual(allowed);
		}
	});

	it('baseline has no stale entries (fewer assignments than recorded means BASELINE is outdated)', () => {
		const actual = countInlineStyleAssignments();

		for (const [file, expected] of Object.entries(BASELINE)) {
			const current = actual[file] || 0;



			expect(current).toBe(expected);
		}
	});

	it.each([
		['.style.display = ', "el.style.display = 'none';", 1],
		['.style.cssText =', 'el.style.cssText = "position:fixed";', 1],
		['Public-facing message unavailable.', 'el.style.setProperty("--x", 1);', 0],
		['Public-facing message unavailable.', "el.classList.toggle('is-hidden');", 0],
		['Public-facing message unavailable.', "el.className = 'is-hidden';", 0],
		['Public-facing message unavailable.', "el.style.opacity += 1;", 0],
		['Public-facing message unavailable.', "if (el.style.display == 'none') {}", 0],
	])('pattern verdict: %s', (_label, code, expected) => {
		const propHits = code.match(STYLE_PROP_PATTERN) || [];
		const cssTextHits = code.match(STYLE_CSSTEXT_PATTERN) || [];
		expect(propHits.length + cssTextHits.length).toBe(expected);
	});
});
