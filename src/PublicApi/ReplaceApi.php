<?php

declare(strict_types=1);

namespace Plathix\PublicApi;

use Plathix\Modules\Replace\AttachmentReplaceUi;

final class ReplaceApi
{

	public function renderTrigger(int $attachmentId): string
	{
		return ( new AttachmentReplaceUi() )->renderReplaceTrigger( $attachmentId );
	}
}
