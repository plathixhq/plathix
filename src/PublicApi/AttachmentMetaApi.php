<?php

declare(strict_types=1);

namespace Plathix\PublicApi;

use Plathix\Modules\AttachmentMeta\AttachmentEditContext;

/**
 * Стабильная граница Free для чтения контекста страницы редактирования вложения —
 * потребители вне модуля AttachmentMeta не должны `use`-ить internal-класс
 * `AttachmentEditContext` напрямую ([internal]: строгая унитарность,
 * 0 исключений; [internal]).
 */
final class AttachmentMetaApi
{
	/** Идёт ли рендер на странице редактирования вложения (`post.php`), а не в модалке. */
	public function isAttachmentEditPage(): bool
	{
		return AttachmentEditContext::is_attachment_edit_page();
	}
}
