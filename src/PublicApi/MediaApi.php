<?php

declare(strict_types=1);

namespace Plathix\PublicApi;

use Plathix\Modules\Replace\AttachmentReplaceService;

/**
 * Стабильная граница media-операций Free-ядра для потребителей вне ядра (CLI).
 *
 * Отделяет CLI от прямой ссылки на `Modules\Replace\AttachmentReplaceService` —
 * межмодульную зависимость, которая при выносе CLI в отдельный плагин иначе прибила бы
 * его к внутренностям модуля Replace. Через этот фасад CLI зависит от контракта Free.
 *
 * @api
 */
final class MediaApi
{
	/** @var \Closure(int, array<string,mixed>, array<string,mixed>): (array<string,mixed>|\WP_Error) */
	private \Closure $replacer;

	public function __construct(?callable $replacer = null)
	{
		$this->replacer = \Closure::fromCallable($replacer ?? [$this, 'defaultReplacer']);
	}

	/**
	 * Заменить файл существующего вложения без создания нового attachment-поста.
	 *
	 * @api
	 * @param  array<string,mixed> $input   Дескриптор файла (name/type/tmp_name/size/error).
	 * @param  array<string,mixed> $options Контекст (actor_context/upload_mode/taxonomy).
	 * @return array<string,mixed>|\WP_Error
	 */
	public function replace(int $attachmentId, array $input, array $options = []): array|\WP_Error
	{
		return ($this->replacer)($attachmentId, $input, $options);
	}

	/**
	 * @param  array<string,mixed> $input
	 * @param  array<string,mixed> $options
	 * @return array<string,mixed>|\WP_Error
	 */
	private function defaultReplacer(int $attachmentId, array $input, array $options): array|\WP_Error
	{
		return (new AttachmentReplaceService())->replace($attachmentId, $input, $options);
	}
}
