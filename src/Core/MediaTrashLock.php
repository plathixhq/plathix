<?php

declare(strict_types=1);

namespace Plathix\Core;

use Plathix\Infrastructure\DbAdvisoryLock;

final class MediaTrashLock
{
	private const LOCK_PREFIX = 'plx_at_';

	public function acquire(int $attachment_id): array|\WP_Error {
		if ( $attachment_id <= 0 ) {
			return new \WP_Error( 'invalid_attachment', __( 'Attachment ID must be positive.', 'plathix' ) );
		}

		$lock_name = $this->lockName( $attachment_id );

		$lock = DbAdvisoryLock::acquire( $lock_name, 0 );

		if ( ! $lock ) {
			return new \WP_Error( 'attachment_locked', __( 'A trash operation is already in progress for this attachment.', 'plathix' ) );
		}

		return [
			'token'     => (string) $attachment_id,
			'timestamp' => time(),
		];
	}

	public function release(int $attachment_id, string $token): void {
		if ( $attachment_id <= 0 ) {
			return;
		}

		DbAdvisoryLock::release( $this->lockName( $attachment_id ) );
	}

	private function lockName(int $attachment_id): string {
		return self::LOCK_PREFIX . get_current_blog_id() . '_' . $attachment_id;
	}
}
