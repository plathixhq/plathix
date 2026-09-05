<?php

declare(strict_types=1);

namespace Plathix\Modules\Preset;

final class BuiltInPresetDiscovery
{
	public function __construct(
		private readonly PresetRepository $repository = new PresetRepository(),
		private readonly PresetValidator $validator = new PresetValidator(),
		private readonly string $base_path = PLATHIX_ASSETS_PATH . 'presets'
	) {
	}

	/** @return array{registered: int, invalid: int} */
	public function discover(): array {
		if ( ! is_dir($this->base_path) ) {
			return [ 'registered' => 0, 'invalid' => 0 ];
		}

		$entries = glob(rtrim($this->base_path, '/\\') . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [];
		$stats   = [ 'registered' => 0, 'invalid' => 0 ];

		foreach ( $entries as $directory ) {
			$directory = (string) $directory;
			$presetFile = $directory . DIRECTORY_SEPARATOR . PresetFormat::FILENAME;

			if ( ! is_file($presetFile) ) {
				$stats['invalid']++;
				continue;
			}

			$markdown = file_get_contents($presetFile); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads a bundled preset .md under PLATHIX_ASSETS_PATH (is_file-checked above); local plugin asset, not a remote URL.
			if ( ! is_string($markdown) || $markdown === '' ) {
				$stats['invalid']++;
				continue;
			}

			$result = $this->validator->validate_markdown($markdown);
			if ( ! $result['valid'] ) {
				$stats['invalid']++;
				continue;
			}

			$preset = $result['preset'];
			$previewFile = $directory . DIRECTORY_SEPARATOR . (string) $preset['preview'];
			if ( ! is_file($previewFile) ) {
				$stats['invalid']++;
				continue;
			}

			$stored = $this->repository->upsert_by_slug([
				'source_type' => PresetSourceType::BUILTIN,
				'slug' => (string) $preset['slug'],
				'title' => (string) $preset['title'],
				'version' => (string) $preset['version'],
				'description' => (string) $preset['description'],
				'tags' => (array) ($preset['tags'] ?? []),
				'author_name' => (string) $preset['author'],
				'author_url' => (string) ($preset['author_url'] ?? ''),
				'preview_ref' => $previewFile,
				'storage_path' => $directory,
				'validation_status' => 'valid',
				'folder_count' => count( (array) ($preset['structure'] ?? [])),
				'structure' => (array) ($preset['structure'] ?? []),
			]);

			if ( is_wp_error($stored) ) {
				$stats['invalid']++;
				continue;
			}

			$stats['registered']++;
		}

		return $stats;
	}
}
