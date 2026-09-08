<?php

declare(strict_types=1);

namespace Plathix\PublicApi;

use Plathix\Modules\AttachmentMeta\AttachmentEditContext;

final class AttachmentMetaApi
{

	public function isAttachmentEditPage(): bool
	{
		return AttachmentEditContext::isAttachmentEditPage();
	}
}
