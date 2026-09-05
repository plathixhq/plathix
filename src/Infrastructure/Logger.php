<?php

declare(strict_types=1);

namespace Plathix\Infrastructure;

final class Logger
{
	private const LEVELS = [
		'NONE'    => -1,
		'ERROR'   => 0,
		'WARNING' => 1,
		'INFO'    => 2,
		'DEBUG'   => 3,
	];

	private const MAX_ENTRIES_PER_REQUEST = 200;

	private static ?string $correlation_id = null;
	private static ?string $client_correlation_id = null;
	private static int $entries_this_request = 0;
	private static bool $throttle_sentinel_sent = false;

	/** @param array<string, mixed> $context */
	public static function error(string $message, array $context = [], ?\Throwable $e = null): void {
		if ( self::min_level() < self::LEVELS['ERROR'] || self::throttle_check() ) {
			return;
		}

		$context = self::scrub_context( $context );

		$entry = sprintf(
			'[Plathix ERROR] %s %s %s',
			self::cid_tag(),
			$message,
			$context ? wp_json_encode( $context ) : ''
		);

		if ( $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				$entry .= ' ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
				$frames = $e->getTrace();
				$lines  = [];

				foreach ( $frames as $i => $frame ) {
					$location = ( $frame['file'] ?? '[internal]' ) . '(' . ( $frame['line'] ?? 0 ) . ')';
					$call     = ( $frame['class'] ?? '' ) . ( $frame['type'] ?? '' ) . ( $frame['function'] ?? '' ) . '(...)';
					$lines[]  = "#{$i} {$location}: {$call}";
				}

				$lines[] = '#' . count( $frames ) . ' {main}';
				$entry  .= PHP_EOL . implode( PHP_EOL, $lines );
			} else {
				$entry .= ' ' . get_class( $e ) . ' (enable WP_DEBUG for details)';
			}
		}

		self::emit( $entry );
	}

	/** @param array<string, mixed> $context */
	public static function warning(string $message, array $context = []): void {
		if ( self::min_level() < self::LEVELS['WARNING'] || self::throttle_check() ) {
			return;
		}

		self::write( 'WARNING', $message, $context );
	}

	/** @param array<string, mixed> $context */
	public static function info(string $message, array $context = []): void {
		if ( self::min_level() < self::LEVELS['INFO'] || self::throttle_check() ) {
			return;
		}

		self::write( 'INFO', $message, $context );
	}

	/** @param array<string, mixed> $context */
	public static function debug(string $message, array $context = []): void {
		if ( self::min_level() < self::LEVELS['DEBUG'] || self::throttle_check() ) {
			return;
		}

		self::write( 'DEBUG', $message, $context );
	}

	public static function get_correlation_id(): string {
		if ( null === self::$correlation_id ) {
			$bytes     = random_bytes( 16 );
			$bytes[6]  = chr( ( ord( $bytes[6] ) & 0x0f ) | 0x40 );
			$bytes[8]  = chr( ( ord( $bytes[8] ) & 0x3f ) | 0x80 );
			self::$correlation_id = vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $bytes ), 4 ) );

			if ( isset( $_SERVER['HTTP_X_CORRELATION_ID'] ) ) {
				$raw = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_CORRELATION_ID'] ) );
				if ( preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $raw ) ) {
					self::$client_correlation_id = strtolower( $raw );
				}
			}
		}

		return self::$correlation_id;
	}

	public static function get_client_correlation_id(): ?string {
		self::get_correlation_id();

		return self::$client_correlation_id;
	}

	/**
	 * [internal] ([internal]): единственное место, где плагин пишет в лог.
	 *
	 * До этого `error_log()` вызывался пятью копиями, каждая со своей копией одинакового
	 * `phpcs:ignore`. WordPress не имеет `wp_log()` — `WP_DEBUG_LOG` сам является обёрткой
	 * над `error_log()`, поэтому вызов остаётся, но подавление теперь одно.
	 */
	private static function emit(string $entry): void {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- production logging infrastructure (level-gated, throttled, PII-scrubbed), not leftover debug code; WP core exposes no wp_log()/production-safe alternative
		error_log( $entry );
	}

	/**
	 * Собирает и пишет запись одного уровня. WARNING/INFO/DEBUG отличались только меткой —
	 * форматирование жило в трёх дословных копиях.
	 *
	 * @param array<string, mixed> $context
	 */
	private static function write(string $level, string $message, array $context = []): void {
		self::emit(
			sprintf(
				'[Plathix %s] %s %s %s %s',
				$level,
				wp_date( 'Y-m-d H:i:s' ),
				self::cid_tag(),
				$message,
				$context ? wp_json_encode( self::scrub_context( $context ) ) : ''
			)
		);
	}

	private static function min_level(): int {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			return self::LEVELS['DEBUG'];
		}

		$level = strtoupper(
			(string) apply_filters(
				'plathix/log_level',
				defined( 'PLATHIX_LOG_LEVEL' ) ? PLATHIX_LOG_LEVEL : 'ERROR'
			)
		);

		return self::LEVELS[ $level ] ?? self::LEVELS['ERROR'];
	}

	private static function throttle_check(): bool {
		self::$entries_this_request++;

		if ( self::$entries_this_request <= self::MAX_ENTRIES_PER_REQUEST ) {
			return false;
		}

		if ( ! self::$throttle_sentinel_sent ) {
			self::$throttle_sentinel_sent = true;
			self::emit(
				sprintf(
					'[Plathix THROTTLE] %s %s log flood suppressed after %d entries',
					wp_date( 'Y-m-d H:i:s' ),
					self::cid_tag(),
					self::MAX_ENTRIES_PER_REQUEST
				)
			);
		}

		return true;
	}

	private static function cid_tag(): string {
		$tag  = '[cid:' . self::get_correlation_id() . ']';
		$ccid = self::get_client_correlation_id();

		if ( null !== $ccid ) {
			$tag .= '[ccid:' . $ccid . ']';
		}

		return $tag;
	}

	/**
	 * @param array<string, mixed> $context
	 * @return array<string, mixed>
	 */
	private static function scrub_context(array $context): array {
		static $substrings = [
			'email',
			'mail',
			'password',
			'passwd',
			'pass',
			'pwd',
			'phone',
			'mobile',
			'first_name',
			'last_name',
			'display_name',
			'login',
			'user_login',
			'address',
			'ip',
			'token',
			'secret',
			'api_key',
			'apikey',
			'auth',
			'nonce',
			'sql',
			'query',
			'meta_value',
			'url',
		];

		static $suffixes = [
			'_key',
			'_token',
			'_secret',
			'_pass',
			'_password',
			'_email',
			'_hash',
			'_nonce',
			'_credential',
		];

		$out = [];

		foreach ( $context as $key => $value ) {
			$lower  = strtolower( (string) $key );
			$redact = false;

			foreach ( $substrings as $substring ) {
				if ( str_contains( $lower, $substring ) ) {
					$redact = true;
					break;
				}
			}

			if ( ! $redact ) {
				foreach ( $suffixes as $suffix ) {
					if ( str_ends_with( $lower, $suffix ) ) {
						$redact = true;
						break;
					}
				}
			}

			$out[ $key ] = $redact ? '[REDACTED]' : $value;
		}

		return $out;
	}
}
