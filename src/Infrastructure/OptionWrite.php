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

	/**
	 * @param string $option
	 * @param bool   $network
	 */

	public static function deleted(string $option, bool $network = false): bool
	{
		if ( $network ) {
			delete_site_option( $option );

			return null === get_site_option( $option, null );
		}

		delete_option( $option );

		return null === get_option( $option, null );
	}

	private static function valuesEqual(mixed $a, mixed $b): bool
	{
		if ( is_array( $a ) && is_array( $b ) ) {
			return self::normalizeArray( $a ) === self::normalizeArray( $b );
		}

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
