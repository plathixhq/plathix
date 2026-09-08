<?php

declare(strict_types=1);

namespace Plathix\Infrastructure;

final class OptionWrite
{
	/**
	 * @param string $option
	 * @param mixed  $newValue
	 * @param bool   $network
	 */

	public static function ifChanged(string $option, mixed $newValue, bool $network = false): bool
	{
		$oldValue = $network ? get_site_option( $option, null ) : get_option( $option, null );
		$result   = $network
			? update_site_option( $option, $newValue )
			: update_option( $option, $newValue, false );

		if ( $result ) {
			return true;
		}

		return self::valuesEqual( $oldValue, $newValue );
	}

	private static function valuesEqual(mixed $a, mixed $b): bool
	{
		if ( is_array( $a ) && is_array( $b ) ) {
			return self::normalizeArray( $a ) === self::normalizeArray( $b );
		}

		// get_option() always returns the value as stored in wp_options (a TEXT column —
		// scalars come back as strings, e.g. '0'), while a save callback's $newValue is
		// typically a native int/bool (e.g. absint()/  (bool) casts in SettingsPage). A
		// strict === here treated a real WP core no-op ($wpdb->update() affecting 0 rows
		// because the serialized values are textually identical, e.g. maybe_serialize(0)
		// vs the stored '0') as a genuine write failure — every scalar option round-tripped
		// through this dispatcher was misreported as failed on the very save that changed
		// nothing. Mirror the actual comparison $wpdb->update() effectively performs at the
		// DB layer: textual/serialized equality, not PHP type-strict equality.
		return (string) maybe_serialize( $a ) === (string) maybe_serialize( $b );
	}

	/**
	 * @param array<mixed> $value
	 * @return array<mixed>
	 */
	private static function normalizeArray(array $value): array
	{
		ksort( $value );

		return $value;
	}
}
