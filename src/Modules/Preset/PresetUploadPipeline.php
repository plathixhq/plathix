<?php

declare(strict_types=1);

namespace Plathix\Modules\Preset;

use Plathix\Infrastructure\TempDirectory;

/**
 * Receives a .zip upload, validates its contents, and registers the preset in the local catalog.
 * Spec ref: sections 4, 5, 16, 27, 28.1.
 */
final class PresetUploadPipeline
{
	private const MAX_ARCHIVE_BYTES  = 1_048_576;  // 1 MB
	private const MAX_PREVIEW_BYTES  = 307_200;    // 300 KB
	private const MAX_PRESET_BYTES   = 2_097_152;  // 2 MB (распакованный preset-файл)
	private const ALLOWED_EXTENSIONS = [ 'webp', 'png', 'jpg', 'jpeg' ];

	/** @var \Closure(): string резолвер базовой временной директории */
	private \Closure $temp_dir_resolver;

	/**
	 * @param ?callable $temp_dir_resolver резолвер базовой tmp-папки (): string.
	 *   Дефолт — единый Infrastructure\TempDirectory (свойство 4). Инжектируется
	 *   для тестов без подмены global get_temp_dir.
	 */
	public function __construct(
		private readonly PresetValidator $validator = new PresetValidator(),
		private readonly PresetRepository $repository = new PresetRepository(),
		?callable $temp_dir_resolver = null,
	) {
		$this->temp_dir_resolver = \Closure::fromCallable(
			$temp_dir_resolver ?? static fn (): string => ( new \Plathix\Infrastructure\TempDirectory() )->path()
		);
	}

	/**
	 * Run the full upload pipeline.
	 *
	 * @param array{tmp_name?: string, size?: int, name?: string} $file  $_FILES-style entry
	 * @param string $source_type  One of PresetSourceType constants
	 * @param bool   $dry_run  [internal]: when true, run all structural/content validation
	 *   (Steps 1-14) but stop before persisting anything — no preview file copy, no catalog
	 *   record, no `preset_uploaded` audit entry. Used by the pre-submit AJAX validation so the
	 *   UI can show a real success state instead of a decorative client-side timer.
	 * @return array{success: true, preset: array<string, mixed>}|array{success: false, error: array<string, mixed>}
	 */
	public function run(array $file, string $source_type = PresetSourceType::CUSTOM, bool $dry_run = false): array {
		// Step 1: basic file presence
		$tmp = (string) ($file['tmp_name'] ?? '');
		if ( $tmp === '' || ! is_file($tmp) ) {
			return $this->fail(new PresetError('preset_upload_missing_file', __('No file was uploaded.', 'plathix'), null, null, true));
		}

		// Step 2: extension
		$original_name = (string) ($file['name'] ?? '');
		if ( strtolower(pathinfo($original_name, PATHINFO_EXTENSION)) !== 'zip' ) {
			return $this->fail(new PresetError('preset_invalid_extension', __('Upload must be a .zip file.', 'plathix'), null, null, true));
		}

		// Step 3: archive size
		$size = (int) ($file['size'] ?? filesize($tmp));
		if ( $size > self::MAX_ARCHIVE_BYTES ) {
			return $this->fail(new PresetError('preset_zip_too_large', __('Archive exceeds the 1 MB size limit.', 'plathix'), null, null, true));
		}

		// Step 4–6: open zip and validate entries
		$zip = new \ZipArchive();
		if ( $zip->open($tmp) !== true ) {
			return $this->fail(new PresetError('preset_zip_unreadable', __('Could not open the zip archive.', 'plathix'), null, null, true));
		}

		$count = $zip->numFiles;
		if ( $count !== 2 ) {
			$zip->close();
			return $this->fail(new PresetError('preset_invalid_file_count', __('Archive must contain exactly 2 files.', 'plathix'), null, null, true));
		}

		$found_md      = false;
		$found_preview = null;
		for ( $i = 0; $i < $count; $i++ ) {
			$entry = $zip->getNameIndex($i);
			if ( $entry === false ) {
				$zip->close();
				return $this->fail(new PresetError('preset_zip_unreadable', __('Could not read archive entries.', 'plathix'), null, null, true));
			}

			// Path safety (step 6)
			if ( str_contains($entry, '/') || str_contains($entry, '\\') || str_contains($entry, '..') ) {
				$zip->close();
				return $this->fail(new PresetError('preset_unsafe_path', __('Archive contains unsafe file paths.', 'plathix'), null, null, true));
			}

			// Zip-бомба: до этой проверки контролировался только размер АРХИВА
			// (1 МБ), а extractTo() писал на диск распакованное содержимое без
			// ограничений — 1 МБ хорошо сжатых нулей разворачивается в сотни МБ.
			$stat = $zip->statIndex($i);
			$declared_size = (int) ($stat['size'] ?? 0);
			if ( $entry === PresetFormat::FILENAME && $declared_size > self::MAX_PRESET_BYTES ) {
				$zip->close();
				return $this->fail(new PresetError('preset_uncompressed_too_large', __('Preset description exceeds the size limit.', 'plathix'), null, null, true));
			}
			if ( in_array($entry, PresetFormat::ALLOWED_PREVIEWS, true) && $declared_size > self::MAX_PREVIEW_BYTES ) {
				$zip->close();
				return $this->fail(new PresetError('preset_uncompressed_too_large', __('Preview image exceeds the size limit.', 'plathix'), null, null, true));
			}

			if ( $entry === PresetFormat::FILENAME ) {
				$found_md = true;
			} elseif ( in_array($entry, PresetFormat::ALLOWED_PREVIEWS, true) ) {
				$found_preview = $entry;
			}
		}

		if ( ! $found_md ) {
			$zip->close();
			return $this->fail(new PresetError('preset_missing_preset_md', __('Archive is missing the preset description file.', 'plathix'), null, null, true));
		}

		if ( $found_preview === null ) {
			$zip->close();
			return $this->fail(new PresetError('preset_missing_preview', __('Archive is missing a valid preview image.', 'plathix'), null, null, true));
		}

		// Step 7: extract to temp directory
		$temp_dir = $this->make_temp_dir();
		if ( $temp_dir === null ) {
			$zip->close();
			return $this->fail(new PresetError('preset_temp_dir_failed', __('Could not create temporary directory.', 'plathix'), null, null, true));
		}

		try {
			$extracted = $zip->extractTo($temp_dir);
			$zip->close();

			if ( ! $extracted ) {
				return $this->fail(new PresetError('preset_extract_failed', __('Could not extract archive.', 'plathix'), null, null, true));
			}

			// Step 8: validate preview image
			$preview_path = $temp_dir . DIRECTORY_SEPARATOR . $found_preview;
			$preview_error = $this->validate_preview($preview_path);
			if ( $preview_error !== null ) {
				return $this->fail($preview_error);
			}

			// Step 9–10: read and validate encoding of preset.md
			$md_path  = $temp_dir . DIRECTORY_SEPARATOR . PresetFormat::FILENAME;
			// statIndex() отдаёт размер, ЗАЯВЛЕННЫЙ архивом, — он может расходиться
			// с фактически записанным, поэтому лимит перепроверяется на диске.
			$actual_size = @filesize($md_path);
			if ( $actual_size !== false && $actual_size > self::MAX_PRESET_BYTES ) {
				return $this->fail(new PresetError('preset_uncompressed_too_large', __('Preset description exceeds the size limit.', 'plathix'), null, null, true));
			}
			$markdown = file_get_contents($md_path); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads preset.md from the pipeline's own ephemeral temp dir (Infrastructure\TempDirectory + plathix_preset_ prefix); local path, not a remote URL.
			if ( $markdown === false ) {
				return $this->fail(new PresetError('preset_read_failed', __('Could not read the preset description file.', 'plathix'), null, null, true));
			}

			// BOM check
			if ( str_starts_with($markdown, "\xEF\xBB\xBF") ) {
				return $this->fail(new PresetError('preset_invalid_encoding', __('The preset description file must be UTF-8 without BOM.', 'plathix'), null, null, true));
			}

			// Steps 11–14: parse and validate
			$validation = $this->validator->validate_markdown($markdown);
			if ( ! $validation['valid'] ) {
				$first_error = $validation['errors'][0] ?? [];
				return $this->fail_array($first_error);
			}

			$preset = $validation['preset'];

			// Verify preview field matches actual preview file
			if ( ($preset['preview'] ?? '') !== $found_preview ) {
				return $this->fail(new PresetError('preset_preview_mismatch', __('The Preview field does not match the preview file in the archive.', 'plathix'), null, 'metadata', true));
			}

			// [internal]: dry-run stops here — structural/content validation (Steps 1-14) has
			// passed, but nothing is persisted (no preview copy, no catalog record, no audit
			// entry). The AJAX validation endpoint uses this to show a real success state
			// without side effects; the final Upload click still runs the full pipeline.
			if ( $dry_run ) {
				return [
					'success' => true,
					'preset'  => [
						'title'             => $preset['title'],
						'slug'              => $preset['slug'],
						'source_type'       => PresetSourceType::normalize($source_type),
						'validation_status' => 'valid',
						'folder_count'      => count( (array) ($preset['structure'] ?? [])),
					],
				];
			}

			// Step 15: persist preview and register catalog record
			$preview_ref = $this->store_preview($preview_path, (string) ($preset['slug'] ?? ''), $found_preview);

			$record = [
				'source_type'       => PresetSourceType::normalize($source_type),
				'slug'              => $preset['slug'],
				'title'             => $preset['title'],
				'version'           => $preset['version'],
				'description'       => $preset['description'],
				'tags'              => $preset['tags'],
				'author_name'       => $preset['author'],
				'author_url'        => $preset['author_url'] ?? '',
				'preview_ref'       => $preview_ref,
				'validation_status' => 'valid',
				'folder_count'      => count( (array) ($preset['structure'] ?? [])),
				'structure'         => $preset['structure'],
			];

			// Слаг занят записью с ТОЙ ЖЕ структурой (built-in уже отсечён
			// валидатором) — значит автор выпустил новую редакцию своего пресета:
			// обновляем запись вместо создания дубля `slug-2`, `slug-3`, которые
			// никто потом не чистит (delete() зовётся только вручную из UI).
			$existing = $this->repository->find_by_slug( (string) $preset['slug']);
			$is_update = $existing !== null
				&& $this->validator->structures_match(
					(array) ($preset['structure'] ?? []),
					(array) ($existing['structure'] ?? [])
				);

			if ( $is_update ) {
				// Метка применения принадлежала ПРЕДЫДУЩЕЙ редакции: update() делает
				// array_merge со старой записью и иначе сохранил бы её, после чего
				// дашборд (find_last_applied) утверждал бы, что применена новая
				// версия, хотя в медиатеке лежат папки старой.
				$record['last_applied_at'] = null;
				$id = $this->repository->upsert_by_slug($record);
			} else {
				$id = $this->repository->create($record);
			}

			if ( is_wp_error($id) ) {
				/** @var \WP_Error $id Narrowed inside is_wp_error() guard (namespaced test stub lacks narrowing; see [internal] #6). */
				// [internal]: store_preview() (Step 15 above) уже записал файл на диск
				// ДО этой проверки — запись в каталог не удалась, значит preview-файл
				// осиротел (никакая запись на него не ссылается, delete() вызывается
				// только вручную из UI по существующему id).
				$this->remove_orphaned_preview($preview_ref);
				return $this->fail(new PresetError(
					(string) $id->get_error_code(),
					$id->get_error_message(),
					null, null, true
				));
			}
			/** @var int $id Narrowed after is_wp_error() guard (see [internal] #6). */

			$stored = $this->repository->find($id);

			do_action( 'plathix/audit/record', $is_update ? 'preset_updated' : 'preset_uploaded', [
				'presetId'   => $id,
				'slug'        => $preset['slug'],
				'title'       => $preset['title'],
				'sourceType' => PresetSourceType::normalize($source_type),
			]);

			return [
				'success' => true,
				// Отличает обновление существующей записи от создания новой, чтобы
				// UI показал «Пресет обновлён», а не «Пресет загружен».
				'updated' => $is_update,
				'preset'  => [
					'id'                => $id,
					'title'             => $preset['title'],
					'slug'              => $preset['slug'],
					'source_type'       => PresetSourceType::normalize($source_type),
					'validation_status' => 'valid',
					'preview_ref'       => $preview_ref,
					'folder_count'      => count( (array) ($preset['structure'] ?? [])),
				],
			];
		} finally {
			// Step 16: cleanup temp
			$this->remove_temp_dir($temp_dir);
		}
	}

	// -------------------------------------------------------------------------

	private function validate_preview(string $path): ?PresetError {
		if ( ! is_file($path) ) {
			return new PresetError('preset_missing_preview', __('Preview file could not be found after extraction.', 'plathix'), null, null, true);
		}

		$size = filesize($path);
		if ( $size === false || $size > self::MAX_PREVIEW_BYTES ) {
			return new PresetError('preset_preview_too_large', __('Preview image exceeds the 300 KB size limit.', 'plathix'), null, null, true);
		}

		$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
		if ( ! in_array($ext, self::ALLOWED_EXTENSIONS, true) ) {
			return new PresetError('preset_invalid_preview', __('Preview image has an unsupported format.', 'plathix'), null, null, true);
		}

		// Verify it is actually an image
		$info = @getimagesize($path);
		if ( $info === false ) {
			return new PresetError('preset_invalid_preview', __('Preview file is not a valid image.', 'plathix'), null, null, true);
		}

		return null;
	}

	/**
	 * Stores the preview image in the WP uploads directory under plathix/presets/{slug}/.
	 * Returns the relative reference path, or null if the directory could not be created
	 * or the file could not be copied.
	 */
	private function store_preview(string $src_path, string $slug, string $filename): ?string {
		$upload_dir = wp_upload_dir();
		$presets    = trailingslashit($upload_dir['basedir']) . 'plathix/presets';
		$base       = $presets . '/' . sanitize_key($slug);

		if ( ! wp_mkdir_p($base) ) {
			return null;
		}

		// Directory guard на РОДИТЕЛЕ plathix/presets: Apache `Deny from all` наследуется на
		// все {slug}/ подкаталоги, поэтому один guard закрывает polyglot-preview (jpg+php,
		// исполняемый по прямому URL при мисконфиге сервера). Эталон — Activator::ensure_temp_dir.
		$this->write_dir_guard($presets);

		$dest = $base . '/' . $filename;
		if ( ! @copy($src_path, $dest) ) {
			return null;
		}

		return 'plathix/presets/' . sanitize_key($slug) . '/' . $filename;
	}

	/**
	 * Идемпотентно кладёт directory-guard (index.php + .htaccess «Deny from all») в $dir,
	 * если файлов ещё нет. Защищает каталог от листинга и прямого исполнения содержимого.
	 * Содержимое 1:1 с Activator::ensure_temp_dir (единый инвариант защиты uploads-каталогов).
	 */
	private function write_dir_guard(string $dir): void {
		$index = trailingslashit($dir) . 'index.php';
		if ( ! file_exists($index) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writes a directory-index guard into the plugin's own just-created plathix/presets dir (under uploads); WP_Filesystem credentials-flow may be unavailable and this runs on a local upload path.
		}

		$htaccess = trailingslashit($dir) . '.htaccess';
		if ( ! file_exists($htaccess) ) {
			file_put_contents( $htaccess, "Deny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writes an Apache deny-all guard into the plugin's own just-created plathix/presets dir (under uploads); WP_Filesystem credentials-flow may be unavailable and this runs on a local upload path.
		}
	}

	private function make_temp_dir(): ?string {
		// База — общий TempDirectory (резолвер уже trailingslashed); изоляция
		// upload-staging через префикс plathix_preset_.
		$base = ($this->temp_dir_resolver)();
		$dir  = $base . 'plathix_preset_' . wp_generate_password(12, false);
		if ( ! wp_mkdir_p($dir) ) {
			return null;
		}

		return $dir;
	}

	private function remove_temp_dir(string $dir): void {
		TempDirectory::remove_tree($dir);
	}

	/**
	 * Удаляет preview-файл, уже записанный store_preview(), когда последующая запись
	 * каталога (create/upsert_by_slug/update) не удалась — иначе файл остаётся сиротой:
	 * никакая запись на него не ссылается, delete() вызывается только вручную из UI по
	 * существующему id ([internal]).
	 *
	 * $preview_ref — тот же относительный путь, что store_preview() вернул и что она же
	 * записала под uploads basedir; путь не пересобирается вручную, чтобы не разойтись
	 * с write-стороной при будущем изменении схемы каталога.
	 */
	private function remove_orphaned_preview(?string $preview_ref): void {
		if ( $preview_ref === null || $preview_ref === '' ) {
			return;
		}

		$upload_dir = wp_upload_dir();
		$path       = trailingslashit($upload_dir['basedir']) . $preview_ref;

		if ( is_file($path) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- best-effort cleanup of an orphaned upload; a failed unlink here must not break the already-in-progress error response. wp_delete_file() is not used here on purpose — this path is not a WP attachment/media-library file, just a leftover preview blob with no post/attachment record to trigger the filter this call would fire.
			@unlink($path);
		}

		$dir = \dirname($path);
		// rmdir на непустом каталоге тихо возвращает false, ничего не удаляя —
		// безопасно на случай гонки/чужого файла в том же {slug}/ каталоге.
		if ( is_dir($dir) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- best-effort removal of the now-possibly-empty {slug}/ dir; failure (non-empty dir, race) is expected and harmless. WP_Filesystem is overkill for this one-shot, non-critical orphan cleanup path.
			@rmdir($dir);
		}
	}

	/** @return array{success: false, error: array<string, mixed>} */
	private function fail(PresetError $error): array {
		$arr = $error->to_array();
		do_action( 'plathix/audit/record', 'preset_validation_failed', [
			'errorCode'    => $arr['code'] ?? '',
			'errorMessage' => $arr['message'] ?? '',
			'section'       => $arr['section'] ?? null,
		]);
		return [ 'success' => false, 'error' => $arr ];
	}

	/** @param array<string, mixed> $error_array
	 *  @return array{success: false, error: array<string, mixed>}
	 */
	private function fail_array(array $error_array): array {
		do_action( 'plathix/audit/record', 'preset_validation_failed', [
			'errorCode'    => $error_array['code'] ?? '',
			'errorMessage' => $error_array['message'] ?? '',
			'section'       => $error_array['section'] ?? null,
		]);
		return [ 'success' => false, 'error' => $error_array ];
	}
}
