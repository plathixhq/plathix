<?php

declare(strict_types=1);

namespace Plathix\Core;

final class TaxonomyResolver
{
	public static function fromPostType(string $post_type): string {
		$slug = match ( $post_type ) {
			'attachment', '' => PLATHIX_TAXONOMY,
			default => PLATHIX_TAX_PREFIX . $post_type,
		};

		if ( strlen($slug) > 32 && defined('WP_DEBUG') && WP_DEBUG ) {
			// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.PHP.DevelopmentFunctions.error_log_trigger_error -- trigger_error is a debug log gated behind WP_DEBUG, not user-facing output and not shipped debug code
			trigger_error(
				sprintf(
					'plathix: taxonomy slug "%s" (%d chars) exceeds WP limit of 32. CPT name must be ≤ %d chars with prefix "%s".',
					$slug,
					strlen($slug),
					32 - strlen(PLATHIX_TAX_PREFIX),
					PLATHIX_TAX_PREFIX
				),
				E_USER_WARNING
			);
			// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.PHP.DevelopmentFunctions.error_log_trigger_error
		}

		return $slug;
	}
}
