<?php

declare(strict_types=1);

namespace Plathix\Svg;

use Plathix\Svg\Sanitizer\Sanitizer;

final class SvgUploadPolicy
{
	public function __construct(
		private readonly Sanitizer $sanitizer = new Sanitizer()
	) {
	}

	/**
	 * @param string $contents
	 * @param bool   $safeMode
	 * @return string|\WP_Error
	 */

	public function sanitizeMarkup(string $contents, bool $safeMode): string|\WP_Error {
		if ( $safeMode && $this->sanitizer->hasUnsafeUseOrImageReference( $contents ) ) {
			return new \WP_Error( 'invalid_mime', __( 'SVG file contains unsafe content and was rejected.', 'plathix' ) );
		}

		$sanitized = $this->sanitizer->sanitize( $contents );

		if ( '' === $sanitized ) {
			return new \WP_Error( 'invalid_mime', __( 'SVG file contains unsafe content and was rejected.', 'plathix' ) );
		}

		if ( $safeMode ) {
			$lower = strtolower( $sanitized );
			if (
				str_contains( $lower, '<style' )
				|| str_contains( $lower, '<foreignobject' )
			) {
				return new \WP_Error( 'invalid_mime', __( 'SVG file contains unsafe content and was rejected.', 'plathix' ) );
			}
		}

		return $sanitized;
	}

	/**
	 * @param string $tmpName
	 * @param bool   $safeMode
	 * @return string|\WP_Error
	 */

	public function enforceUploadLimitsAndSanitize(string $tmpName, bool $safeMode): string|\WP_Error {
		$maxBytes = (int) apply_filters( 'plathix/svg/max_upload_bytes', 2 * 1024 * 1024 ); // 2 MB default
		if ( filesize( $tmpName ) > $maxBytes ) {
			return new \WP_Error( 'invalid_upload', __( 'SVG file exceeds the maximum allowed size.', 'plathix' ) );
		}

		$contents = file_get_contents( $tmpName ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads a caller-provided local tmp path (size-checked above), never a remote URL; callers are responsible for is_readable() before calling this method.
		if ( false === $contents ) {
			return new \WP_Error( 'invalid_upload', __( 'Unable to read SVG file.', 'plathix' ) );
		}

		return $this->sanitizeMarkup( $contents, $safeMode );
	}
}
