<?php

declare(strict_types=1);

namespace Plathix\PublicApi;

use Plathix\Modules\Replace\AttachmentReplaceUi;

/**
 * Стабильная граница Free для UI замены вложения — потребители вне модуля Replace не
 * должны `use`-ить internal-класс `AttachmentReplaceUi` напрямую
 * ([internal]: строгая унитарность, 0 исключений).
 */
final class ReplaceApi
{
	/** Разметка триггера «Заменить файл» для указанного attachment. */
	public function renderTrigger(int $attachmentId): string
	{
		return ( new AttachmentReplaceUi() )->render_replace_trigger( $attachmentId );
	}
}
