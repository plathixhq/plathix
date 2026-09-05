<?php

declare(strict_types=1);

namespace Plathix\Modules\Trash;

use Plathix\Core\FolderCountService;
use Plathix\Core\FolderRepository;
use Plathix\Core\FolderTreeService;
use Plathix\Core\MediaTrashLock;
use Plathix\Infrastructure\Cache;
use Plathix\Infrastructure\Logger;

/**
 * Мягкое удаление папок в корзину — собственность модуля Trash ([internal]).
 *
 * Раньше удаление папки было безвозвратным (FolderTreeService::delete_recursive → wp_delete_term).
 * Теперь папка при удалении помечается мета-флагом (soft-trash), сохраняя parent/position на
 * момент удаления, и может быть восстановлена (FolderRestoreService, [internal]). Физический
 * wp_delete_term наступает только при окончательной очистке корзины / retention ([internal]).
 *
 * ГРАНИЦА (канон A7): term-lifecycle папок = модуль. Платформа (FolderTreeService) зовёт этот
 * runner через слот plathix/folder/trash_runner; дефолт слота — тонкий мост FolderTrashRunner.
 *
 * Мета-ключи (storage-контракт, новый — старых данных в этом формате нет, миграция не нужна):
 *  - _plathix_folder_trashed             = '1'  признак «папка в корзине»
 *  - _plathix_folder_trash_time          = int  timestamp для retention-отсчёта
 *  - _plathix_folder_trash_parent        = int  parent на момент удаления (для restore)
 *  - _plathix_folder_trash_position      = int  позиция на момент удаления (для restore)
 *  - _plathix_folder_trash_original_name = string  [internal]: имя термина ДО коллизионного
 *    rename в `{id}__trashed` (FolderTreeService::create(), не trash()-путь этого класса —
 *    пишется при создании НОВОЙ папки с тем же именем, не при обычном trash) — читается и
 *    удаляется в FolderRestoreService::restore() для возврата человекочитаемого имени.
 */
final class FolderTrashService
{
	public const META_TRASHED        = '_plathix_folder_trashed';
	public const META_TIME           = '_plathix_folder_trash_time';
	public const META_PARENT         = '_plathix_folder_trash_parent';
	public const META_POSITION       = '_plathix_folder_trash_position';
	public const META_ORIGINAL_NAME  = '_plathix_folder_trash_original_name';

	private FolderRepository $repository;
	private FolderTreeService $tree;
	private FolderCountService $count_service;

	public function __construct(?FolderRepository $repository = null, ?FolderTreeService $tree = null, ?FolderCountService $count_service = null)
	{
		$this->repository = $repository ?? new FolderRepository();
		// [internal] MSC-103: reuse ONE FolderCountService instance for both the
		// tree's own dependency and this class's new recursive-count decrement — avoids
		// constructing two separate instances (cheap, but pointless duplication) when
		// $tree is not explicitly injected.
		$this->count_service = $count_service ?? new FolderCountService( $this->repository, Cache::make() );
		// Тот же паттерн инстанцирования, что уже применяет TrashCleanupJobRunner::cleanup_folders()
		// в этом же модуле — только для structure-замка (acquire/release_structure_lock), не для
		// tree-мутаций.
		$this->tree = $tree ?? new FolderTreeService( $this->repository, $this->count_service );
	}

	/**
	 * Мягко удаляет папку в корзину.
	 *
	 * [internal] (возврат к исходному дизайну [internal] после того, как [internal]
	 * временно поменял дефолт на reattach): дефолтное поведение — безусловный каскад, как в
	 * обычной файловой системе.
	 *  - 'delete' (дефолт) — каскад: всё поддерево трэшится вместе с папкой.
	 *  - 'reattach' — трэшится ТОЛЬКО сама папка; прямые дети переподвешиваются (reparent) на
	 *    её текущего родителя и остаются живыми в дереве — тот же целевой parent, что использует
	 *    permanent-delete путь (FolderTreeService::delete_recursive_body). Явная альтернатива,
	 *    вызывается только по осознанному выбору пользователя в UI, не дефолт.
	 *
	 * relation файл↔term НЕ рвётся — term жив (только помечен), файлы остаются привязаны и едут
	 * с папкой при restore. Идемпотентно: повторный трэш уже помеченной папки не затирает
	 * сохранённый parent/position.
	 *
	 * structure-замок ([internal]): берётся ОДИН раз здесь, на
	 * верхней границе операции — тот же structure-замок, что move()/delete_recursive_permanent()
	 * в FolderTreeService (сериализует trash с move/permanent-delete/restore одной таксономии).
	 * Каскад по детям идёт через private trash_body() БЕЗ повторного взятия лока — тот же
	 * принцип, что delete_recursive_permanent → delete_recursive_body (докблок там же объясняет
	 * причину: повторное взятие на каждом ребёнке рисковало бы self-deadlock).
	 *
	 * @return bool true если сама папка (корень поддерева) помечена; false и при занятом замке
	 *              (честный отказ, симметрично structure_locked в move()).
	 */
	public function trash(int $id, string $taxonomy, string $on_children = 'delete'): bool
	{
		$lock = $this->tree->acquire_structure_lock( $taxonomy );
		if ( 'none' === $lock['mode'] ) {
			return false;
		}

		try {
			return $this->trash_body( $id, $taxonomy, $on_children );
		} finally {
			$this->tree->release_structure_lock( $taxonomy, $lock );
		}
	}

	/**
	 * Рекурсивное тело trash() БЕЗ захвата лока — лок держит публичная обёртка trash().
	 */
	private function trash_body(int $id, string $taxonomy, string $on_children): bool
	{
		if ( $id <= 0 || $this->repository->is_uncategorized_folder( $id, $taxonomy ) ) {
			return false;
		}

		if ( 'delete' === $on_children ) {
			// Каскад сверху вниз: сначала поддерево, потом сам узел — порядок для restore не
			// критичен, но так дети гарантированно помечены до того, как родитель «исчезнет».
			// Поведение cascade намеренно не меняется ([internal] добавляет только видимость
			// ошибки, не останавливает и не откатывает cascade при child-failure).
			foreach ( $this->repository->get_children_ids( $id, $taxonomy ) as $child_id ) {
				if ( ! $this->trash_body( $child_id, $taxonomy, 'delete' ) ) {
					Logger::warning(
						'Folder trash: child node failed to trash, cascade continues',
						[ 'child_id' => $child_id, 'parent_id' => $id, 'taxonomy' => $taxonomy ]
					);
				}
			}
		} else {
			$term       = $this->repository->get_by_id( $id, $taxonomy );
			$new_parent = $term instanceof \WP_Term ? (int) $term->parent : 0;
			$reparented = $this->repository->bulk_update_parent( $id, $new_parent, $taxonomy );
			if ( $reparented instanceof \WP_Error ) {
				// Причина сохраняется ([internal]): раньше WP_Error от bulk_update_parent()
				// коллапсировал в голый false без trace.
				Logger::warning(
					'Folder trash: reattach-on-trash failed',
					[
						'folder_id' => $id,
						'taxonomy'  => $taxonomy,
						'code'      => (string) $reparented->get_error_code(),
						'message'   => (string) $reparented->get_error_message(),
					]
				);
				return false;
			}
		}

		return $this->mark_trashed( $id, $taxonomy );
	}

	/**
	 * Помечает ОДИН term как удалённый, сохраняя его parent/position. Идемпотентно.
	 */
	private function mark_trashed(int $id, string $taxonomy): bool
	{
		// Идемпотентность: если уже в корзине — не перезаписываем исходные parent/position.
		if ( (string) $this->repository->get_meta( $id, self::META_TRASHED ) === '1' ) {
			return true;
		}

		$term = $this->repository->get_by_id( $id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			Logger::warning(
				'Folder trash: term not found while marking as trashed',
				[ 'folder_id' => $id, 'taxonomy' => $taxonomy ]
			);
			return false;
		}

		$parent   = (int) $term->parent;
		$position = (int) $this->repository->get_meta( $id, PLATHIX_TERM_POSITION );

		// [internal]: явный агрегат −own_count отсюда удалён. Каскад
		// maybe_trash_files() ниже в ОБЕИХ ветках порождает per-file события
		// (опция ВКЛ -> wp_trash_post -> trashed_post; ВЫКЛ -> wp_set_object_terms([]) ->
		// set_object_terms), и дельты по цепочке предков применяет единственный владелец
		// FolderCountLifecycle. Прежняя схема «агрегат + события» давала предкам −2N —
		// живой двойной декремент [internal] (второй слой шёл мимо is_processing-маркера).
		$this->repository->set_meta( $id, self::META_PARENT, $parent );
		$this->repository->set_meta( $id, self::META_POSITION, $position );
		$this->repository->set_meta( $id, self::META_TIME, time() );
		$this->repository->set_meta( $id, self::META_TRASHED, '1' );

		$this->maybe_trash_files( $id, $taxonomy );

		return true;
	}

	/**
	 * Если включена настройка «удалять вложенные файлы вместе с папкой» ([internal]) — уводит
	 * файлы этой папки в WP-корзину (post_status=trash); FolderRestoreService::restore_folder_files
	 * довозит их обратно при восстановлении папки (симметрия, включая per-attachment
	 * MediaTrashLock+recheck — [internal], [internal]/#805).
	 *
	 * Default (настройка выключена, [internal]): файлы НЕ уходят в WP-корзину, но
	 * term-relation с удаляемой папкой снимается сразу — файл сиротеет в Несортированные и виден
	 * пользователю немедленно (продуктовое решение: раньше relation сохранялась и файл был
	 * привязан к невидимой trashed-папке, нигде не отображаясь — воспринималось как потеря данных,
	 * [internal] follow-up). Восстановление папки НЕ довозит осиротевшие файлы обратно: relation
	 * разорвана в момент trash, не временно скрыта — once orphaned, остаётся orphaned.
	 */
	private function maybe_trash_files(int $id, string $taxonomy): void
	{
		$object_ids = get_objects_in_term( $id, $taxonomy );
		if ( is_wp_error( $object_ids ) ) {
			return;
		}

		if ( get_option( TrashSettings::OPTION_DELETE_FILES, '' ) === '1' ) {
			// [internal] ([internal]): per-attachment lock+recheck — тот же shape,
			// что MediaDeleteService::bulk_trash(). Занятый лок/уже-trashed конкурентом ID
			// пропускается честно (skip), не прерывает каскад остальных файлов папки.
			foreach ( (array) $object_ids as $object_id ) {
				$object_id = (int) $object_id;
				$lock      = ( new MediaTrashLock() )->acquire( $object_id );
				if ( is_wp_error( $lock ) ) {
					continue;
				}

				try {
					$post = get_post( $object_id );
					if ( ! $post instanceof \WP_Post || $post->post_status === 'trash' ) {
						continue;
					}

					wp_trash_post( $object_id );
				} finally {
					( new MediaTrashLock() )->release( $object_id, $lock['token'] ?? '' );
				}
			}
			return;
		}

		$object_ids = (array) $object_ids;
		if ( $object_ids === [] ) {
			return;
		}

		foreach ( $object_ids as $object_id ) {
			wp_set_object_terms( (int) $object_id, [], $taxonomy, false );
		}

		Cache::on_attachment_change( null, $taxonomy );
	}
}
