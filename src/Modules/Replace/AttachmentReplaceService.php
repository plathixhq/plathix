<?php

declare(strict_types=1);

namespace Plathix\Modules\Replace;

use Plathix\Core\AttachmentFileCleanup;
use Plathix\Infrastructure\Logger;
use Plathix\PublicApi\SvgApi;
use Plathix\Svg\Sanitizer\Sanitizer;
use Plathix\Svg\SvgUploadPolicy;

final class AttachmentReplaceService
{
	private readonly AttachmentReplaceLock $lock;
	private readonly AttachmentFileCleanup $cleanup;
	private readonly Sanitizer $svgSanitizer;
	private readonly SvgUploadPolicy $svg_upload_policy;
	private readonly ReplaceAuthorization $authorization;
	private readonly SvgApi $svg_api;

	/** @var \Closure(string,string): (array{ext:string|false,type:string|false,proper_filename:string|false}|array<string,mixed>) */
	private \Closure $filetype_validator;
	/** @var \Closure(array<string,mixed>, array<string,mixed>): (array<string,mixed>|\WP_Error) */
	private \Closure $upload_runner;
	/** @var \Closure(array<string,mixed>, array<string,mixed>): (array<string,mixed>|\WP_Error) */
	private \Closure $sideload_runner;

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
		?Sanitizer $svgSanitizer = null,
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
		?callable $missing_subsizes_resolver = null,
		?SvgApi $svg_api = null
	) {
		$this->lock = $lock ?? new AttachmentReplaceLock();
		$this->cleanup = $cleanup ?? new AttachmentFileCleanup();
		$this->svgSanitizer = $svgSanitizer ?? new Sanitizer();

		$this->svg_upload_policy = $svg_upload_policy ?? new SvgUploadPolicy( $this->svgSanitizer );

		$this->authorization = $authorization ?? new ReplaceAuthorization();
		$this->svg_api = $svg_api ?? new SvgApi();
		$this->filetype_validator = \Closure::fromCallable( $filetype_validator ?? [ $this, 'defaultFiletypeValidator' ] );
		$this->upload_runner = \Closure::fromCallable( $upload_runner ?? [ $this, 'defaultUploadRunner' ] );
		$this->sideload_runner = \Closure::fromCallable( $sideload_runner ?? [ $this, 'defaultSideloadRunner' ] );
		$this->temp_dir_resolver = \Closure::fromCallable( $temp_dir_resolver ?? [ $this, 'defaultTempDirResolver' ] );
		$this->metadata_generator = \Closure::fromCallable( $metadata_generator ?? [ $this, 'defaultMetadataGenerator' ] );
		$this->post_cache_cleaner = \Closure::fromCallable( $post_cache_cleaner ?? 'clean_post_cache' );
		$this->cache_invalidator = \Closure::fromCallable(
			$cache_invalidator ?? static function (string $taxonomy): void {
				\Plathix\Infrastructure\Cache::onAttachmentChange( null, $taxonomy );
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
		$this->sizes_resolver = \Closure::fromCallable( $sizes_resolver ?? [ $this, 'defaultSizesResolver' ] );
		$this->missing_subsizes_resolver = \Closure::fromCallable( $missing_subsizes_resolver ?? [ $this, 'defaultMissingSubsizesResolver' ] );
	}

	/**
	 * @param array<string, mixed> $input
	 * @param array<string, mixed> $options
	 * @return array{attachmentId: int, oldFile: string, newFile: string, oldMime: string, newMime: string, url: string, sizes: array<string, mixed>, version: int, warnings: list<string>, partialSuccess: bool, newWidth: int, newHeight: int, newFilesizeHuman: string}|\WP_Error
	 */
	public function replace(int $attachment_id, array $input, array $options = []): array|\WP_Error
	{
		$validated_input = $this->validateInputContract( $input );
		if ( is_wp_error( $validated_input ) ) {
			return $validated_input;
		}
		/**
		 * @var array<string, mixed> $validated_input
		 */


		$actor_context = $this->authorization->normalize( $options['actor_context'] ?? [] );
		$post = get_post( $attachment_id );
		if ( ! $post instanceof \WP_Post || $post->post_type !== 'attachment' ) {
			return new \WP_Error( 'invalid_attachment', __( 'Attachment does not exist.', 'plathix' ) );
		}

		if ( ! $this->authorization->canReplace( $actor_context, $attachment_id ) ) {
			return new \WP_Error( 'forbidden', __( 'Actor is not allowed to replace this attachment.', 'plathix' ) );
		}

		$lock = $this->lock->acquire( $attachment_id );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}
		/**
		 * @var array{token: string, timestamp: int} $lock
		 */


		$staged_file = null;
		$sideload_staged_file = null;
		$committed = false;
		$warnings = [];
		$old_state = $this->snapshotAttachmentState( $attachment_id );
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
			$validated_input = $this->validateSvgIfNeeded( $validated_input, $actor_context );
			if ( is_wp_error( $validated_input ) ) {
				return $validated_input;
			}
			/**
			 * @var array<string, mixed> $validated_input
			 */


			$upload_mode = sanitize_key( (string) ( $options['upload_mode'] ?? 'upload' ) );
			$temp_dir = null;
			if ( $upload_mode === 'sideload' ) {
				$temp_dir = $this->ensureTempDir();
				if ( is_wp_error( $temp_dir ) ) {
					/**
					 * @var \WP_Error $temp_dir
					 */

					return $temp_dir;
				}
				/**
				 * @var string $temp_dir
				 */

				$validated_input = $this->stageSideloadFile( $validated_input, $temp_dir );
				if ( is_wp_error( $validated_input ) ) {
					return $validated_input;
				}
				/**
				 * @var array<string, mixed> $validated_input
				 */

				// only assigned after runUploadPipeline() succeeds (below), so if the
				// pipeline fails, this staged sideload copy would otherwise never be unlinked.
				$sideload_staged_file = (string) ( $validated_input['tmp_name'] ?? '' );
			}

			// can make the new physical file path collide with the old one — the old file
			// is about to be physically overwritten before commit, while
			// rollbackPreCommit() only knows how to restore metadata, not bytes
			// (snapshotAttachmentState() never captured a byte-level backup). Whether the
			// collision actually happens depends on runUploadPipeline()'s result — a
			// mocked upload_runner() in tests may return a path that never goes through
			// reuseOldFilename() at all — so the real path is only known AFTER the
			// pipeline runs, by which point an overwrite would already have happened. Copy
			// (not move — the old file must stay in place for a pipeline that does not
			// collide) the old file into the existing TempDirectory-resolved staging area
			// unconditionally before the pipeline call whenever it exists on disk; the copy
			// is discarded right below once the real staged path is known, if it turns out
			// no collision occurred.
			if ( $old_state['absolute_file'] !== '' && file_exists( $old_state['absolute_file'] ) ) {
				$collision_backup = $this->backupCollisionTarget( $old_state['absolute_file'] );
				if ( is_wp_error( $collision_backup ) ) {
					/**
					 * @var \WP_Error $collision_backup
					 */

					return $collision_backup;
				}
				/**
				 * @var string $collision_backup
				 */

			}

			$uploaded = $this->runUploadPipeline( $validated_input, $upload_mode, $old_state['absolute_file'] );
			if ( is_wp_error( $uploaded ) ) {
				return $uploaded;
			}
			/**
			 * @var array<string, mixed> $uploaded
			 */


			$staged_file = (string) ( $uploaded['file'] ?? '' );
			$new_mime = (string) ( $uploaded['type'] ?? $new_mime );
			if ( $staged_file === '' || $new_mime === '' ) {
				return new \WP_Error( 'upload_failed', __( 'Uploaded file result is incomplete.', 'plathix' ) );
			}

			// new file elsewhere, or a test double bypassed reuseOldFilename()) — the
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

			if (
				! update_attached_file( $attachment_id, $staged_file )
				&& get_post_meta( $attachment_id, '_wp_attached_file', true ) !== _wp_relative_upload_path( $staged_file )
			) {
				return $this->rollbackPreCommit( $attachment_id, $old_state, $staged_file, __( 'Unable to update attachment file path.', 'plathix' ), $collision_backup );
			}

			$post_update = wp_update_post(
				[
					'ID'             => $attachment_id,
					'post_mime_type' => $new_mime,
				],
				true
			);
			if ( is_wp_error( $post_update ) ) {
				/**
				 * @var \WP_Error $post_update
				 */

				return $this->rollbackPreCommit( $attachment_id, $old_state, $staged_file, $post_update->get_error_message(), $collision_backup );
			}

			$new_metadata = $this->buildNewMetadata( $attachment_id, $staged_file, $new_mime, $old_state );
			if ( is_wp_error( $new_metadata ) ) {
				/**
				 * @var \WP_Error $new_metadata
				 */

				return $this->rollbackPreCommit( $attachment_id, $old_state, $staged_file, $new_metadata->get_error_message(), $collision_backup );
			}
			/**
			 * @var array<string, mixed> $new_metadata
			 */


			// wp_update_attachment_metadata returns false when update_post_meta sees no change — not a real failure.
			$updated_meta = wp_update_attachment_metadata( $attachment_id, $new_metadata );
			if ( $updated_meta === false && wp_get_attachment_metadata( $attachment_id ) !== $new_metadata ) {
				return $this->rollbackPreCommit( $attachment_id, $old_state, $staged_file, __( 'Failed to update attachment metadata.', 'plathix' ), $collision_backup, $new_metadata );
			}

			// failed to write one or more thumbnail files during generation above — surface
			// that honestly instead of reporting a silent full success.
			if ( $this->isTransformableImageMime( $new_mime ) ) {
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

			if ( is_string( $collision_backup ) && $collision_backup !== '' && file_exists( $collision_backup ) ) {
				wp_delete_file( $collision_backup ); // deleting the temp collision-backup copy created by backupCollisionTarget() after a successful commit; local path in the plugin's own temp dir.
			}

			($this->post_cache_cleaner)( $attachment_id );

			try {
				($this->cache_invalidator)( $taxonomy );
			} catch ( \Throwable $throwable ) {

				Logger::error( 'Attachment replace: cache invalidation failed', [ 'attachment_id' => $attachment_id ], $throwable );
				$warnings[] = sprintf( 'Cache invalidation failed: %s', $throwable->getMessage() );
			}

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
				'newFilesizeHuman' => $this->formatNewFilesize( $attachment_id ),
			];

			($this->audit_recorder)( $attachment_id, $result, $actor_context );
			($this->hook_dispatcher)( $attachment_id, $result );

			return $result;
		} finally {

			// never proven necessary (runUploadPipeline() failed before the collision
			// check ran, or landed on a different path) — the original was never touched,
			// just discard the speculative copy; or (b) the collision WAS confirmed
			// ($staged_file === old path) but a later step failed via an early `return`
			// that bypassed rollbackPreCommit() (e.g. the 'upload_failed' incomplete-result
			// guard right after the pipeline call) — the original path now holds the NEW
			// content and needs the backup restored onto it. rollbackPreCommit(), when it
			// did run, already consumed the backup — file_exists() guards against a
			// redundant/no-op second attempt either way.

			// uncommitted-staged-file cleanup below must NEVER touch that path — either
			// rollbackPreCommit() already restored the original onto it (ran earlier in
			// this same request, backup file itself no longer exists — file_exists() below
			// is false), or it is restored right here when rollbackPreCommit() was never
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
					if ( ! @rename( $collision_backup, $old_state['absolute_file'] ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- finally-block restore of the pre-replace original from the plugin's temp dir when rollbackPreCommit() was never reached; local paths.
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

			// $staged_file above is still null and never covers this earlier sideload
			// staging copy. Guard against double-unlink if both paths happen to match.
			if (
				! $committed
				&& is_string( $sideload_staged_file )
				&& $sideload_staged_file !== ''
				&& $sideload_staged_file !== $staged_file
				&& file_exists( $sideload_staged_file )
			) {
				wp_delete_file( $sideload_staged_file ); // finally-block rollback deleting the uncommitted sideload staging copy (copy() into the plugin's own temp dir in stageSideloadFile()); local path.
			}

			$this->lock->release( $attachment_id, (string) ( $lock['token'] ?? '' ) );
		}
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|\WP_Error
	 */
	private function validateInputContract(array $input): array|\WP_Error
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
	private function snapshotAttachmentState(int $attachment_id): array
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
	 * @return string|\WP_Error
	 */

	private function backupCollisionTarget(string $old_absolute_file): string|\WP_Error
	{
		$temp_dir = $this->ensureTempDir();
		if ( is_wp_error( $temp_dir ) ) {
			/**
			 * @var \WP_Error $temp_dir
			 */

			return $temp_dir;
		}
		/**
		 * @var string $temp_dir
		 */


		$backup_path = rtrim( $temp_dir, '/\\' ) . '/' . uniqid( 'replace_collision_', true ) . '-' . basename( $old_absolute_file );

		if ( ! @copy( $old_absolute_file, $backup_path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- copying the attachment's own original file into the plugin's temp dir before an upload pipeline that may overwrite it, so a restorable copy exists regardless of whether the collision actually happens; local paths, not remote.
			return new \WP_Error( 'collision_backup_failed', __( 'Unable to back up the original file before replacing it.', 'plathix' ) );
		}

		return $backup_path;
	}

	/**
	 * @return string|\WP_Error
	 */

	private function ensureTempDir(): string|\WP_Error
	{
		$path = rtrim( (string) ( $this->temp_dir_resolver )(), '/\\' );
		if ( $path === '' ) {
			return new \WP_Error( 'tmp_dir_unwritable', __( 'Upload base directory is unavailable.', 'plathix' ) );
		}

		if ( ! is_dir( $path ) && ! wp_mkdir_p( $path ) ) {
			return new \WP_Error( 'tmp_dir_unwritable', __( 'Unable to create Plathix temporary directory.', 'plathix' ) );
		}

		$this->writeDirGuard( $path );

		if ( ! is_writable( $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- preflight writability check of the plugin's own just-ensured temp dir before staging an upload; a WP_Error is returned on failure, no file is written here.
			return new \WP_Error( 'tmp_dir_unwritable', __( 'Plathix temporary directory is not writable.', 'plathix' ) );
		}

		return $path;
	}

	private function writeDirGuard(string $dir): void
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
	private function validateSvgIfNeeded(array $input, array $actor_context): array|\WP_Error
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

		if ( $actor_context['mode'] !== 'system_cli' && ! $this->svg_api->currentUserCanUploadSvg() ) {
			return new \WP_Error( 'forbidden', __( 'Your role is not allowed to upload SVG files.', 'plathix' ) );
		}

		$sanitized = $this->svg_upload_policy->enforceUploadLimitsAndSanitize( (string) $input['tmp_name'], $this->isSvgSafeMode() );
		if ( is_wp_error( $sanitized ) ) {
			return $sanitized;
		}
		/**
		 * @var string $sanitized
		 */


		if ( $actor_context['mode'] !== 'system_cli' && $actor_context['user_id'] <= 0 ) {
			return new \WP_Error( 'forbidden', __( 'SVG replacement requires an identified actor context.', 'plathix' ) );
		}

		if ( file_put_contents( (string) $input['tmp_name'], $sanitized ) === false ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writes sanitized markup back over the same upload tmp_name (from wp_handle_upload) before it is moved into place; local temp path.
			return new \WP_Error( 'invalid_upload', __( 'Unable to rewrite sanitized SVG file.', 'plathix' ) );
		}

		return $input;
	}

	private function isSvgSafeMode(): bool
	{
		return ( new SvgApi() )->isSafeMode();
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|\WP_Error
	 */
	private function stageSideloadFile(array $input, string $temp_dir): array|\WP_Error
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
	private function runUploadPipeline(array $input, string $upload_mode, string $old_absolute_file): array|\WP_Error
	{
		$overrides = [ 'test_form' => false ];

		if ( $old_absolute_file !== '' ) {
			$overrides['unique_filename_callback'] = function (string $dir, string $name, ?string $ext) use ($old_absolute_file): string {
				return $this->reuseOldFilename( $old_absolute_file, $ext );
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
	private function buildNewMetadata(int $attachment_id, string $file, string $new_mime, array $old_state): array|\WP_Error
	{
		$old_is_image = $this->isTransformableImageMime( $old_state['mime'] );
		$new_is_image = $this->isTransformableImageMime( $new_mime );

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

	private function formatNewFilesize(int $attachment_id): string {
		$path = get_attached_file( $attachment_id );
		$bytes = is_string( $path ) && $path !== '' ? filesize( $path ) : false;

		return size_format( is_int( $bytes ) ? $bytes : 0 );
	}

	private function isTransformableImageMime(string $mime): bool
	{
		return str_starts_with( $mime, 'image/' ) && $mime !== 'image/svg+xml';
	}

	private function reuseOldFilename(string $old_absolute_file, ?string $ext): string
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
	 * @param array<string,mixed>|null $new_metadata
	 */

	private function rollbackPreCommit(int $attachment_id, array $old_state, string $uploaded_file, string $message, ?string $collision_backup = null, ?array $new_metadata = null): \WP_Error
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

		// (by comparing $staged_file to the old path after the pipeline ran) that the new
		// file landed on the same path as the old one — $uploaded_file IS
		// $old_state['absolute_file'] physically now, holding the NEW content. A plain
		// unlink would delete the only remaining copy of the original (the pre-pipeline
		// copy is what backupCollisionTarget() saved). Restore real bytes by moving the
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
	private function defaultFiletypeValidator(string $file, string $filename): array
	{
		return wp_check_filetype_and_ext( $file, $filename, null );
	}

	/**
	 * @param array<string,mixed> $input
	 * @param array<string,mixed> $overrides
	 * @return array<string,mixed>
	 */
	private function defaultUploadRunner(array $input, array $overrides): array
	{
		return wp_handle_upload( $input, $overrides );
	}

	/**
	 * @param array<string,mixed> $input
	 * @param array<string,mixed> $overrides
	 * @return array<string,mixed>
	 */
	private function defaultSideloadRunner(array $input, array $overrides): array
	{
		return wp_handle_sideload( $input, $overrides );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function defaultMetadataGenerator(int $attachment_id, string $file): array
	{
		return (array) wp_generate_attachment_metadata( $attachment_id, $file );
	}

	/**
	 * @return array<string,mixed>
	 */

	private function defaultSizesResolver(int $attachment_id): array
	{
		$js_data = wp_prepare_attachment_for_js( $attachment_id );

		return is_array( $js_data ) && is_array( $js_data['sizes'] ?? null ) ? $js_data['sizes'] : [];
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */

	private function defaultMissingSubsizesResolver(int $attachment_id): array
	{
		return (array) wp_get_missing_image_subsizes( $attachment_id );
	}

	private function defaultTempDirResolver(): string
	{
		return ( new \Plathix\Infrastructure\TempDirectory() )->path();
	}
}
