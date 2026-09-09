<?php

declare(strict_types=1);

namespace Plathix\PublicApi;

use Plathix\Modules\Replace\AttachmentReplaceLock;
use Plathix\Modules\Replace\AttachmentReplaceUi;

final class ReplaceApi
{

	public function renderTrigger(int $attachmentId): string
	{
		return ( new AttachmentReplaceUi() )->renderReplaceTrigger( $attachmentId );
	}

	public function isReplaceInProgress(int $attachmentId): bool
	{
		$lock = ( new AttachmentReplaceLock() )->acquire( $attachmentId );
		if ( is_wp_error( $lock ) ) {
			return true;
		}

		( new AttachmentReplaceLock() )->release( $attachmentId, $lock['token'] ?? '' );

		return false;
	}
}
