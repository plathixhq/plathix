<?php

declare(strict_types=1);

namespace Plathix\PublicApi;

use Plathix\Modules\Replace\AttachmentReplaceService;

final class MediaApi
{
	/** @var \Closure(int, array<string,mixed>, array<string,mixed>): (array<string,mixed>|\WP_Error) */
	private \Closure $replacer;

	public function __construct(?callable $replacer = null)
	{
		$this->replacer = \Closure::fromCallable($replacer ?? [$this, 'defaultReplacer']);
	}

	/**
	 * @param  array<string,mixed> $input
	 * @param  array<string,mixed> $options
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
