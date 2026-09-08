<?php

declare(strict_types=1);

namespace Plathix;

final class Edition
{
	public const STATUS_OPTION = 'plathix_license_status';
	public const STATUS_ACTIVE = 'active';

	public const STATUS_STALE = 'stale';

	public const KEY_OPTION = 'plathix_license_key';

	public const EXPIRES_OPTION = 'plathix_license_expires';

	public const LAST_CHECK_OPTION = 'plathix_license_last_check';

	public static function isPro(): bool
	{
		$status = get_option( self::STATUS_OPTION, '' );

		if ( self::STATUS_ACTIVE !== $status && self::STATUS_STALE !== $status ) {
			return false;
		}

		if ( '' === (string) get_option( self::KEY_OPTION, '' ) ) {
			return false;
		}

		return (bool) apply_filters( 'plathix/edition/pro_active', false );
	}
}
