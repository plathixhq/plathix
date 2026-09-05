<?php

/**
 * Конвертер пресетов из старого формата (preset.md, сквозная нумерация) в новый
 * (preset.plx.md, отступы + кавычки).
 *
 * Существует, потому что 9 built-in пресетов и тестовые фикстуры записаны в
 * грамматике, которую парсер больше не понимает. Ручная правка 9 файлов
 * механическая и легко даёт опечатку в структуре, которую тесты поймают не сразу
 * — конвертер делает это детерминированно и валидирует результат перед записью.
 *
 * Usage:
 *   php bin/convert-preset-format.php <path-to-preset.md|directory> [--delete-source] [--dry-run]
 *
 * Directory обходится рекурсивно: конвертируется каждый найденный preset.md.
 *
 * @package Plathix
 */

declare(strict_types=1);

// Скрипт работает с текстом и не грузит WordPress: gettext-функции, которые
// зовёт валидатор, здесь подменяются no-op заглушками.
if ( ! function_exists('__') ) {
	function __(string $text, string $domain = 'default'): string {
		return $text;
	}
}
if ( ! function_exists('sanitize_key') ) {
	function sanitize_key(string $key): string {
		return preg_replace('/[^a-z0-9_\-]/', '', strtolower($key)) ?? '';
	}
}

require_once __DIR__ . '/../src/Modules/Preset/PresetError.php';
require_once __DIR__ . '/../src/Modules/Preset/PresetFormat.php';
require_once __DIR__ . '/../src/Modules/Preset/PresetParser.php';
require_once __DIR__ . '/../src/Modules/Preset/PresetValidator.php';

use Plathix\Modules\Preset\PresetFormat;
use Plathix\Modules\Preset\PresetValidator;

/** Строка структуры старого формата: `1.2: Folder(Name): Color(x): Favorite(1)`. */
const LEGACY_LINE = '/^\s*([0-9]+(?:\.[0-9]+)*)\s*:\s*Folder\(([^)]*)\)\s*:\s*Color\(([^)]*)\)(?:\s*:\s*Favorite\(([^)]*)\))?\s*$/';

/**
 * Конвертирует содержимое одного preset.md.
 *
 * @return array{markdown: string, converted: bool}
 */
function convert_markdown(string $source): array {
	$lines       = preg_split("/\r\n|\n|\r/", $source) ?: [];
	$out         = [];
	$inStructure = false;
	$converted   = false;

	foreach ( $lines as $line ) {
		if ( ! $inStructure ) {
			$out[] = $line;
			if ( trim($line) === '## Structure' ) {
				$inStructure = true;
			}
			continue;
		}

		if ( trim($line) === '' ) {
			$out[] = '';
			continue;
		}

		if ( preg_match(LEGACY_LINE, $line, $m) !== 1 ) {
			// Уже новый формат (или строка, которую конвертер не понимает) —
			// отдаём как есть, решение о валидности примет валидатор.
			$out[] = $line;
			continue;
		}

		$converted = true;
		$depth     = substr_count($m[1], '.');
		$color     = trim($m[3]);
		$favorite  = ( $m[4] ?? '' ) === '1';

		$attributes = [];
		if ( $color !== 'default' && $color !== '' ) {
			$attributes[] = 'color: ' . $color;
		}
		if ( $favorite ) {
			$attributes[] = 'favorite';
		}

		$out[] = str_repeat('  ', $depth)
			. '- ' . PresetFormat::escape_name($m[2])
			. ( $attributes === [] ? '' : ' {' . implode(', ', $attributes) . '}' );
	}

	return [
		'markdown'  => implode("\n", $out),
		'converted' => $converted,
	];
}

/** @return array{ok: bool, message: string} */
function convert_file(string $path, bool $dry_run, bool $delete_source): array {
	$source = file_get_contents($path);
	if ( $source === false ) {
		return [ 'ok' => false, 'message' => 'не читается' ];
	}

	$result = convert_markdown($source);
	$target = dirname($path) . DIRECTORY_SEPARATOR . PresetFormat::FILENAME;

	// Решение о записи — по «результат отличается от того, что уже лежит на
	// месте назначения», а не по флагу преобразования грамматики. Флаг
	// сигнализирует только о конвертации структуры; файл, у которого структура
	// уже новая, но имя старое (или который ещё не существует под новым
	// именем), обязан быть записан — иначе конвертер тихо ничего не делает.
	$existing = is_file($target) ? file_get_contents($target) : false;
	if ( $existing === $result['markdown'] && realpath($path) === realpath($target) ) {
		return [ 'ok' => true, 'message' => 'пропущен (изменений нет)' ];
	}

	// Валидация ДО записи: конвертер не имеет права оставить на диске файл,
	// который плагин не примет. Sibling (PresetExportPipeline) этого шага не
	// делает — здесь делаем осознанно, см. Sibling-Parity Diff в спеке.
	$validation = ( new PresetValidator() )->validate_markdown($result['markdown']);
	if ( ! $validation['valid'] ) {
		$first = $validation['errors'][0] ?? [];
		return [
			'ok'      => false,
			'message' => 'результат невалиден: ' . ( $first['code'] ?? '?' )
				. ( isset($first['line']) ? ' (строка ' . $first['line'] . ')' : '' ),
		];
	}

	if ( $dry_run ) {
		return [ 'ok' => true, 'message' => 'dry-run → ' . basename($target) ];
	}

	if ( file_put_contents($target, $result['markdown']) === false ) {
		return [ 'ok' => false, 'message' => 'не удалось записать ' . $target ];
	}

	if ( $delete_source && realpath($path) !== realpath($target) ) {
		unlink($path);
	}

	return [ 'ok' => true, 'message' => '→ ' . basename($target) ];
}

// ── main ────────────────────────────────────────────────────────────────────

$args          = array_slice($argv, 1);
$dry_run       = in_array('--dry-run', $args, true);
$delete_source = in_array('--delete-source', $args, true);
$paths         = array_values(array_filter($args, static fn (string $a): bool => ! str_starts_with($a, '--')));

if ( $paths === [] ) {
	fwrite(STDERR, "Usage: php bin/convert-preset-format.php <preset.md|dir> [--delete-source] [--dry-run]\n");
	exit(1);
}

$targets = [];
foreach ( $paths as $path ) {
	if ( is_dir($path) ) {
		$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
		foreach ( $it as $file ) {
			// Оба имени: `preset.md` — файлы старой грамматики, ещё не тронутые;
			// PresetFormat::FILENAME — уже переименованные, которым может не
			// хватать более поздних преобразований шапки.
			if ( $file->isFile() && in_array($file->getFilename(), [ 'preset.md', PresetFormat::FILENAME ], true) ) {
				$targets[] = $file->getPathname();
			}
		}
		continue;
	}

	if ( is_file($path) ) {
		$targets[] = $path;
		continue;
	}

	fwrite(STDERR, "Не найдено: {$path}\n");
	exit(1);
}

sort($targets);

$failed = 0;
foreach ( $targets as $target ) {
	$result = convert_file($target, $dry_run, $delete_source);
	// fwrite в STDOUT, а не echo/printf: это CLI-скрипт, его вывод идёт в
	// терминал, а не в HTML-страницу, поэтому escaping-функции WordPress здесь
	// неприменимы — и sniff на неэкранированный вывод не должен подавляться
	// комментарием там, где проблемы по существу нет.
	fwrite(STDOUT, sprintf("%-60s %s\n", $target, $result['message']));
	if ( ! $result['ok'] ) {
		$failed++;
	}
}

fwrite(STDOUT, sprintf("\nВсего: %d, ошибок: %d\n", count($targets), $failed));
exit($failed > 0 ? 1 : 0);
