<?php

declare(strict_types=1);

namespace Plathix\Http;

use Plathix\Core\FolderRepository;
use Plathix\Core\FolderTreeService;
use Plathix\Core\HiddenFolders;
use Plathix\Modules\Trash\FolderTrashService;

/**
 * REST-операции корзины папок: список в корзине, восстановление, окончательная очистка.
 * Часть распила FolderController ([internal] #94) — trash слой (общая подсистема
 * soft-trash, [internal]*). Зависимости — repository (мета/термы trashed) + tree
 * (structure-lock + delete_recursive_under_lock для purge, [internal]/#795); restore
 * делегирует в PublicApi\FoldersApi.
 *
 * Маршрутизация и permission_callback остаются в RestController/RestRouteRegistry.
 */
final class FolderTrashController
{
	use RestControllerHelpers;

	public function __construct(
		private readonly FolderRepository $repository,
		private readonly FolderTreeService $tree,
	) {
	}

	/**
	 * Список папок в корзине (soft-trash, [internal]/201) для рендера блока корзины папок.
	 * Отдельная выборка — НЕ живое дерево (то исключает эти же id через get_trashed_ids).
	 *
	 * Ответ (плитка эталона, [internal]): { success, folders: [{ id, name, parent,
	 * color, kids, deletedAt }] }. color — цвет папки (term-meta, '' = дефолт); kids — число
	 * помеченных дочерних (ушли в корзину каскадом вместе); deletedAt — timestamp трэша (формат
	 * «N дней назад» — на клиенте). size во Free НЕТ (размеры папок = PRO, FolderInfo).
	 */
	public function trashed_folders(\WP_REST_Request $request): \WP_REST_Response {
		$taxonomy = $this->request_taxonomy( $request );
		$ids      = HiddenFolders::ids( $taxonomy );

		// Один проход: сохранённый parent каждого trashed-term → счётчик дочерних в корзине.
		$saved_parent = [];
		foreach ( $ids as $id ) {
			$saved_parent[ (int) $id ] = (int) $this->repository->get_meta( (int) $id, FolderTrashService::META_PARENT );
		}
		$kids_count = array_count_values( array_values( $saved_parent ) );

		$folders = [];
		foreach ( $ids as $id ) {
			$id   = (int) $id;
			$term = $this->repository->get_by_id( $id, $taxonomy );
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			$folders[] = [
				'id'        => $id,
				'name'      => (string) $term->name,
				'parent'    => $saved_parent[ $id ],
				'color'     => (string) $this->repository->get_meta( $id, PLATHIX_TERM_COLOR ),
				'kids'      => (int) ( $kids_count[ $id ] ?? 0 ),
				'deletedAt' => (int) $this->repository->get_meta( $id, FolderTrashService::META_TIME ),
			];
		}

		return new \WP_REST_Response( [ 'success' => true, 'folders' => $folders ], 200 );
	}

	/**
	 * Восстанавливает папку из корзины на прежнее место ([internal]/103).
	 * Возвращает fallbackRoot=true, если исходный родитель недоступен и папка вернулась в корень.
	 */
	public function restore_folder(\WP_REST_Request $request): \WP_REST_Response {
		$id       = absint( $request->get_param( 'id' ) );
		$taxonomy = $this->request_taxonomy( $request );

		$result = ( new \Plathix\PublicApi\FoldersApi() )->restoreFolder( $id, $taxonomy );

		if ( ! $result['restored'] ) {
			return new \WP_REST_Response(
				[ 'success' => false, 'message' => __( 'Folder is not in Trash or no longer exists.', 'plathix' ) ],
				410
			);
		}

		return new \WP_REST_Response(
			[
				'success'      => true,
				'fallbackRoot' => $result['fallbackRoot'],
				'parent'       => $result['parent'],
			],
			200
		);
	}

	/**
	 * Окончательное удаление папки из корзины ([internal]): физический снос поддерева.
	 * Guard: только реально помеченная папка (не живая) — иначе живое дерево можно было бы снести
	 * этим маршрутом. Осиротевшие файлы теряют term-привязку → в несортированные (поведение WP).
	 *
	 * [internal] ([internal]): guard META_TRASHED перечитывается ПОД structure-lock,
	 * непосредственно перед физическим удалением — тот же шаблон lock → re-check → mutate,
	 * что [internal] (#683) уже применил в TrashCleanupJobRunner::cleanup_folders().
	 * Конкурентный restore той же папки, завершившийся в окне между ранним guard-чтением и
	 * захватом лока, больше не может быть снесён этим маршрутом.
	 */
	public function purge_folder(\WP_REST_Request $request): \WP_REST_Response {
		$id       = absint( $request->get_param( 'id' ) );
		$taxonomy = $this->request_taxonomy( $request );

		$lock = $this->tree->acquire_structure_lock( $taxonomy );
		if ( 'none' === $lock['mode'] ) {
			return new \WP_REST_Response(
				[ 'success' => false, 'message' => __( 'Trash is busy, try again.', 'plathix' ) ],
				503
			);
		}

		try {
			if ( (string) $this->repository->get_meta( $id, FolderTrashService::META_TRASHED ) !== '1' ) {
				return new \WP_REST_Response(
					[ 'success' => false, 'message' => __( 'Folder is not in Trash.', 'plathix' ) ],
					409
				);
			}

			$ok = $this->tree->delete_recursive_under_lock( $id, $taxonomy, 'delete' );
		} finally {
			$this->tree->release_structure_lock( $taxonomy, $lock );
		}

		return new \WP_REST_Response( [ 'success' => $ok ], $ok ? 200 : 500 );
	}
}
