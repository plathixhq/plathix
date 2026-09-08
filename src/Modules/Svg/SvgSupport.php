<?php

declare(strict_types=1);

namespace Plathix\Modules\Svg;

use Plathix\Infrastructure\Features;
use Plathix\Infrastructure\Keys;
use Plathix\Infrastructure\Logger;
use Plathix\Loader;
use Plathix\PublicApi\SvgApi;
use Plathix\Svg\Sanitizer\Sanitizer;
use Plathix\Svg\SvgUploadPolicy;
use Plathix\User\AccessResolver;

class SvgSupport
{
	private readonly SvgUploadPolicy $svg_upload_policy;

	public function __construct(
		private readonly Sanitizer $sanitizer = new Sanitizer(),
		private readonly ?Loader $loader = null,
		?SvgUploadPolicy $svg_upload_policy = null
	) {

		$this->svg_upload_policy = $svg_upload_policy ?? new SvgUploadPolicy( $this->sanitizer );

		if ( $this->loader ) {
			$this->loader->addFilter( 'upload_mimes', $this, 'allowSvgMime' );
			$this->loader->addFilter( 'wp_check_filetype_and_ext', $this, 'fixSvgFiletype', 10, 4 );
			$this->loader->addFilter( 'wp_handle_upload_prefilter', $this, 'sanitizeSvgUpload' );
			$this->loader->addFilter( 'wp_get_attachment_image_src', $this, 'svgImageSrcFallback', 10, 4 );
		}
	}

	public function register(): void {
		if ( $this->loader ) {
			return;
		}
		add_filter( 'upload_mimes', [ $this, 'allowSvgMime' ] );
		add_filter( 'wp_check_filetype_and_ext', [ $this, 'fixSvgFiletype' ], 10, 4 );
		add_filter( 'wp_handle_upload_prefilter', [ $this, 'sanitizeSvgUpload' ] );
		add_filter( 'wp_get_attachment_image_src', [ $this, 'svgImageSrcFallback' ], 10, 4 );
	}

	/**
	 * @param array<string,string> $mimes
	 * @return array<string,string>
	 */
	public function allowSvgMime(array $mimes): array {
		if ( ! $this->isEnabled() || ! $this->canUploadSvg() ) {
			return $mimes;
		}

		$mimes['svg'] = 'image/svg+xml';
		unset( $mimes['svgz'] );

		return $mimes;
	}

	/**
	 * @param array<string,mixed> $data
	 * @param array<string,string>|null $mimes
	 * @return array<string,mixed>
	 */
	public function fixSvgFiletype(array $data, string $file, string $filename, ?array $mimes): array {
		if ( ! $this->isEnabled() ) {
			return $data;
		}

		$extension = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
		if ( 'svgz' === $extension ) {
			$data['ext']  = false;
			$data['type'] = false;
			return $data;
		}

		if ( 'svg' !== $extension ) {
			return $data;
		}

		if ( ! $this->isValidSvgFile( $file ) ) {
			$data['ext']  = false;
			$data['type'] = false;
			return $data;
		}

		$data['ext']  = 'svg';
		$data['type'] = 'image/svg+xml';

		return $data;
	}

	/**
	 * @param array<string,mixed> $file
	 * @return array<string,mixed>
	 */
	public function sanitizeSvgUpload(array $file): array {
		if ( ! $this->isEnabled() ) {
			return $file;
		}

		$filename = (string) ( $file['name'] ?? '' );
		$tmp_name = (string) ( $file['tmp_name'] ?? '' );
		$extension = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );

		// Never interfere with non-SVG uploads in the global upload prefilter.
		if ( 'svg' !== $extension && 'svgz' !== $extension ) {
			return $file;
		}

		if ( ! $this->canUploadSvg() ) {
			$file['error'] = __( 'You are not allowed to upload SVG files.', 'plathix' );
			return $file;
		}

		if ( 'svgz' === $extension ) {
			$file['error'] = __( 'Compressed SVGZ files are not supported.', 'plathix' );
			return $file;
		}

		if ( ! is_readable( $tmp_name ) ) {
			return $file;
		}

		$sanitized = $this->svg_upload_policy->enforceUploadLimitsAndSanitize( $tmp_name, $this->isSafeMode() );
		if ( is_wp_error( $sanitized ) ) {
			if ( 'invalid_mime' === $sanitized->get_error_code() ) {
				$this->logSanitizeFailure( basename( $filename ), (int) ( $file['size'] ?? 0 ), 'markup_policy_rejection' );
				$file['error'] = (string) apply_filters( 'plathix/svg/blocked_notice', $sanitized->get_error_message(), basename( $filename ) );
			} else {
				$file['error'] = $sanitized->get_error_message();
			}
			return $file;
		}

		if ( false === file_put_contents( $tmp_name, $sanitized ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writes sanitized markup back over the same WP-managed upload-prefilter tmp_name before WP moves it into uploads; local temp path.
			Logger::error( 'SVG sanitize failed', [ 'file' => basename( $filename ), 'reason' => 'cannot_write_sanitized_content' ] );
			$file['error'] = __( 'Unable to process SVG file.', 'plathix' );
			return $file;
		}

		return $file;
	}

	public function svgImageSrcFallback(mixed $image, mixed $attachment_id, mixed $size, bool $icon): mixed {
		if ( false !== $image ) {
			return $image;
		}

		$attachment_id = is_numeric( $attachment_id ) ? (int) $attachment_id : 0;
		if ( $attachment_id <= 0 ) {
			return $image;
		}

		$mime = (string) get_post_mime_type( $attachment_id );
		if ( 'image/svg+xml' !== $mime ) {
			return $image;
		}

		$url = wp_get_attachment_url( $attachment_id );
		if ( ! $url ) {
			return $image;
		}

		return [
			(string) $url,
			300,
			150,
			false,
		];
	}

	private function isEnabled(): bool {
		return Features::isEnabled( 'svg' );
	}

	private function canUploadSvg(): bool {
		return current_user_can( 'upload_files' )
			&& AccessResolver::forCurrentUser()->canUpload()
			&& $this->currentUserAllowed();
	}

	private function isSafeMode(): bool {
		return ( new SvgApi() )->isSafeMode();
	}

	public function currentUserAllowed(): bool {
		$allowedRoles = $this->allowedRoles();
		$user = wp_get_current_user();
		if ( ! $user || empty( $user->roles ) ) {
			return false;
		}

		foreach ( $user->roles as $role ) {
			if ( in_array( $role, $allowedRoles, true ) ) {
				return true;
			}
		}

		if ( apply_filters( 'plathix/infrastructure/service_token_active', false ) ) {
			return false;
		}

		// Individual override from profile with full/upload levels.

		//

		//

		return (bool) apply_filters( 'plathix/svg/user_override_allows_upload', false, get_current_user_id() );
	}

	private function isValidSvgFile(string $file): bool {
		if ( '' === $file || ! is_readable( $file ) ) {
			return false;
		}

		$contents = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads the local upload-prefilter tmp_name (is_readable-checked above) to validate SVG markup; not a remote URL.
		if ( false === $contents || '' === trim( $contents ) ) {
			return false;
		}

		return preg_match( '/<svg\b/i', $contents ) === 1;
	}

	/**
	 * @return array<int,string>
	 */
	private function allowedRoles(): array {
		$roles = get_option( 'plathix_svg_support', [ 'administrator', 'editor' ] );
		if ( ! is_array( $roles ) ) {
			return [ 'administrator', 'editor' ];
		}

		return array_values( array_filter( array_map( static fn ($role) => sanitize_key( $role ), $roles ) ) );
	}

	private function logSanitizeFailure(string $filename, int $size, string $reason): void {
		$user_id   = get_current_user_id();
		$ip_hash   = md5( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- REMOTE_ADDR is a server variable; md5() output is used only as a cache key

		$rate_key  = Keys::transient( 'svg_log_' . $ip_hash . '_' . $user_id );
		$log_count = (int) get_transient( $rate_key );

		if ( $log_count >= 10 ) {
			return;
		}

		Logger::error(
			'SVG sanitize failed',
			[
				'file'   => $filename,
				'reason' => $reason,
			]
		);
		Logger::debug(
			'SVG rejected',
			[
				'file' => $filename,
				'size' => $size,
			]
		);
		set_transient( $rate_key, $log_count + 1, MINUTE_IN_SECONDS );
	}
}
