<?php

declare(strict_types=1);

namespace Plathix\Core;

use Plathix\Infrastructure\Cache;
use Plathix\Modules\Trash\FolderTrashService;

final class MediaDeleteService
{
	// [internal]: FolderCountService-зависимость и re-entrancy-маркер is_processing()
	// удалены. Дельты счётчика ведёт единственный подписчик FolderCountLifecycle прямо из
	// хуков trashed_post/untrashed_post, которые wp_trash_post()/wp_untrash_post() ниже
	// стреляют синхронно — одно событие = одна дельта, второго слоя, который маркер
	// разруливал (и не покрывал на каскадах — [internal]), больше не существует.

	/**
	 * Move a batch of attachments to WP trash.
	 *
	 * Returns three lists:
	 *   trashed  — IDs successfully moved to trash
	 *   failed   — IDs where permission was denied or wp_trash_post() returned false
	 *   skipped  — IDs that are invalid, not found, or not attachments
	 *
	 * @param int[] $ids
	 */
	public function bulk_trash(array $ids, string $taxonomy = PLATHIX_TAXONOMY): MediaTrashResult {
		$trashed = [];
		$failed  = [];
		$skipped = [];

		foreach ( $ids as $raw_id ) {
			$id = (int) $raw_id;

			if ( $id <= 0 ) {
				$skipped[] = $id;
				continue;
			}

			$post = get_post($id);
			if ( ! $post instanceof \WP_Post || $post->post_type !== 'attachment' ) {
				$skipped[] = $id;
				continue;
			}

			// Guard ([internal]): wp_trash_post() на посте, уже находящемся в trash,
			// эскалирует в WP core до wp_delete_post($id, true) — permanent delete.
			// Идемпотентность важнее прав: уже-trashed пост пропускаем независимо
			// от current_user_can, симметрично guard'у в bulk_restore().
			if ( $post->post_status === 'trash' ) {
				$skipped[] = $id;
				continue;
			}

			if ( ! current_user_can('delete_post', $id) ) {
				$failed[] = $id;
				continue;
			}

			// [internal] ([internal]): per-attachment advisory lock закрывает TOCTOU-окно
			// между guard-чтением post_status выше и мутацией ниже. Конкурентный bulk_trash/
			// bulk_restore/TrashCleanupJobRunner на этом же ID честно отказывает (failed), не
			// проходит guard повторно на устаревшем снимке.
			$lock = ( new MediaTrashLock() )->acquire( $id );
			if ( is_wp_error( $lock ) ) {
				$failed[] = $id;
				continue;
			}

			try {
				// Recheck под локом: снимок post_status выше (до acquire) мог устареть, пока
				// ждали лок — конкурент успел протрэшить этот же ID первым.
				$post = get_post( $id );
				if ( ! $post instanceof \WP_Post || $post->post_status === 'trash' ) {
					$skipped[] = $id;
					continue;
				}

				// [internal]: −1 по папкам файла применяет FolderCountLifecycle из хука
				// trashed_post, который wp_trash_post() стреляет синхронно (relation при
				// trash не рвётся — папки на момент хука те же, что до мутации).
				$result = wp_trash_post($id);

				if ( $result !== false && $result !== null ) {
					$trashed[] = $id;
				} else {
					$failed[] = $id;
				}
			} finally {
				( new MediaTrashLock() )->release( $id, $lock['token'] ?? '' );
			}
		}

		if ( $trashed !== [] ) {
			Cache::on_attachment_change(null, $taxonomy);
		}

		return new MediaTrashResult(
			trashed: $trashed,
			failed: $failed,
			skipped: $skipped
		);
	}

	/**
	 * Restore trashed attachments and assign them to the target folder.
	 *
	 * @param int[] $ids
	 */
	public function bulk_restore(array $ids, int $target_folder_id, string $taxonomy): MediaRestoreResult {
		$restored = [];
		$failed   = [];
		$skipped  = [];
		$repo     = new FolderRepository();

		foreach ( $ids as $raw_id ) {
			$id = (int) $raw_id;

			if ( $id <= 0 ) {
				$skipped[] = $id;
				continue;
			}

			$post = get_post($id);
			if ( ! $post instanceof \WP_Post || $post->post_type !== 'attachment' ) {
				$skipped[] = $id;
				continue;
			}

			if ( $post->post_status !== 'trash' ) {
				$skipped[] = $id;
				continue;
			}

			if ( ! current_user_can('delete_post', $id) ) {
				$failed[] = $id;
				continue;
			}

			// [internal] ([internal]): симметрично bulk_trash() — закрывает TOCTOU-окно
			// между guard-чтением post_status выше и wp_untrash_post ниже.
			$lock = ( new MediaTrashLock() )->acquire( $id );
			if ( is_wp_error( $lock ) ) {
				$failed[] = $id;
				continue;
			}

			try {
				// Recheck под локом: конкурент мог успеть restore/permanent-delete этот ID,
				// пока ждали лок.
				$post = get_post( $id );
				if ( ! $post instanceof \WP_Post || $post->post_status !== 'trash' ) {
					$skipped[] = $id;
					continue;
				}

				// [internal]: +1 по прежним папкам применяет FolderCountLifecycle из
				// хука untrashed_post; последующее переназначение в target ниже — отдельная
				// мутация wp_set_object_terms, её diff (−прежняя/+target) обрабатывает тот же
				// подписчик. Сумма корректна и при WP_Error на переназначении (файл остаётся
				// в прежней папке с уже применённым +1 — прежний код в этой ветке дельту
				// терял вовсе).
				$result = wp_untrash_post($id);
				if ( $result === false || $result === null ) {
					$failed[] = $id;
					continue;
				}

				// Куда вернуть файл ([internal], relation-first fallback):
				// 1) валидный target из запроса (живой, не системный, не в корзине) → туда;
				// 2) иначе текущий term-relation файла (родная папка, если жива и не в корзине) → туда;
				// 3) иначе → Несортированные. Guard существования+статуса term исключает сироту
				//    (привязку к снесённому/в-корзине терму). Продуктовое: родная папка в корзине →
				//    Несортированные, папку НЕ восстанавливаем (аналогия с ОС).
				$folder_id     = $this->resolve_restore_target( $id, $target_folder_id, $taxonomy, $repo );
				$terms_result  = $folder_id > 0
					? wp_set_object_terms($id, [ $folder_id ], $taxonomy)
					: wp_set_object_terms($id, [], $taxonomy, false);
				if ( is_wp_error( $terms_result ) ) {
					$failed[] = $id;
					continue;
				}

				$restored[] = $id;
			} finally {
				( new MediaTrashLock() )->release( $id, $lock['token'] ?? '' );
			}
		}

		if ( $restored !== [] ) {
			Cache::on_attachment_change(null, $taxonomy);
		}

		return new MediaRestoreResult(
			restored: $restored,
			failed: $failed,
			skipped: $skipped
		);
	}

	/**
	 * Определяет папку назначения при восстановлении файла ([internal]).
	 *
	 * relation-first fallback: явный target из запроса → текущий term-relation файла →
	 * Несортированные. Каждый кандидат проходит guard is_live_user_folder (существует,
	 * пользовательская, не в корзине), иначе отбрасывается — так файл не привязывается к
	 * снесённому/в-корзине терму (нет сироты). Возврат 0 = Несортированные (пустой term-set).
	 *
	 * @param int              $file_id          ID восстанавливаемого attachment.
	 * @param int              $target_folder_id Явный target из REST-запроса (0 = не задан).
	 * @param string           $taxonomy         Таксономия папок.
	 * @param FolderRepository $repo             Репозиторий (переиспользуется из цикла).
	 * @return int term_id живой пользовательской папки или 0 (→ Несортированные).
	 */
	private function resolve_restore_target(int $file_id, int $target_folder_id, string $taxonomy, FolderRepository $repo): int {
		// 1) Явный target из запроса, если он живая пользовательская папка.
		if ( $target_folder_id > FolderId::ROOT && $this->is_live_user_folder( $target_folder_id, $taxonomy, $repo ) ) {
			return $target_folder_id;
		}

		// 2) Родная папка файла из term-relation (если жива и не в корзине). Файл может быть в
		//    нескольких термах (плоская модель) — берём первый живой пользовательский (см.
		//    [internal]: известное ограничение неоднозначности).
		$current = wp_get_object_terms( $file_id, $taxonomy, [ 'fields' => 'ids' ] );
		if ( ! is_wp_error( $current ) ) {
			foreach ( (array) $current as $term_id ) {
				$term_id = (int) $term_id;
				if ( $this->is_live_user_folder( $term_id, $taxonomy, $repo ) ) {
					return $term_id;
				}
			}
		}

		// 3) Несортированные.
		return 0;
	}

	/**
	 * Guard: term существует, пользовательский (не uncategorized/trash) и НЕ в корзине
	 * (не помечен _plathix_folder_trashed). Единая проверка для всех кандидатов назначения —
	 * исключает привязку к мёртвому/в-корзине терму (сирота). [internal].
	 */
	private function is_live_user_folder(int $term_id, string $taxonomy, FolderRepository $repo): bool {
		if ( $term_id <= FolderId::ROOT ) {
			return false;
		}
		if ( ! $repo->get_by_id( $term_id, $taxonomy ) instanceof \WP_Term ) {
			return false; // term снесён (напр. retention) — не привязываем.
		}
		if ( $repo->is_uncategorized_folder( $term_id, $taxonomy ) || $term_id === TrashFolder::id( $taxonomy ) ) {
			return false; // системная папка — не считается «родной».
		}
		if ( (string) $repo->get_meta( $term_id, FolderTrashService::META_TRASHED ) === '1' ) {
			return false; // папка сама в корзине — файл идёт в Несортированные (как ОС).
		}
		return true;
	}
}
