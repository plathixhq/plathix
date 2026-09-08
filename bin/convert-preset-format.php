<?php


declare(strict_types=1);

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

require_once __DIR__ . '/../src/Core/FolderName.php';
require_once __DIR__ . '/../src/Modules/Preset/PresetError.php';
require_once __DIR__ . '/../src/Modules/Preset/PresetFormat.php';
require_once __DIR__ . '/../src/Modules/Preset/PresetParser.php';
require_once __DIR__ . '/../src/Modules/Preset/PresetValidator.php';

use Plathix\Modules\Preset\PresetFormat;
use Plathix\Modules\Preset\PresetValidator;

const LEGACY_LINE = '/^\s*([0-9]+(?:\.[0-9]+)*)\s*:\s*Folder\(([^)]*)\)\s*:\s*Color\(([^)]*)\)(?:\s*:\s*Favorite\(([^)]*)\))?\s*$/';

/**
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
			. '- ' . PresetFormat::escapeName($m[2])
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
		return [ 'ok' => false, 'message' => 'Cannot read file' ];
	}

	$result = convert_markdown($source);
	$target = dirname($path) . DIRECTORY_SEPARATOR . PresetFormat::FILENAME;

	$existing = is_file($target) ? file_get_contents($target) : false;
	if ( $existing === $result['markdown'] && realpath($path) === realpath($target) ) {
		return [ 'ok' => true, 'message' => 'Skipped: no changes' ];
	}

	$validation = ( new PresetValidator() )->validateMarkdown($result['markdown']);
	if ( ! $validation['valid'] ) {
		$first = $validation['errors'][0] ?? [];
		return [
			'ok'      => false,
			'message' => 'Generated result is invalid' . ( $first['code'] ?? '?' )
				. ( isset($first['line']) ? 'line' . $first['line'] . ')' : '' ),
		];
	}

	if ( $dry_run ) {
		return [ 'ok' => true, 'message' => 'dry-run → ' . basename($target) ];
	}

	if ( file_put_contents($target, $result['markdown']) === false ) {
		return [ 'ok' => false, 'message' => 'Could not write file' . $target ];
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

	fwrite(STDERR, "File not found: {$path}\n");
	exit(1);
}

sort($targets);

$failed = 0;
foreach ( $targets as $target ) {
	$result = convert_file($target, $dry_run, $delete_source);

	fwrite(STDOUT, sprintf("%-60s %s\n", $target, $result['message']));
	if ( ! $result['ok'] ) {
		$failed++;
	}
}

fwrite(STDOUT, sprintf("Static analysis rule failed for a public contract violation.", count($targets), $failed));
exit($failed > 0 ? 1 : 0);
