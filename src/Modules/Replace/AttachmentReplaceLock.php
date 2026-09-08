<?php

declare(strict_types=1);

namespace Plathix\Modules\Replace;

use Plathix\Infrastructure\DbAdvisoryLock;
use Plathix\Infrastructure\Keys;

final class AttachmentReplaceLock
{
	private const LOCK_PREFIX = 'plx_replace_';

	public function acquire(int $attachment_id): array|\WP_Error
	{
		if ( $attachment_id <= 0 ) {
			return new \WP_Error( 'invalid_attachment', __( 'Attachment ID must be positive.', 'plathix' ) );
		}

		global $wpdb;

		$lock_name = Keys::lock( self::LOCK_PREFIX . $attachment_id );

		$lock = DbAdvisoryLock::acquire( $lock_name, 0 );

		if ( ! $lock ) {
			return new \WP_Error( 'replace_locked', __( 'A replace operation is already in progress for this attachment.', 'plathix' ) );
		}

		return [
			'token'     => (string) $attachment_id,
			'timestamp' => time(),
		];
	}

	public function release(int $attachment_id, string $token): void
	{
		if ( $attachment_id <= 0 ) {
			return;
		}

		global $wpdb;

		$lock_name = Keys::lock( self::LOCK_PREFIX . $attachment_id );
		DbAdvisoryLock::release( $lock_name );
	}
}
