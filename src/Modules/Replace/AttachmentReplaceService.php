<?php

declare(strict_types=1);

namespace Plathix\Modules\Replace;

use Plathix\Core\AttachmentFileCleanup;
use Plathix\Infrastructure\Logger;
use Plathix\Svg\Sanitizer\Sanitizer;
use Plathix\Svg\SvgUploadPolicy;

final class AttachmentReplaceService
{
	private readonly AttachmentReplaceLock $lock;
	private readonly AttachmentFileCleanup $cleanup;
	private readonly Sanitizer $svg_sanitizer;
	private readonly SvgUploadPolicy $svg_upload_policy;
	private readonly ReplaceAuthorization $authorization;

	/** @var \Closure(string,string): (array{ext:string|false,type:string|false,proper_filename:string|false}|array<string,mixed>) */
	private \Closure $filetype_validator;
	/** @var \Closure(array<string,mixed>, array<string,mixed>): (array<string,mixed>|\WP_Error) */
	private \Closure $upload_runner;
	/** @var \Closure(array<string,mixed>, array<string,mixed>): (array<string,mixed>|\WP_Error) */
	private \Closure $sideload_runner;
	/** @var \Closure(): string резолвер пути к временной директории Plathix */
	private \Closure $temp_dir_resolver;
	/** @var \Closure(int,string): (array<string,mixed>|false) */
	private \Closure $metadata_generator;
	/** @var \Closure(int): void */
	private \Closure $post_cache_cleaner;
	/** @var \Closure(string): void */
	private \Closure $cache_invalidator;
	/** @var \Closure(int, array<string,mixed>, array<string,mixed>): void */
	private \Closure $audit_recorder;
	/** @var \Closure(int, array<string,mixed>): void */
	private \Closure $hook_dispatcher;
	/** @var \Closure(int): array<string,mixed> */
	private \Closure $sizes_resolver;
	/** @var \Closure(int): array<string,array<string,mixed>> */
	private \Closure $missing_subsizes_resolver;

	public function __construct(
		?AttachmentReplaceLock $lock = null,
		?AttachmentFileCleanup $cleanup = null,
		?Sanitizer $svg_sanitizer = null,
		?callable $filetype_validator = null,
		?callable $upload_runner = null,
		?callable $sideload_runner = null,
		?callable $temp_dir_resolver = null,
		?callable $metadata_generator = null,
		?callable $post_cache_cleaner = null,
		?callable $cache_invalidator = null,
		?callable $audit_recorder = null,
		?callable $hook_dispatcher = null,
		?SvgUploadPolicy $svg_upload_policy = null,
		?ReplaceAuthorization $authorization = null,
		?callable $sizes_resolver = null,
		?callable $missing_subsizes_resolver = null
	) {
		$this->lock = $lock ?? new AttachmentReplaceLock();
		$this->cleanup = $cleanup ?? new AttachmentFileCleanup();
		$this->svg_sanitizer = $svg_sanitizer ?? new Sanitizer();
		// SvgUploadPolicy делит тот же Sanitizer, что инжектирован в сервис — одна точка
		// подмены санитайзера в тестах; markup-политика едина с Modules\Svg ([internal]).
		$this->svg_upload_policy = $svg_upload_policy ?? new SvgUploadPolicy( $this->svg_sanitizer );
		// Авторизация replace вынесена в соучастника ([internal]): отдельная причина меняться
		// (модель прав), не завязана на транзакцию. Дефолт new — как lock/cleanup/policy.
		$this->authorization = $authorization ?? new ReplaceAuthorization();
		$this->filetype_validator = \Closure::fromCallable( $filetype_validator ?? [ $this, 'default_filetype_validator' ] );
		$this->upload_runner = \Closure::fromCallable( $upload_runner ?? [ $this, 'default_upload_runner' ] );
		$this->sideload_runner = \Closure::fromCallable( $sideload_runner ?? [ $this, 'default_sideload_runner' ] );
		$this->temp_dir_resolver = \Closure::fromCallable( $temp_dir_resolver ?? [ $this, 'default_temp_dir_resolver' ] );
		$this->metadata_generator = \Closure::fromCallable( $metadata_generator ?? [ $this, 'default_metadata_generator' ] );
		$this->post_cache_cleaner = \Closure::fromCallable( $post_cache_cleaner ?? 'clean_post_cache' );
		$this->cache_invalidator = \Closure::fromCallable(
			$cache_invalidator ?? static function (string $taxonomy): void {
				\Plathix\Infrastructure\Cache::on_attachment_change( null, $taxonomy );
			}
		);
		$this->audit_recorder = \Closure::fromCallable(
			$audit_recorder ?? static function (int $attachment_id, array $result, array $actor_context): void {
				do_action( 'plathix/audit/record',
					'attachment_replaced',
					[
						'objectType' => 'attachment',
						'objectId'   => $attachment_id,
						'summary'     => sprintf( 'Replaced attachment %d', $attachment_id ),
						'context'     => $result,
						'userId'     => (int) ( $actor_context['user_id'] ?? 0 ),
					]
				);
			}
		);
		$this->hook_dispatcher = \Closure::fromCallable(
			$hook_dispatcher ?? static function (int $attachment_id, array $result): void {
				do_action( 'plathix/replace/attachment_replaced', $attachment_id, $result );
			}
		);
		$this->sizes_resolver = \Closure::fromCallable( $sizes_resolver ?? [ $this, 'default_sizes_resolver' ] );
		$this->missing_subsizes_resolver = \Closure::fromCallable( $missing_subsizes_resolver ?? [ $this, 'default_missing_subsizes_resolver' ] );
	}

	/**
	 * @param array<string, mixed> $input
	 * @param array<string, mixed> $options
	 * @return array{attachmentId: int, oldFile: string, newFile: string, oldMime: string, newMime: string, url: string, sizes: array<string, mixed>, version: int, warnings: list<string>, partialSuccess: bool, newWidth: int, newHeight: int, newFilesizeHuman: string}|\WP_Error
	 */
	public function replace(int $attachment_id, array $input, array $options = []): array|\WP_Error
	{
		$validated_input = $this->validate_input_contract( $input );
		if ( is_wp_error( $validated_input ) ) {
			return $validated_input;
		}
		/** @var array<string, mixed> $validated_input Narrowed: namespaced is_wp_error() test stub lacks type-narrowing (see [internal] #6). */

		$actor_context = $this->authorization->normalize( $options['actor_context'] ?? [] );
		$post = get_post( $attachment_id );
		if ( ! $post instanceof \WP_Post || $post->post_type !== 'attachment' ) {
			return new \WP_Error( 'invalid_attachment', __( 'Attachment does not exist.', 'plathix' ) );
		}

		if ( ! $this->authorization->can_replace( $actor_context, $attachment_id ) ) {
			return new \WP_Error( 'forbidden', __( 'Actor is not allowed to replace this attachment.', 'plathix' ) );
		}

		$lock = $this->lock->acquire( $attachment_id );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}
		/** @var array{token: string, timestamp: int} $lock Narrowed after is_wp_error() guard (see [internal] #6). */

		$staged_file = null;
		$sideload_staged_file = null;
		$committed = false;
		$warnings = [];
		$old_state = $this->snapshot_attachment_state( $attachment_id );
		$taxonomy = sanitize_key( (string) ( $options['taxonomy'] ?? PLATHIX_TAXONOMY ) );
		$collision_backup = null;

		try {
			$file_type = ($this->filetype_validator)( (string) $validated_input['tmp_name'], (string) $validated_input['name'] );
			$new_mime = (string) ( $file_type['type'] ?? '' );
			$new_ext = (string) ( $file_type['ext'] ?? '' );
			if ( $new_mime === '' || $new_ext === '' ) {
				return new \WP_Error( 'invalid_mime', __( 'Uploaded file type is not allowed.', 'plathix' ) );
			}

			$validated_input['type'] = $new_mime;
			$validated_input = $this->validate_svg_if_needed( $validated_input, $actor_context );
			if ( is_wp_error( $validated_input ) ) {
				return $validated_input;
			}
			/** @var array<string, mixed> $validated_input Narrowed: namespaced is_wp_error() test stub lacks type-narrowing (see [internal] #6). */

			$upload_mode = sanitize_key( (string) ( $options['upload_mode'] ?? 'upload' ) );
			$temp_dir = null;
			if ( $upload_mode === 'sideload' ) {
				$temp_dir = $this->ensure_temp_dir();
				if ( is_wp_error( $temp_dir ) ) {
					/** @var \WP_Error $temp_dir Narrowed inside is_wp_error() guard (see [internal] #6). */
					return $temp_dir;
				}
				/** @var string $temp_dir Narrowed after is_wp_error() guard (see [internal] #6). */
				$validated_input = $this->stage_sideload_file( $validated_input, $temp_dir );
				if ( is_wp_error( $validated_input ) ) {
					return $validated_input;
				}
				/** @var array<string, mixed> $validated_input Narrowed after is_wp_error() guard (see [internal] #6). */
				// [internal] (M15): capture the staged path immediately — $staged_file is
				// only assigned after run_upload_pipeline() succeeds (below), so if the
				// pipeline fails, this staged sideload copy would otherwise never be unlinked.
				$sideload_staged_file = (string) ( $validated_input['tmp_name'] ?? '' );
			}

			// [internal]: reuse_old_filename() ([internal], run_upload_pipeline() below)
			// can make the new physical file path collide with the old one — the old file
			// is about to be physically overwritten before commit, while
			// rollback_pre_commit() only knows how to restore metadata, not bytes
			// (snapshot_attachment_state() never captured a byte-level backup). Whether the
			// collision actually happens depends on run_upload_pipeline()'s result — a
			// mocked upload_runner() in tests may return a path that never goes through
			// reuse_old_filename() at all — so the real path is only known AFTER the
			// pipeline runs, by which point an overwrite would already have happened. Copy
			// (not move — the old file must stay in place for a pipeline that does not
			// collide) the old file into the existing TempDirectory-resolved staging area
			// unconditionally before the pipeline call whenever it exists on disk; the copy
			// is discarded right below once the real staged path is known, if it turns out
			// no collision occurred.
			if ( $old_state['absolute_file'] !== '' && file_exists( $old_state['absolute_file'] ) ) {
				$collision_backup = $this->backup_collision_target( $old_state['absolute_file'] );
				if ( is_wp_error( $collision_backup ) ) {
					/** @var \WP_Error $collision_backup Narrowed inside is_wp_error() guard (see [internal] #6). */
					return $collision_backup;
				}
				/** @var string $collision_backup Narrowed after is_wp_error() guard (see [internal] #6). */
			}

			$uploaded = $this->run_upload_pipeline( $validated_input, $upload_mode, $old_state['absolute_file'] );
			if ( is_wp_error( $uploaded ) ) {
				return $uploaded;
			}
			/** @var array<string, mixed> $uploaded Narrowed after is_wp_error() guard (see [internal] #6). */

			$staged_file = (string) ( $uploaded['file'] ?? '' );
			$new_mime = (string) ( $uploaded['type'] ?? $new_mime );
			if ( $staged_file === '' || $new_mime === '' ) {
				return new \WP_Error( 'upload_failed', __( 'Uploaded file result is incomplete.', 'plathix' ) );
			}

			// [internal]: no real path collision after all (either the pipeline placed the
			// new file elsewhere, or a test double bypassed reuse_old_filename()) — the
			// backup copy protects nothing and would otherwise leak into temp forever.
			if (
				is_string( $collision_backup )
				&& $collision_backup !== ''
				&& $staged_file !== $old_state['absolute_file']
				&& file_exists( $collision_backup )
			) {
				wp_delete_file( $collision_backup ); // discarding the speculative collision-backup copy once the real staged path proves no collision occurred; local temp path.
				$collision_backup = null;
			}

			// [internal]: update_attached_file() делегирует в update_post_meta(), который
			// возвращает false, если значение НЕ ИЗМЕНИЛОСЬ (WP core "no change" — не
			// ошибка, тот же паттерн, что уже обработан ниже для wp_update_attachment_metadata()).
			// До этого фикса относительный путь ($_wp_attached_file) практически никогда не
			// совпадал со старым (wp_unique_filename() всегда генерировал новое имя) — этот
			// false-negative был недостижим. Теперь unique_filename_callback намеренно
			// переиспользует старый путь (та же директория/дата), поэтому "не изменилось"
			// стало реальным успешным случаем, требующим той же проверки через get_post_meta.
			if (
				! update_attached_file( $attachment_id, $staged_file )
				&& get_post_meta( $attachment_id, '_wp_attached_file', true ) !== _wp_relative_upload_path( $staged_file )
			) {
				return $this->rollback_pre_commit( $attachment_id, $old_state, $staged_file, __( 'Unable to update attachment file path.', 'plathix' ), $collision_backup );
			}

			$post_update = wp_update_post(
				[
					'ID'             => $attachment_id,
					'post_mime_type' => $new_mime,
				],
				true
			);
			if ( is_wp_error( $post_update ) ) {
				/** @var \WP_Error $post_update Narrowed inside is_wp_error() guard (see [internal] #6). */
				return $this->rollback_pre_commit( $attachment_id, $old_state, $staged_file, $post_update->get_error_message(), $collision_backup );
			}

			$new_metadata = $this->build_new_metadata( $attachment_id, $staged_file, $new_mime, $old_state );
			if ( is_wp_error( $new_metadata ) ) {
				/** @var \WP_Error $new_metadata Narrowed inside is_wp_error() guard (see [internal] #6). */
				return $this->rollback_pre_commit( $attachment_id, $old_state, $staged_file, $new_metadata->get_error_message(), $collision_backup );
			}
			/** @var array<string, mixed> $new_metadata Narrowed after is_wp_error() guard (see [internal] #6). */

			// wp_update_attachment_metadata returns false when update_post_meta sees no change — not a real failure.
			$updated_meta = wp_update_attachment_metadata( $attachment_id, $new_metadata );
			if ( $updated_meta === false && wp_get_attachment_metadata( $attachment_id ) !== $new_metadata ) {
				return $this->rollback_pre_commit( $attachment_id, $old_state, $staged_file, __( 'Failed to update attachment metadata.', 'plathix' ), $collision_backup, $new_metadata );
			}

			// [internal]: metadata committed successfully, but WP core may have silently
			// failed to write one or more thumbnail files during generation above — surface
			// that honestly instead of reporting a silent full success.
			if ( $this->is_transformable_image_mime( $new_mime ) ) {
				$missing_sizes = ($this->missing_subsizes_resolver)( $attachment_id );
				if ( $missing_sizes !== [] ) {
					$warnings[] = sprintf(
						'Missing thumbnail sizes after replace: %s',
						implode( ', ', array_keys( $missing_sizes ) )
					);
				}
			}

			$committed = true;
			$version = time();
			$sizes = ($this->sizes_resolver)( $attachment_id );

			// [internal]: успешный commit больше не нуждается в backup-копии оригинала.
			if ( is_string( $collision_backup ) && $collision_backup !== '' && file_exists( $collision_backup ) ) {
				wp_delete_file( $collision_backup ); // deleting the temp collision-backup copy created by backup_collision_target() after a successful commit; local path in the plugin's own temp dir.
			}

			($this->post_cache_cleaner)( $attachment_id );

			try {
				($this->cache_invalidator)( $taxonomy );
			} catch ( \Throwable $throwable ) {
				// Причина сохраняется ([internal]): раньше \Throwable тонул в укороченной
				// строке warnings-массива без трейса; side-effect ошибка по-прежнему не валит
				// успешный replace (осознанное решение — cache invalidation некритична), но
				// теперь полный контекст попадает в лог.
				Logger::error( 'Attachment replace: cache invalidation failed', [ 'attachment_id' => $attachment_id ], $throwable );
				$warnings[] = sprintf( 'Cache invalidation failed: %s', $throwable->getMessage() );
			}

			// [internal]: пути новых thumbnail-файлов передаются явно, чтобы cleanup() не
			// удалил только что созданный thumbnail, чей путь совпал со старым (тот же
			// stem через reuse_old_filename() при коллизии основного файла).
			$new_size_paths = [];
			$new_base_dir = dirname( $staged_file );
			foreach ( (array) ( $new_metadata['sizes'] ?? [] ) as $size ) {
				if ( is_array( $size ) && ! empty( $size['file'] ) ) {
					$new_size_paths[] = $new_base_dir . '/' . ltrim( (string) $size['file'], '/' );
				}
			}

			$warnings = array_merge(
				$warnings,
				$this->cleanup->cleanup(
					$old_state['absolute_file'],
					(array) $old_state['metadata'],
					$staged_file,
					$new_size_paths
				)
			);

			$result = [
				'attachmentId'     => $attachment_id,
				'oldFile'          => $old_state['attached_file'],
				'newFile'          => (string) get_post_meta( $attachment_id, '_wp_attached_file', true ),
				'oldMime'          => $old_state['mime'],
				'newMime'          => $new_mime,
				'url'              => (string) ( $uploaded['url'] ?? '' ),
				'sizes'            => $sizes,
				'version'          => $version,
				'warnings'         => array_values( array_unique( $warnings ) ),
				'partialSuccess'   => $warnings !== [],
				'newWidth'         => (int) ( $new_metadata['width'] ?? 0 ),
				'newHeight'        => (int) ( $new_metadata['height'] ?? 0 ),
				'newFilesizeHuman' => $this->format_new_filesize( $attachment_id ),
			];

			($this->audit_recorder)( $attachment_id, $result, $actor_context );
			($this->hook_dispatcher)( $attachment_id, $result );

			return $result;
		} finally {
			// [internal]: a leftover collision backup at this point means either (a) it was
			// never proven necessary (run_upload_pipeline() failed before the collision
			// check ran, or landed on a different path) — the original was never touched,
			// just discard the speculative copy; or (b) the collision WAS confirmed
			// ($staged_file === old path) but a later step failed via an early `return`
			// that bypassed rollback_pre_commit() (e.g. the 'upload_failed' incomplete-result
			// guard right after the pipeline call) — the original path now holds the NEW
			// content and needs the backup restored onto it. rollback_pre_commit(), when it
			// did run, already consumed the backup — file_exists() guards against a
			// redundant/no-op second attempt either way.
			// [internal]: a confirmed collision (staged path === old path) means the
			// uncommitted-staged-file cleanup below must NEVER touch that path — either
			// rollback_pre_commit() already restored the original onto it (ran earlier in
			// this same request, backup file itself no longer exists — file_exists() below
			// is false), or it is restored right here when rollback_pre_commit() was never
			// reached. Either way, deleting $staged_file afterwards would delete the
			// original replace() just spent this whole package restoring.
			$collision_confirmed = is_string( $staged_file ) && $staged_file !== '' && $staged_file === $old_state['absolute_file'];
			if (
				! $committed
				&& is_string( $collision_backup )
				&& $collision_backup !== ''
				&& file_exists( $collision_backup )
			) {
				if ( $collision_confirmed ) {
					if ( ! @rename( $collision_backup, $old_state['absolute_file'] ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- finally-block restore of the pre-replace original from the plugin's temp dir when rollback_pre_commit() was never reached; local paths.
						Logger::error(
							'Attachment replace: finally-block restore of original from collision backup failed',
							[
								'attachment_id' => $attachment_id,
								'backup_path'   => $collision_backup,
								'target_path'   => $old_state['absolute_file'],
							]
						);
					}
				} else {
					wp_delete_file( $collision_backup ); // finally-block discard of an unused collision-backup copy (the original was never touched); local temp path.
				}
			}

			if ( ! $collision_confirmed && ! $committed && is_string( $staged_file ) && $staged_file !== '' && file_exists( $staged_file ) ) {
				wp_delete_file( $staged_file ); // finally-block rollback deleting the uncommitted staged sideload result (from wp_handle_upload, in the plugin's own temp dir); local path.
			}

			// [internal] (M15): if run_upload_pipeline() failed AFTER staging succeeded,
			// $staged_file above is still null and never covers this earlier sideload
			// staging copy. Guard against double-unlink if both paths happen to match.
			if (
				! $committed
				&& is_string( $sideload_staged_file )
				&& $sideload_staged_file !== ''
				&& $sideload_staged_file !== $staged_file
				&& file_exists( $sideload_staged_file )
			) {
				wp_delete_file( $sideload_staged_file ); // finally-block rollback deleting the uncommitted sideload staging copy (copy() into the plugin's own temp dir in stage_sideload_file()); local path.
			}

			$this->lock->release( $attachment_id, (string) ( $lock['token'] ?? '' ) );
		}
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|\WP_Error
	 */
	private function validate_input_contract(array $input): array|\WP_Error
	{
		$name = (string) ( $input['name'] ?? '' );
		$tmp_name = (string) ( $input['tmp_name'] ?? '' );
		$size = (int) ( $input['size'] ?? 0 );
		$error = (int) ( $input['error'] ?? 0 );

		if ( $name === '' || $tmp_name === '' ) {
			return new \WP_Error( 'invalid_upload', __( 'Upload input must contain name and tmp_name.', 'plathix' ) );
		}

		if ( $error !== 0 ) {
			return new \WP_Error( 'invalid_upload', __( 'Upload input contains a file error.', 'plathix' ) );
		}

		return [
			'name'     => $name,
			'type'     => (string) ( $input['type'] ?? '' ),
			'tmp_name' => $tmp_name,
			'size'     => max( 0, $size ),
			'error'    => $error,
		];
	}

	/**
	 * @return array{attached_file:string,absolute_file:string,mime:string,metadata:array<string,mixed>}
	 */
	private function snapshot_attachment_state(int $attachment_id): array
	{
		$metadata = wp_get_attachment_metadata( $attachment_id );

		return [
			'attached_file' => (string) get_post_meta( $attachment_id, '_wp_attached_file', true ),
			'absolute_file' => (string) get_attached_file( $attachment_id ),
			'mime'          => (string) get_post_mime_type( $attachment_id ),
			'metadata'      => is_array( $metadata ) ? $metadata : [],
		];
	}

	/**
	 * [internal]: копирует старый файл в существующую temp-директорию
	 * ({@see TempDirectory::path()}, тот же резолвер, что уже использует
	 * ensure_temp_dir() для sideload-staging) ДО вызова upload-пайплайна — единственный
	 * момент, когда точно известно, что старый файл ещё цел. Пайплайн (в частности
	 * reuse_old_filename(), [internal]) может, но не обязан, физически перезаписать
	 * этот же путь — известно это станет только по факту (сравнение $staged_file с
	 * $old_absolute_file после вызова), поэтому здесь именно copy(), не rename(): если
	 * коллизии не случится, оригинал должен остаться на месте нетронутым, а лишняя
	 * копия просто удаляется вызывающей стороной сразу после того, как факт известен.
	 *
	 * @return string|\WP_Error абсолютный путь к backup-копии
	 */
	private function backup_collision_target(string $old_absolute_file): string|\WP_Error
	{
		$temp_dir = $this->ensure_temp_dir();
		if ( is_wp_error( $temp_dir ) ) {
			/** @var \WP_Error $temp_dir Narrowed inside is_wp_error() guard (see [internal] #6). */
			return $temp_dir;
		}
		/** @var string $temp_dir Narrowed after is_wp_error() guard (see [internal] #6). */

		$backup_path = rtrim( $temp_dir, '/\\' ) . '/' . uniqid( 'replace_collision_', true ) . '-' . basename( $old_absolute_file );

		if ( ! @copy( $old_absolute_file, $backup_path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- copying the attachment's own original file into the plugin's temp dir before an upload pipeline that may overwrite it, so a restorable copy exists regardless of whether the collision actually happens; local paths, not remote.
			return new \WP_Error( 'collision_backup_failed', __( 'Unable to back up the original file before replacing it.', 'plathix' ) );
		}

		return $backup_path;
	}

	/**
	 * Готовит временную директорию для staging sideload-файла.
	 *
	 * Путь берётся из единого резолвера {@see TempDirectory::path()} — той же
	 * папки, что используют фоновые задачи. Сам резолвер уже создаёт директорию,
	 * здесь остаётся только финальная проверка существования и прав на запись,
	 * чтобы вернуть стабильный код ошибки `tmp_dir_unwritable`.
	 *
	 * @return string|\WP_Error абсолютный путь к директории без trailing-слеша
	 */
	private function ensure_temp_dir(): string|\WP_Error
	{
		$path = rtrim( (string) ( $this->temp_dir_resolver )(), '/\\' );
		if ( $path === '' ) {
			return new \WP_Error( 'tmp_dir_unwritable', __( 'Upload base directory is unavailable.', 'plathix' ) );
		}

		if ( ! is_dir( $path ) && ! wp_mkdir_p( $path ) ) {
			return new \WP_Error( 'tmp_dir_unwritable', __( 'Unable to create Plathix temporary directory.', 'plathix' ) );
		}

		// [internal] ([internal]): на сайте сети, не посещённом Activator'ом
		// (Activator::ensure_temp_dir()), эта директория может впервые появиться именно
		// здесь — лениво, при первом replace/sideload. Без guard-файлов staging-каталог
		// с временными upload-файлами остаётся без защиты от листинга/прямого исполнения.
		// Эталон 1:1 — PresetUploadPipeline::write_dir_guard() (тот же инвариант, что
		// Activator::ensure_guarded_dir()). Идемпотентно — пишется только при отсутствии.
		$this->write_dir_guard( $path );

		if ( ! is_writable( $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- preflight writability check of the plugin's own just-ensured temp dir before staging an upload; a WP_Error is returned on failure, no file is written here.
			return new \WP_Error( 'tmp_dir_unwritable', __( 'Plathix temporary directory is not writable.', 'plathix' ) );
		}

		return $path;
	}

	/**
	 * Идемпотентно кладёт directory-guard (index.php + .htaccess «Deny from all») в $dir,
	 * если файлов ещё нет. Содержимое 1:1 с PresetUploadPipeline::write_dir_guard() и
	 * Activator::ensure_guarded_dir() — единый инвариант защиты plugin-owned upload-каталогов.
	 */
	private function write_dir_guard(string $dir): void
	{
		$index = trailingslashit( $dir ) . 'index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writes a directory-index guard into the plugin's own just-created temp dir; WP_Filesystem credentials-flow may be unavailable and this runs on a local upload path.
		}

		$htaccess = trailingslashit( $dir ) . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Deny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writes an Apache deny-all guard into the plugin's own just-created temp dir; WP_Filesystem credentials-flow may be unavailable and this runs on a local upload path.
		}
	}

	/**
	 * @param array<string, mixed> $input
	 * @param array{mode:string,user_id:int} $actor_context
	 * @return array<string, mixed>|\WP_Error
	 */
	private function validate_svg_if_needed(array $input, array $actor_context): array|\WP_Error
	{
		$extension = strtolower( pathinfo( (string) $input['name'], PATHINFO_EXTENSION ) );
		if ( $extension !== 'svg' && $extension !== 'svgz' ) {
			return $input;
		}

		if ( $extension === 'svgz' ) {
			return new \WP_Error( 'invalid_mime', __( 'Compressed SVGZ files are not supported.', 'plathix' ) );
		}

		if ( ! is_readable( (string) $input['tmp_name'] ) ) {
			return new \WP_Error( 'invalid_upload', __( 'SVG file is not readable.', 'plathix' ) );
		}

		$contents = file_get_contents( (string) $input['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads the sanitized upload's tmp_name (from wp_handle_upload, is_readable-checked above) to sanitize the SVG; local path, not a remote URL.
		if ( $contents === false ) {
			return new \WP_Error( 'invalid_upload', __( 'Unable to read SVG file.', 'plathix' ) );
		}

		// Markup-политика (санитайзинг + safe-mode reject) вынесена в единый core-владелец
		// SvgUploadPolicy ([internal]) — тот же источник, что использует Modules\Svg\SvgSupport,
		// вместо второй копии правила здесь. safe-mode-флаг сервис по-прежнему определяет сам.
		$sanitized = $this->svg_upload_policy->sanitizeMarkup( $contents, $this->is_svg_safe_mode() );
		if ( is_wp_error( $sanitized ) ) {
			return $sanitized;
		}
		/** @var string $sanitized Narrowed after is_wp_error() guard (see [internal] #6). */

		if ( $actor_context['mode'] !== 'system_cli' && $actor_context['user_id'] <= 0 ) {
			return new \WP_Error( 'forbidden', __( 'SVG replacement requires an identified actor context.', 'plathix' ) );
		}

		if ( file_put_contents( (string) $input['tmp_name'], $sanitized ) === false ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writes sanitized markup back over the same upload tmp_name (from wp_handle_upload) before it is moved into place; local temp path.
			return new \WP_Error( 'invalid_upload', __( 'Unable to rewrite sanitized SVG file.', 'plathix' ) );
		}

		return $input;
	}

	private function is_svg_safe_mode(): bool
	{
		$default = is_multisite();

		return (bool) get_option( 'plathix_svg_safe_mode', $default );
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|\WP_Error
	 */
	private function stage_sideload_file(array $input, string $temp_dir): array|\WP_Error
	{
		$source = (string) $input['tmp_name'];
		if ( $source === '' || ! is_readable( $source ) ) {
			return new \WP_Error( 'invalid_upload', __( 'Sideload source file is not readable.', 'plathix' ) );
		}

		$target = rtrim( $temp_dir, '/\\' ) . '/' . uniqid( 'replace_', true ) . '-' . preg_replace( '/[^A-Za-z0-9._-]/', '-', basename( (string) $input['name'] ) );
		if ( ! copy( $source, $target ) ) {
			return new \WP_Error( 'invalid_upload', __( 'Unable to stage sideload file into temporary directory.', 'plathix' ) );
		}

		$input['tmp_name'] = $target;

		return $input;
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|\WP_Error
	 */
	private function run_upload_pipeline(array $input, string $upload_mode, string $old_absolute_file): array|\WP_Error
	{
		$overrides = [ 'test_form' => false ];
		// [internal]: без этого callback wp_unique_filename() резервирует новое имя ПОКА
		// старый физический файл ещё существует (cleanup() удаляет его только после
		// успешного commit, строка ~231 — порядок НЕ меняется, чтобы сохранить
		// rollback-safety, WP Senior Dev skeptic pass) — коллизия имён гарантирована,
		// URL меняется при КАЖДОМ Replace, ломая статичные ссылки в уже опубликованном
		// Gutenberg/Elementor-контенте. Форсируем переиспользование старого basename (тот
		// же stem, новое расширение из аргумента $ext) — WP-native параметр, задокументирован
		// в wp_unique_filename(), не новый механизм.
		if ( $old_absolute_file !== '' ) {
			$overrides['unique_filename_callback'] = function (string $dir, string $name, ?string $ext) use ($old_absolute_file): string {
				return $this->reuse_old_filename( $old_absolute_file, $ext );
			};
		}
		$result = $upload_mode === 'sideload'
			? ($this->sideload_runner)( $input, $overrides )
			: ($this->upload_runner)( $input, $overrides );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! is_array( $result ) ) {
			return new \WP_Error( 'upload_failed', __( 'Upload handler returned an invalid result.', 'plathix' ) );
		}

		if ( ! empty( $result['error'] ) ) {
			return new \WP_Error( 'upload_failed', (string) $result['error'] );
		}

		return $result;
	}

	/**
	 * @param array{attached_file:string,absolute_file:string,mime:string,metadata:array<string,mixed>} $old_state
	 * @return array<string,mixed>|\WP_Error
	 */
	private function build_new_metadata(int $attachment_id, string $file, string $new_mime, array $old_state): array|\WP_Error
	{
		$old_is_image = $this->is_transformable_image_mime( $old_state['mime'] );
		$new_is_image = $this->is_transformable_image_mime( $new_mime );

		if ( $new_is_image ) {
			$metadata = ($this->metadata_generator)( $attachment_id, $file );
			if ( ! is_array( $metadata ) ) {
				return new \WP_Error( 'metadata_generation_failed', __( 'Failed to generate attachment metadata.', 'plathix' ) );
			}

			return $metadata;
		}

		// $new_is_image is always false here (the $new_is_image === true branch returned above).
		if ( $old_is_image ) {
			return [];
		}

		$metadata = ($this->metadata_generator)( $attachment_id, $file );

		return is_array( $metadata ) ? $metadata : [];
	}

	/**
	 * Человекочитаемый размер нового файла для панели метаданных модалки ([internal]).
	 * Форматирует через WP core size_format() — та же функция, что рендерит core-панель
	 * `.attachment-info .file-size`, чтобы не расходиться с ней по локали/суффиксам.
	 */
	private function format_new_filesize(int $attachment_id): string {
		$path = get_attached_file( $attachment_id );
		$bytes = is_string( $path ) && $path !== '' ? filesize( $path ) : false;

		return size_format( is_int( $bytes ) ? $bytes : 0 );
	}

	private function is_transformable_image_mime(string $mime): bool
	{
		return str_starts_with( $mime, 'image/' ) && $mime !== 'image/svg+xml';
	}

	/**
	 * unique_filename_callback для wp_handle_upload()/wp_handle_sideload() ([internal]):
	 * возвращает старый basename (stem) с расширением НОВОГО файла — игнорирует
	 * $name/$dir, переданные WP core (они уже основаны на имени ЗАГРУЖАЕМОГО файла, не
	 * старом). Без учёта $ext итоговый файл получил бы старое расширение поверх нового
	 * содержимого (например .jpg-имя с PNG-байтами внутри) — тот самый mismatch, которого
	 * нет при обычной загрузке. Не проверяет коллизию: старый файл — тот же самый ресурс,
	 * который мы заменяем, а не новый файл, поэтому "конфликт" неверен по построению.
	 *
	 * $ext приходит от wp_unique_filename() УЖЕ С ТОЧКОЙ ('.jpg') или пустой строкой —
	 * подтверждено чтением реального core-исходника (wp-includes/functions.php:2591-2592,
	 * $ext = '.' . $ext перед вызовом callback), не только PHPDoc-стабов. Callback обязан
	 * вернуть ПОЛНОЕ имя файла (basename с расширением), не только stem — WP core
	 * присваивает возврат напрямую в $filename без дальнейшей конкатенации.
	 */
	private function reuse_old_filename(string $old_absolute_file, ?string $ext): string
	{
		$old_basename = basename( $old_absolute_file );
		$stem = pathinfo( $old_basename, PATHINFO_FILENAME );
		$new_ext = is_string( $ext ) ? $ext : '';
		if ( $new_ext === '' ) {
			$old_ext = pathinfo( $old_basename, PATHINFO_EXTENSION );
			$new_ext = $old_ext !== '' ? '.' . $old_ext : '';
		}

		return $stem . $new_ext;
	}

	/**
	 * @param array{attached_file:string,absolute_file:string,mime:string,metadata:array<string,mixed>} $old_state
	 * @param array<string,mixed>|null $new_metadata metadata уже вычислен build_new_metadata() до этого сбоя ([internal],
	 *                                                дефект 3) — thumbnail-файлы, физически созданные wp_generate_attachment_metadata(),
	 *                                                нужно удалить, иначе они остаются orphaned на диске без соответствующей
	 *                                                записи в откаченных метаданных. null — metadata до этого шага не дошла,
	 *                                                thumbnails не создавались, unlink не требуется.
	 */
	private function rollback_pre_commit(int $attachment_id, array $old_state, string $uploaded_file, string $message, ?string $collision_backup = null, ?array $new_metadata = null): \WP_Error
	{
		if ( $old_state['absolute_file'] !== '' ) {
			update_attached_file( $attachment_id, $old_state['absolute_file'] );
		}

		wp_update_post(
			[
				'ID'             => $attachment_id,
				'post_mime_type' => $old_state['mime'],
			]
		);

		// [internal]: a non-null $collision_backup here means replace() already confirmed
		// (by comparing $staged_file to the old path after the pipeline ran) that the new
		// file landed on the same path as the old one — $uploaded_file IS
		// $old_state['absolute_file'] physically now, holding the NEW content. A plain
		// unlink would delete the only remaining copy of the original (the pre-pipeline
		// copy is what backup_collision_target() saved). Restore real bytes by moving the
		// backup onto the corrupted path; if there is no backup (paths never collided, or
		// it was already discarded once proven unnecessary), the previous
		// unlink-the-staged-result behaviour is unchanged.
		if ( is_string( $collision_backup ) && $collision_backup !== '' && file_exists( $collision_backup ) ) {
			if ( ! @rename( $collision_backup, $old_state['absolute_file'] ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- restoring the pre-replace original from the plugin's temp dir back to its attachment path after a failed replace; local paths, not remote.
				return new \WP_Error(
					'metadata_generation_failed',
					sprintf(
						/* translators: 1: original error message, 2: path to the backup copy that still holds the original file */
						__( '%1$s Additionally, the original file could not be restored from its backup at %2$s — restore it manually before retrying.', 'plathix' ),
						$message,
						$collision_backup
					)
				);
			}
		} elseif ( $uploaded_file !== '' && file_exists( $uploaded_file ) ) {
			wp_delete_file( $uploaded_file ); // rollback deleting the staged sideload result (from wp_handle_upload, in the plugin's own temp dir) after metadata generation failed; local path.
		}

		// [internal] (дефект 3): build_new_metadata() уже физически создал thumbnail-файлы
		// на диске (wp_generate_attachment_metadata()) до этого сбоя — метаданные в БД не
		// откатываются на них явно (update_attached_file()/wp_update_post() выше трогают
		// только путь главного файла и mime), значит без явного unlink здесь эти файлы
		// остаются orphaned. Тот же паттерн построения path, что AttachmentFileCleanup::
		// collect_size_paths() (base_dir + ltrim($file, '/')) — не вызывается напрямую,
		// т.к. это приватный метод другого класса; та же логика инлайн здесь, узкий scope.
		if ( is_array( $new_metadata ) && is_array( $new_metadata['sizes'] ?? null ) && $uploaded_file !== '' ) {
			$base_dir = dirname( $uploaded_file );
			foreach ( $new_metadata['sizes'] as $size ) {
				if ( ! is_array( $size ) || empty( $size['file'] ) ) {
					continue;
				}
				$thumbnail_path = $base_dir . '/' . ltrim( (string) $size['file'], '/' );
				if ( file_exists( $thumbnail_path ) ) {
					wp_delete_file( $thumbnail_path ); // rollback deleting an orphaned thumbnail already generated by wp_generate_attachment_metadata() before this failure; local path under the attachment's own directory.
				}
			}
		}

		return new \WP_Error( 'metadata_generation_failed', $message );
	}

	/**
	 * @return array{ext:string|false,type:string|false,proper_filename:string|false}|array<string,mixed>
	 */
	private function default_filetype_validator(string $file, string $filename): array
	{
		return wp_check_filetype_and_ext( $file, $filename, null );
	}

	/**
	 * @param array<string,mixed> $input
	 * @param array<string,mixed> $overrides
	 * @return array<string,mixed>
	 */
	private function default_upload_runner(array $input, array $overrides): array
	{
		return wp_handle_upload( $input, $overrides );
	}

	/**
	 * @param array<string,mixed> $input
	 * @param array<string,mixed> $overrides
	 * @return array<string,mixed>
	 */
	private function default_sideload_runner(array $input, array $overrides): array
	{
		return wp_handle_sideload( $input, $overrides );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function default_metadata_generator(int $attachment_id, string $file): array
	{
		return (array) wp_generate_attachment_metadata( $attachment_id, $file );
	}

	/**
	 * Возвращает `sizes` в формате, который реально понимает Backbone-модель
	 * `wp.media.model.Attachment` на фронте (url/width/height/orientation) — НЕ формат
	 * `media_details.sizes` из REST API `wp/v2/media` (другие ключи). `wp_prepare_
	 * attachment_for_js()` — тот же WP core сериализатор, которым сам WP кормит `wp.media`
	 * JS; учитывает фильтр `image_downsize` (CDN/offload-совместимость) и
	 * `image_constrain_size_for_editor()`, которые ручная сборка URL пропустила бы
	 * ([internal], [internal]).
	 *
	 * @return array<string,mixed>
	 */
	private function default_sizes_resolver(int $attachment_id): array
	{
		$js_data = wp_prepare_attachment_for_js( $attachment_id );

		return is_array( $js_data ) && is_array( $js_data['sizes'] ?? null ) ? $js_data['sizes'] : [];
	}

	/**
	 * [internal]: WP core (`_wp_make_subsizes()`, wp-admin/includes/image.php) silently
	 * swallows a thumbnail write failure — the error never surfaces as a WP_Error from
	 * `wp_generate_attachment_metadata()`, it just leaves that size out of the result.
	 * `wp_get_missing_image_subsizes()` is the same WP core function `wp_update_image_subsizes()`
	 * uses for "Restore missing image sizes" — it already filters out upscale sizes that a
	 * small image should never get (via `image_resize_dimensions()`), so this call does not
	 * duplicate that filtering logic locally (see spec rejected alternatives). Must run AFTER
	 * `wp_update_attachment_metadata()` — the function reads metadata from the DB, not a
	 * parameter.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function default_missing_subsizes_resolver(int $attachment_id): array
	{
		return (array) wp_get_missing_image_subsizes( $attachment_id );
	}

	/**
	 * Дефолтный резолвер временной директории — единый источник истины Plathix.
	 *
	 * Вынесен в инжектируемый callable, чтобы sideload-ветку можно было покрыть
	 * тестами без подмены глобальных WP-функций, которые зовёт TempDirectory.
	 */
	private function default_temp_dir_resolver(): string
	{
		return ( new \Plathix\Infrastructure\TempDirectory() )->path();
	}
}
