<?php

declare(strict_types=1);

namespace Plathix\Modules\Replace;

use Plathix\Infrastructure\DbAdvisoryLock;

/**
 * Mutex для операции replace одного attachment: не даёт двум параллельным replace того же
 * вложения выполниться одновременно (иначе last-writer-wins → битые метаданные).
 *
 * Примитив — MySQL advisory-lock (GET_LOCK), тот же канон, что у {@see \Plathix\Infrastructure\JobLockService}.
 * Прежняя transient-реализация (get_transient → проверка → set_transient) была неатомарна:
 * между чтением и записью два процесса оба видели «свободно» и оба захватывали лок ([internal]).
 * GET_LOCK атомарен на уровне MySQL и не требует персистентного стора: acquire и release живут
 * в пределах ОДНОГО HTTP-запроса ({@see AttachmentReplaceService::replace()} — синхронный, без
 * фоновой фазы), а advisory-lock привязан к соединению, поэтому владелец лока = наш коннект.
 *
 * option-fallback (readback через wp_options) НЕ используется намеренно — он был удалён как
 * структурно слепой к параллельной краже ([internal], [internal]).
 */
final class AttachmentReplaceLock
{
	private const LOCK_PREFIX = 'plx_replace_';

	/**
	 * Захватить лок на replace данного attachment.
	 *
	 * @return array{token:string,timestamp:int}|\WP_Error Успех — массив (поля token/timestamp
	 *         сохранены для стабильности контракта call-site; владелец лока фактически = коннект).
	 *         Занят / GET_LOCK недоступен → WP_Error('replace_locked').
	 */
	public function acquire(int $attachment_id): array|\WP_Error
	{
		if ( $attachment_id <= 0 ) {
			return new \WP_Error( 'invalid_attachment', __( 'Attachment ID must be positive.', 'plathix' ) );
		}

		global $wpdb;

		$lock_name = self::LOCK_PREFIX . $attachment_id;
		// timeout=0: не ждать очередь. Это dedup-guard параллельного replace того же вложения,
		// а не очередь задач (в отличие от order-lock JobLockService с timeout=3). Занятый лок
		// → сразу «replace_locked», вызывающий отдаёт 409 «попробуйте ещё раз».
		$lock = DbAdvisoryLock::acquire( $lock_name, 0 );

		// '1' = лок взят. '0' (занят) ИЛИ NULL (GET_LOCK недоступен: managed-MySQL без passthrough) →
		// честный отказ, НЕ ложный захват.
		if ( ! $lock ) {
			return new \WP_Error( 'replace_locked', __( 'A replace operation is already in progress for this attachment.', 'plathix' ) );
		}

		return [
			'token'     => (string) $attachment_id,
			'timestamp' => time(),
		];
	}

	/**
	 * Освободить лок replace. Параметр $token сохранён в сигнатуре для стабильности call-site;
	 * advisory-lock освобождает владелец-коннект, отдельная сверка токена не нужна.
	 */
	public function release(int $attachment_id, string $token): void
	{
		if ( $attachment_id <= 0 ) {
			return;
		}

		global $wpdb;

		$lock_name = self::LOCK_PREFIX . $attachment_id;
		DbAdvisoryLock::release( $lock_name );
	}
}
