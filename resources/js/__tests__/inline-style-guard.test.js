const fs = require('fs');
const path = require('path');

/**
 * [internal]: PHP-сторону inline-стилей стережёт InlineStyleGuardTest
 * (tests/InlineStyleGuardTest.php), JS-сторону — ничто. За время без этой проверки
 * JS-пакет [internal] выносил `.style.display =`/`.style.cssText =` в классы, но
 * ничто не мешало следующему PR вернуть их обратно незамеченным. Этот гейт — тот
 * же source-scan подход, что и PHP-версия: baseline существующих легитимных
 * присваиваний (координаты, opacity-transition, размеры), красный на новых сверх
 * baseline.
 *
 * Что НЕ ловится намеренно: `.style.setProperty(...)`, `+=`/`-=` на style-свойстве,
 * `.className =`/`.classList.*` — это ровно то, во что вынос превращает
 * static/toggle присваивания, и новый код обязан этим пользоваться.
 */

const ROOT = path.resolve(__dirname, '..', '..', '..');
const SCAN_DIR = path.join(ROOT, 'resources', 'js');

// Простое `=`, не `==`/`===`/`+=`/`-=` — за style-свойством следует ровно один `=`
// без соседних `=`/`+`/`-`. cssText исключён из общего паттерна отрицательным
// lookahead — иначе `.style.cssText =` матчится обоими паттернами разом (двойной счёт).
const STYLE_PROP_PATTERN = /\.style\.(?!cssText\b)[a-zA-Z][a-zA-Z0-9]*\s*=(?![=])/g;
const STYLE_CSSTEXT_PATTERN = /\.style\.cssText\s*=(?![=])/g;

/**
 * Снимок на 2026-08-20: файл => число `.style.<prop> =` / `.style.cssText =`
 * присваиваний вне __tests__. Все текущие — динамические значения (координаты,
 * opacity-transition, вычисляемые размеры), не static/toggle разметка.
 */
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

			// Новое inline-присваивание сверх baseline: static/toggle-стили выносятся
			// в CSS-класс (classList.toggle/add), не в .style.<prop>. Если это
			// динамическое значение — добавь в BASELINE с причиной.
			expect(count).toBeLessThanOrEqual(allowed);
		}
	});

	it('baseline has no stale entries (fewer assignments than recorded means BASELINE is outdated)', () => {
		const actual = countInlineStyleAssignments();

		for (const [file, expected] of Object.entries(BASELINE)) {
			const current = actual[file] || 0;

			// Если тут стало меньше присваиваний, чем в BASELINE — вынос состоялся,
			// обнови BASELINE в этом тесте (устаревший снимок разрешает откат незамеченным).
			expect(current).toBe(expected);
		}
	});

	it.each([
		['.style.display = ', "el.style.display = 'none';", 1],
		['.style.cssText =', 'el.style.cssText = "position:fixed";', 1],
		['setProperty не считается', 'el.style.setProperty("--x", 1);', 0],
		['classList не считается', "el.classList.toggle('is-hidden');", 0],
		['className не считается', "el.className = 'is-hidden';", 0],
		['+= не считается', "el.style.opacity += 1;", 0],
		['== не считается присваиванием', "if (el.style.display == 'none') {}", 0],
	])('pattern verdict: %s', (_label, code, expected) => {
		const propHits = code.match(STYLE_PROP_PATTERN) || [];
		const cssTextHits = code.match(STYLE_CSSTEXT_PATTERN) || [];
		expect(propHits.length + cssTextHits.length).toBe(expected);
	});
});
