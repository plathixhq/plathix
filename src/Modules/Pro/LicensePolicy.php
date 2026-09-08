<?php

declare(strict_types=1);

namespace Plathix\Modules\Pro;

final class LicensePolicy
{

	public const EXPIRY_SOON_DAYS = 14;

	public static function isValidKeyFormat(string $key): bool {
		return 1 === preg_match( '/^[A-Za-z0-9\-]{8,128}$/', $key );
	}

	/**
	 * @param string   $expiry_iso
	 * @param int|null $now
	 */

	public static function daysUntilExpiry(string $expiry_iso, ?int $now = null): ?int {
		if ( '' === $expiry_iso ) {
			return null;
		}

		$expiry_ts = strtotime( $expiry_iso );
		if ( false === $expiry_ts ) {
			return null;
		}

		$now = $now ?? time();

		return (int) floor( ( $expiry_ts - $now ) / DAY_IN_SECONDS );
	}

	/**
	 * @param int|null $days
	 */

	public static function expiryState(?int $days): string {
		if ( null === $days ) {
			return 'lifetime';
		}
		if ( $days < 0 ) {
			return 'expired';
		}
		if ( $days <= self::EXPIRY_SOON_DAYS ) {
			return 'soon';
		}
		return 'ok';
	}
}
