<?php

declare(strict_types=1);

namespace Plathix\Http;

use Plathix\Core\Taxonomy;
use Plathix\Infrastructure\RateLimiter;
use Plathix\Modules\Replace\AttachmentReplaceService;

final class ReplaceRestController
{
	use RestControllerHelpers;

	private ?AttachmentReplaceService $attachmentReplaceService = null;

	public function __construct(
		private readonly RateLimiter $rateLimiter
	) {
	}

	public function replaceAttachmentMedia(
		\WP_REST_Request $request,
		?\Closure $replace_file_resolver = null,
		?\Closure $attachment_replace_runner = null
	): \WP_REST_Response {
		if ( ! $this->rateLimiter->attempt( 'replace_attachment', get_current_user_id(), max: 15, window: 60 ) ) {
			return new \WP_REST_Response( [ 'message' => __( 'Too many requests.', 'plathix' ) ], 429 );
		}

		$post_type = sanitize_key( (string) ( $request->get_param( 'post_type' ) ?: 'attachment' ) );
		if ( $post_type !== 'attachment' ) {
			return new \WP_REST_Response( [ 'message' => __( 'Media replace is only available for attachments.', 'plathix' ), 'code' => 'invalid_attachment' ], 422 );
		}

		$attachment_id = absint( $request->get_param( 'id' ) );
		if ( $attachment_id <= 0 ) {
			return new \WP_REST_Response( [ 'message' => __( 'Attachment ID is required.', 'plathix' ), 'code' => 'invalid_attachment' ], 400 );
		}

		$file = $this->resolveReplaceFileInput( $request, $replace_file_resolver );
		if ( ! is_array( $file ) ) {
			return new \WP_REST_Response( [ 'message' => __( 'Replacement file is required.', 'plathix' ), 'code' => 'invalid_upload' ], 400 );
		}

		// wp_handle_sideload() and wp_generate_attachment_metadata() live in
		// wp-admin/includes/{file,image}.php, which WordPress does not load on
		// REST requests. Load them on demand (core does the same in AJAX actions).
		// Guarded by function_exists so the load is skipped when the functions are
		// already available (normal WP runtime) or stubbed (tests).
		if ( ! function_exists( 'wp_handle_sideload' ) ) {
			require_once \ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once \ABSPATH . 'wp-admin/includes/image.php';
		}

		$options = [
			'actor_context' => [
				'mode'    => 'wp_user',
				'user_id' => get_current_user_id(),
			],
			// REST uploads already arrive as a prepared tmp file payload.
			// Sideload handling avoids the strict browser-upload test that can
			// reject programmatic multipart requests in some environments.
			'upload_mode' => 'sideload',
			'taxonomy'    => Taxonomy::taxonomyForPostType( 'attachment' ),
		];

		$result = $this->runOptionalOverride(
			$attachment_replace_runner,
			fn (): array|\WP_Error => $this->attachmentReplaceService()->replace( $attachment_id, $file, $options ),
			$attachment_id,
			$file,
			$options
		);

		if ( is_wp_error( $result ) ) {
			return $this->errorResponse( $result, $this->replaceErrorStatus( (string) $result->get_error_code() ) );
		}

		$result['message'] = __( 'File replaced successfully', 'plathix' );

		return new \WP_REST_Response( $result, 200 );
	}

	private function attachmentReplaceService(): AttachmentReplaceService {
		return $this->attachmentReplaceService ??= new AttachmentReplaceService();
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private function resolveReplaceFileInput(\WP_REST_Request $request, ?\Closure $resolver = null): ?array {
		$file = $this->runOptionalOverride(
			$resolver,
			static function (\WP_REST_Request $request): ?array {
				if ( method_exists( $request, 'get_file_params' ) ) {
					$params = (array) $request->get_file_params();
					if ( isset( $params['file'] ) && is_array( $params['file'] ) ) {
						return $params['file'];
					}
				}

				// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- REST nonce already checked by endpoint auth; upload array is normalized downstream.
				$file = isset( $_FILES['file'] ) && is_array( $_FILES['file'] ) ? $_FILES['file'] : null;

				return is_array( $file ) ? $file : null;
			},
			$request
		);

		return is_array( $file ) ? $file : null;
	}

	private function replaceErrorStatus(string $code): int {
		return match ( $code ) {
			'forbidden' => 403,
			'replace_locked' => 409,
			'invalid_mime' => 415,
			'metadata_generation_failed' => 422,
			'invalid_attachment', 'invalid_upload', 'missing_file', 'unreadable_file', 'tmp_dir_unwritable' => 400,
			default => 500,
		};
	}
}
