<?php

declare(strict_types=1);

namespace Plathix\Modules\Preset;

use Plathix\Core\FolderName;

final class PresetValidator
{
	public function __construct(
		private readonly PresetParser $parser = new PresetParser(),
		private readonly ?PresetRepository $repository = null
	) {
	}

	/** @return array{preset: array<string, mixed>, errors: array<int, array<string, mixed>>, valid: bool} */
	public function validateMarkdown(string $markdown): array {
		$parsed = $this->parser->parseMarkdown($markdown);
		if ( ! $parsed['valid'] ) {
			return $parsed;
		}

		$preset = $parsed['preset'];
		$errors = [];
		$errors = array_merge($errors, $this->validateMetadata($preset));
		$errors = array_merge($errors, $this->validateStructure($preset));

		return [
			'preset' => $preset,
			'errors' => $errors,
			'valid' => $errors === [],
		];
	}

	/** @param array<string, mixed> $preset
	 *  @return array<int, array<string, mixed>>
	 */
	public function validateMetadata(array $preset): array {
		$errors = [];

		foreach ( ['title', 'slug', 'version', 'description', 'author'] as $required ) {
			if ( trim( (string) ($preset[$required] ?? '')) === '' ) {
				$errors[] = (new PresetError(
					'preset_missing_required_metadata',
					__('Missing required metadata field.', 'plathix'),
					null,
					'metadata'
				))->toArray();
				return $errors;
			}
		}

		if ( trim( (string) $preset['version']) === '' ) {
			$errors[] = (new PresetError(
				'preset_invalid_version',
				__('Preset version cannot be empty.', 'plathix'),
				null,
				'metadata'
			))->toArray();
		}

		$slug = sanitize_key( (string) $preset['slug']);
		if ( $slug === '' || $slug !== (string) $preset['slug'] ) {
			$errors[] = (new PresetError(
				'preset_invalid_slug',
				__('Invalid preset slug.', 'plathix'),
				null,
				'metadata'
			))->toArray();
		}

		$preview = (string) ($preset['preview'] ?? '');
		if ( $preview !== '' && ! in_array($preview, PresetFormat::ALLOWED_PREVIEWS, true) ) {
			$errors[] = (new PresetError(
				'preset_invalid_preview',
				__('Preview must be one of: preview.webp, preview.png, preview.jpg, preview.jpeg.', 'plathix'),
				null,
				'metadata'
			))->toArray();
		}

		foreach ( (array) ($preset['tags'] ?? []) as $tag ) {
			$tag = trim( (string) $tag);
			if ( $tag === '' || ! str_starts_with($tag, '#') ) {
				$errors[] = (new PresetError(
					'preset_invalid_tags',
					__('Invalid tags format.', 'plathix'),
					null,
					'metadata'
				))->toArray();
				break;
			}
		}

		if ( $this->repository instanceof PresetRepository ) {
			$existing = $this->repository->findBySlug( (string) $preset['slug']);

			if ( $existing !== null && (string) ($existing['source_type'] ?? '') === PresetSourceType::BUILTIN ) {
				$errors[] = (new PresetError(
					'preset_builtin_slug_reserved',
					__('This slug is reserved by a built-in preset. Rename the preset before uploading.', 'plathix'),
					null,
					'metadata'
				))->toArray();

				return $errors;
			}

			//

			if (
				$existing !== null
				&& trim( (string) ($preset['version'] ?? '')) === trim( (string) ($existing['version'] ?? ''))
				&& $this->structuresEqual(
					(array) ($preset['structure'] ?? []),
					(array) ($existing['structure'] ?? [])
				)
			) {
				$errors[] = (new PresetError(
					'preset_slug_conflict',
					__('This preset already exists.', 'plathix'),
					null,
					'metadata'
				))->toArray();
			}
		}

		return $errors;
	}

	/**
	 * @param array<int, array<string, mixed>> $a
	 * @param array<int, array<string, mixed>> $b
	 */

	private function structuresEqual(array $a, array $b): bool {
		return $this->structuresMatch($a, $b);
	}

	/**
	 * @param array<int, array<string, mixed>> $a
	 * @param array<int, array<string, mixed>> $b
	 */

	public function structuresMatch(array $a, array $b): bool {
		return $this->normalizeStructure($a) === $this->normalizeStructure($b);
	}

	/**
	 * @param array<int, array<string, mixed>> $structure
	 * @return list<array{depth:int,name:string,color:string}>
	 */
	private function normalizeStructure(array $structure): array {
		$normalized = [];
		foreach ( $structure as $folder ) {
			$normalized[] = [
				'depth'     => (int) ($folder['depth'] ?? 0),
				'name'      => (string) ($folder['name'] ?? ''),
				'color'     => (string) ($folder['color'] ?? ''),
			];
		}

		return $normalized;
	}

	/** @param array<string, mixed> $preset
	 *  @return array<int, array<string, mixed>>
	 */
	public function validateStructure(array $preset): array {
		$errors = [];
		$structure = (array) ($preset['structure'] ?? []);

		if ( count($structure) > 200 ) {
			$errors[] = (new PresetError(
				'preset_structure_limit_exceeded',
				__('Preset exceeds the maximum number of structure lines.', 'plathix'),
				null,
				'structure'
			))->toArray();
			return $errors;
		}

		foreach ( $structure as $entry ) {
			$line = (int) ($entry['line'] ?? 0);
			$depth = (int) ($entry['depth'] ?? 0);
			$name = (string) ($entry['name'] ?? '');
			$color = (string) ($entry['color'] ?? '');

			if ( $name === '' ) {
				$errors[] = (new PresetError('preset_invalid_folder_name', __('Folder name cannot be empty.', 'plathix'), $line, 'structure'))->toArray();
			}

			foreach ( FolderName::validate( $name, 150 ) as $code ) {
				$message = match ( $code ) {
					FolderName::ERROR_LINE_BREAK => __('Folder name contains forbidden characters.', 'plathix'),
					FolderName::ERROR_DANGEROUS_CHARS => __('Folder name contains control or bidirectional characters.', 'plathix'),
					FolderName::ERROR_TOO_LONG_CHARS => __('Folder name is too long.', 'plathix'),
					FolderName::ERROR_TOO_LONG_BYTES => __('Folder name is too long in bytes.', 'plathix'),
					default => null,
				};
				if ( null !== $message ) {
					$errors[] = (new PresetError('preset_invalid_folder_name', $message, $line, 'structure'))->toArray();
				}
			}
			if ( $color !== 'default' && preg_match('/^#[0-9A-Fa-f]{6}$/', $color) !== 1 ) {
				$errors[] = (new PresetError('preset_invalid_color', __('Invalid color value.', 'plathix'), $line, 'structure'))->toArray();
			}

			if ( $depth > 6 ) {
				$errors[] = (new PresetError('preset_max_depth_exceeded', __('Preset exceeds maximum depth.', 'plathix'), $line, 'structure'))->toArray();
			}
		}

		return $errors;
	}
}
