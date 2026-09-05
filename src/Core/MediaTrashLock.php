<?php

declare(strict_types=1);

namespace Plathix\Core;

use Plathix\Infrastructure\DbAdvisoryLock;

/**
 * Per-attachment mutex для операций trash/restore/permanent-delete: не даёт двум
 * конкурентным операциям над одним и тем же вложением пройти TOCTOU-окно между чтением
 * `post_status` и его мутацией ([internal]).
 *
 * Гранулярность — per-attachment, НЕ per-taxonomy: конфликт из [internal] привязан к
 * конкретному attachment ID (два конкурента трэшат один и тот же файл), а не к структуре
 * дерева папок. Переиспользование taxonomy-уровневого structure-lock
 * ({@see FolderTreeService::acquire_structure_lock()}) было отклонено — оно защищает
 * другой инвариант (parent/children целостность дерева термов) и создало бы ложную
 * сериализацию между независимыми bulk-операциями и folder-операциями той же таксономии
 * (wp-concurrency-skeptic pass, [internal]).
 *
 * Примитив — MySQL advisory-lock (GET_LOCK), тот же канон, что у
 * {@see \Plathix\Infrastructure\JobLockService} и {@see \Plathix\Modules\Replace\AttachmentReplaceLock}
 * (последний — sibling для этого класса: тот же per-attachment паттерн, другой owner/lock-namespace,
 * т.к. защищает replace-операцию, не trash/restore). timeout=0 (non-blocking, honest refuse) —
 * критическая секция здесь короткая (guard-recheck + одна мутация post_status одного ID), не
 * длинная очередь, для которой существует `JobLockService::acquire_order()`'s timeout=3.
 */
final class MediaTrashLock
{
	private const LOCK_PREFIX = 'plx_at_';

	/**
	 * Захватить лок на trash/restore/permanent-delete данного attachment.
	 *
	 * @return array{token:string,timestamp:int}|\WP_Error Успех — массив (поля token/timestamp
	 *         сохранены для стабильности контракта call-site; владелец лока фактически = коннект).
	 *         Занят / GET_LOCK недоступен → WP_Error('attachment_locked').
	 */
	public function acquire(int $attachment_id): array|\WP_Error {
		if ( $attachment_id <= 0 ) {
			return new \WP_Error( 'invalid_attachment', __( 'Attachment ID must be positive.', 'plathix' ) );
		}

		$lock_name = $this->lock_name( $attachment_id );
		// timeout=0: не ждать очередь — honest-refuse guard, симметрично AttachmentReplaceLock,
		// а не reorder-очередь JobLockService::acquire_order() (timeout=3).
		$lock = DbAdvisoryLock::acquire( $lock_name, 0 );

		// '1' = лок взят. '0' (занят) ИЛИ NULL (GET_LOCK недоступен) → честный отказ, НЕ ложный захват.
		if ( ! $lock ) {
			return new \WP_Error( 'attachment_locked', __( 'A trash operation is already in progress for this attachment.', 'plathix' ) );
		}

		return [
			'token'     => (string) $attachment_id,
			'timestamp' => time(),
		];
	}

	/**
	 * Освободить лок. Параметр $token сохранён в сигнатуре для стабильности call-site;
	 * advisory-lock освобождает владелец-коннект, отдельная сверка токена не нужна.
	 */
	public function release(int $attachment_id, string $token): void {
		if ( $attachment_id <= 0 ) {
			return;
		}

		DbAdvisoryLock::release( $this->lock_name( $attachment_id ) );
	}

	/**
	 * Per-attachment lock name (blog + attachment id) — namespace `plx_at_` НЕ пересекается
	 * с `plx_replace_` (AttachmentReplaceLock) или `plathix_mv_`/`plx_o_` (structure/order-лок
	 * FolderTreeService/JobLockService): trash и replace одного файла — разные операции и
	 * намеренно не блокируют друг друга по совпадению ID.
	 */
	private function lock_name(int $attachment_id): string {
		return self::LOCK_PREFIX . get_current_blog_id() . '_' . $attachment_id;
	}
}
