<?php

declare(strict_types=1);

namespace Plathix\Modules\Preset;

use Plathix\Infrastructure\TempDirectory;
use Plathix\Core\FolderRepository;
use Plathix\Core\Taxonomy;

/**
 * Exports the current media folder hierarchy as a valid Plathix preset package.
 * Spec ref: sections 17, 28.3, 29, 30.
 *
 * @requires extension zip
 */
final class PresetExportPipeline
{

	private \Closure $temp_dir_resolver;
	private readonly PresetValidator $validator;

	/**
	 * @param ?callable $temp_dir_resolver
	 * @param ?PresetValidator $validator
	 */

	public function __construct(?callable $temp_dir_resolver = null, ?PresetValidator $validator = null) {
		$this->temp_dir_resolver = \Closure::fromCallable(
			$temp_dir_resolver ?? static fn (): string => ( new \Plathix\Infrastructure\TempDirectory() )->path()
		);
		$this->validator = $validator ?? new PresetValidator();
	}

	/**
	 * @param array{
	 *   title: string,
	 *   slug: string,
	 *   description: string,
	 *   tags: string,
	 *   author: string,
	 *   author_url?: string,
	 * } $metadata         User-supplied metadata for the package.
	 * @param array{
	 *   tmp_name: string,
	 *   name: string,
	 *   size: int,
	 * } $previewFile     Preview image uploaded by the user (same shape as $_FILES entry).
	 * @return array{
	 *     success: true,
	 *     zip_path: string,
	 *     temp_dir: string,
	 *     metadata: array<string, mixed>,
	 *     folder_count: int,
	 * }|array{
	 *     success: false,
	 *     error: array{code: string, message: string, fatal: bool},
	 * }
	 *
	 * On success, `zip_path` and `temp_dir` are short-lived handoff artifacts.
	 * The caller MUST stream `zip_path` and then call $this->cleanup($temp_dir)
	 * (or equivalent) after the response is sent.
	 */
	public function run(array $metadata, array $previewFile): array
	{
		// ── Step 1: validate required metadata ────────────────────────────────
			$required = ['title', 'slug', 'description', 'tags', 'author'];
		foreach ( $required as $key ) {
			if ( trim( (string) ($metadata[$key] ?? '')) === '' ) {
				/* translators: %s: missing preset metadata field key. */
				return $this->fail('preset_export_missing_metadata', sprintf(__('Missing required export metadata: %s', 'plathix'), $key));
			}
		}

		// ── Step 2: validate preview file ────────────────────────────────────
		$preview_tmp  = (string) ($previewFile['tmp_name'] ?? '');
		$preview_name = (string) ($previewFile['name'] ?? '');
		$preview_size = (int) ($previewFile['size'] ?? 0);

		$ext            = strtolower(pathinfo($preview_name, PATHINFO_EXTENSION));
		$canonical_name = 'preview.' . $ext;

		if ( $preview_tmp === '' || ! file_exists($preview_tmp) ) {
			return $this->fail('preset_export_missing_preview', __('Preview image is required for export.', 'plathix'));
		}
		if ( ! in_array($canonical_name, PresetFormat::ALLOWED_PREVIEWS, true) ) {
			return $this->fail('preset_export_missing_preview', __('Preview image must be webp, png, jpg, or jpeg.', 'plathix'));
		}
		if ( $preview_size > 307_200 ) {
			return $this->fail('preset_export_missing_preview', __('Preview image exceeds 300 KB limit.', 'plathix'));
		}
		$image_info = @getimagesize($preview_tmp);
		if ( $image_info === false ) {
			return $this->fail('preset_export_missing_preview', __('Preview image could not be validated.', 'plathix'));
		}

		// ── Step 3: collect media folder hierarchy ────────────────────────────
		$taxonomy = Taxonomy::taxonomyForPostType('attachment');
		$terms    = get_terms([
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
			'orderby'    => 'name',
			'order'      => 'ASC',
		]);

		if ( is_wp_error($terms) ) {
			$terms = [];
		}
		if ( ! is_array($terms) ) {
			$terms = [];
		}

		$protected_slugs = FolderRepository::systemSlugs();
		$user_terms      = array_filter(
			$terms,
			static fn (\WP_Term $t): bool => ! in_array($t->slug, $protected_slugs, true)
		);

		// ── Step 4: build numbering from hierarchy ────────────────────────────

		$favorite_ids = \Plathix\User\Preferences::getFavorites( get_current_user_id(), 'attachment' );
		$structure = $this->buildStructure($user_terms, $taxonomy, $favorite_ids);
		$folder_count = count($structure);

		$structure_errors = $this->validator->validateStructure(['structure' => $structure]);
		foreach ( $structure_errors as $structure_error ) {
			$code = (string) ($structure_error['code'] ?? '');
			if ( in_array($code, ['preset_structure_limit_exceeded', 'preset_max_depth_exceeded'], true) ) {
				return $this->fail($code, (string) ($structure_error['message'] ?? ''));
			}
		}

		// ── Step 5: generate preset.md ────────────────────────────────────────
		$slug        = sanitize_key( (string) $metadata['slug']);
		$title       = trim( (string) $metadata['title']);
		$description = trim( (string) $metadata['description']);
		$tags        = trim( (string) $metadata['tags']);
		$author      = trim( (string) $metadata['author']);
		$author_url  = trim( (string) ($metadata['author_url'] ?? ''));
		$version     = trim( (string) ($metadata['version'] ?? '1'));

		$preset_md = $this->generatePresetMd(
			title:       $title,
			slug:        $slug,
			description: $description,
			preview:     $canonical_name,
			tags:        $tags,
			author:      $author,
			author_url:  $author_url,
			structure:   $structure,
			version:     $version,
		);

		// ── Step 6: build zip in temp dir ─────────────────────────────────────
		if ( ! class_exists(\ZipArchive::class) ) {
			return $this->fail('preset_export_zip_unavailable', __('PHP zip extension is not available.', 'plathix'));
		}

		$temp_dir = ($this->temp_dir_resolver)() . 'plathix_export_' . wp_generate_password(8, false);
		if ( ! wp_mkdir_p($temp_dir) ) {
			return $this->fail('preset_export_temp_failed', __('Could not create temporary directory for export.', 'plathix'));
		}

		$zip_path = $temp_dir . '/' . $slug . '.zip';
		$zip      = new \ZipArchive();
		if ( $zip->open($zip_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true ) {
			$this->cleanup($temp_dir, $preview_tmp);
			return $this->fail('preset_export_zip_failed', __('Could not create export archive.', 'plathix'));
		}

		$zip->addFromString(PresetFormat::FILENAME, $preset_md);
		$zip->addFile($preview_tmp, $canonical_name);
		$zip->close();

		$this->deleteIfInsideTempRoot($preview_tmp);

		// ── Step 7: audit log ─────────────────────────────────────────────────
		do_action( 'plathix/audit/record', 'preset_exported', [
			'slug'         => $slug,
			'folderCount' => $folder_count,
		]);

		return [
			'success'      => true,
			'zip_path'     => $zip_path,
			'temp_dir'     => $temp_dir,
			'metadata'     => [
				'title'       => $title,
				'slug'        => $slug,
				'description' => $description,
				'tags'        => $tags,
				'author'      => $author,
				'author_url'  => $author_url,
			],
			'folder_count' => $folder_count,
		];
	}

	/**
	 * @param  iterable<\WP_Term> $terms
	 * @param  array<int, int>    $favorite_ids
	 * @return array<int, array{depth: int, name: string, color: string, favorite: bool}>
	 */

	private function buildStructure(iterable $terms, string $taxonomy, array $favorite_ids = []): array
	{
		$favorite_lookup = array_fill_keys(array_map('intval', $favorite_ids), true);
		// Index by parent_id
		$by_parent = [];
		foreach ( $terms as $term ) {
			$by_parent[$term->parent][] = $term;
		}

		// Sort each sibling group by name
		foreach ( $by_parent as &$siblings ) {
			usort($siblings, static fn (\WP_Term $a, \WP_Term $b): int => strcmp($a->name, $b->name));
		}
		unset($siblings);

		$result = [];

		$stack = [];
		foreach ( array_reverse($by_parent[0] ?? []) as $root_term ) {
			$stack[] = [$root_term, 0];
		}

		while ( $stack !== [] ) {
			[$term, $depth] = array_pop($stack);

			$color = (string) (get_term_meta( (int) $term->term_id, PLATHIX_TERM_COLOR, true) ?: 'default');
			if ( $color !== 'default' && ! preg_match('/^#[0-9a-f]{6}$/i', $color) ) {
				$color = 'default';
			}

			$result[] = [
				'depth'    => $depth,
				'name'     => $term->name,
				'color'    => $color,
				'favorite' => isset($favorite_lookup[ (int) $term->term_id]),
			];

			foreach ( array_reverse($by_parent[ (int) $term->term_id] ?? []) as $child ) {
				$stack[] = [$child, $depth + 1];
			}
		}

		return $result;
	}

	/**
	 * @param array<int, array{depth: int, name: string, color: string, favorite?: bool}> $structure
	 */
	private function generatePresetMd(
		string $title,
		string $slug,
		string $description,
		string $preview,
		string $tags,
		string $author,
		string $author_url,
		array $structure,
		string $version = '1',
	): string {
		$lines   = [];

		$lines[] = 'FormatVersion: ' . PresetFormat::FORMAT_VERSION;
		$lines[] = 'Title: ' . $title;
		$lines[] = 'Slug: ' . $slug;

		$lines[] = 'Version: ' . ( $version === '' ? '1' : $version );
		$lines[] = 'Description: ' . $description;

		if ( $preview !== '' ) {
			$lines[] = 'Preview: ' . $preview;
		}
		$lines[] = 'Tags: ' . $tags;
		$lines[] = 'Author: ' . $author;
		if ( $author_url !== '' ) {
			$lines[] = 'AuthorURL: ' . $author_url;
		}
		$lines[] = 'Generator: Plathix ' . ( defined('PLATHIX_VERSION') ? PLATHIX_VERSION : 'dev' );
		$lines[] = '';
		$lines[] = '## Structure';
		$lines[] = '';

		foreach ( $structure as $entry ) {

			$line = str_repeat('  ', (int) $entry['depth'])
				. '- ' . PresetFormat::escapeName($entry['name']);

			$attributes = [];
			if ( $entry['color'] !== 'default' && $entry['color'] !== '' ) {
				$attributes[] = 'color: ' . $entry['color'];
			}
			if ( ! empty($entry['favorite']) ) {
				$attributes[] = 'favorite';
			}
			if ( $attributes !== [] ) {
				$line .= ' {' . implode(', ', $attributes) . '}';
			}

			$lines[] = $line;
		}

		return implode("\n", $lines) . "\n";
	}

	private function cleanup(string $dir, ?string $preview_path = null): void
	{
		if ( $preview_path !== null ) {
			$this->deleteIfInsideTempRoot($preview_path);
		}

		TempDirectory::removeTree($dir);
	}

	private function deleteIfInsideTempRoot(string $path): void
	{
		if ( $path === '' || ! is_file($path) ) {
			return;
		}

		$real_path = realpath($path);
		$real_root = realpath( rtrim( ($this->temp_dir_resolver)(), '/\\' ) );

		if ( $real_path === false || $real_root === false ) {
			return;
		}

		if ( ! TempDirectory::isUnderRoot( $real_path, $real_root ) ) {
			return;
		}

		// path-containment checked above: realpath() resolved and confirmed inside the plugin's
		// own temp root before deleting a resized preview file this pipeline produced.
		wp_delete_file($real_path);
	}

	/** @return array{success: false, error: array{code: string, message: string, fatal: bool}} */
	private function fail(string $code, string $message): array
	{
		return [
			'success' => false,
			'error'   => [
				'code'    => $code,
				'message' => $message,
				'fatal'   => true,
			],
		];
	}
}
