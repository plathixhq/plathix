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
 * Восстановление папки из корзины на прежнее место ([internal]).
 *
 * Снимает soft-trash-флаг (FolderTrashService), возвращает term на сохранённый parent/position
 * и убирает трэш-меты. Fallback: если сохранённый parent физически удалён ИЛИ сам ещё в корзине —
 * восстанавливаем в корень (иначе папка окажется под несуществующим/невидимым родителем).
 *
 * ГРАНИЦА (канон A7): term-lifecycle папок = модуль. Симметрично FolderTrashService.
 */
final class FolderRestoreService
{
	private FolderRepository $repository;
	private FolderCountService $count_service;
	private FolderTreeService $tree;

	public function __construct(?FolderRepository $repository = null, ?FolderCountService $count_service = null, ?FolderTreeService $tree = null)
	{
		$this->repository    = $repository ?? new FolderRepository();
		$this->count_service = $count_service ?? new FolderCountService( $this->repository, Cache::make() );
		// structure-замок ([internal]) — тот же паттерн
		// инстанцирования, что FolderTrashService в этом же модуле.
		$this->tree = $tree ?? new FolderTreeService( $this->repository, $this->count_service );
	}

	/**
	 * Восстанавливает одну помеченную папку.
	 *
	 * structure-замок ([internal]): тот же structure-замок, что
	 * move()/delete_recursive_permanent()/trash() в этой же таксономии — без него параллельный
	 * retention permanent-delete мог пройти guard trashed_at и снести восстанавливаемое поддерево
	 * между чтением и записью этого метода. restore() не рекурсивен (одна папка за вызов) —
	 * разделение на public/private тело (как у trash()/delete_recursive_permanent) не требуется,
	 * лок берётся и отпускается в границах самого метода.
	 *
	 * @return array{restored:bool, fallbackRoot:bool, parent:int} Контракт для UI: fallbackRoot=true,
	 *         если исходный родитель недоступен и папка возвращена в корень. Замок занят →
	 *         тот же честный отказ restored=false, что и «не в корзине»/id<=0.
	 */
	public function restore(int $id, string $taxonomy): array
	{
		$result = [ 'restored' => false, 'fallbackRoot' => false, 'parent' => 0 ];

		if ( $id <= 0 ) {
			return $result;
		}

		$lock = $this->tree->acquire_structure_lock( $taxonomy );
		if ( 'none' === $lock['mode'] ) {
			return $result;
		}

		try {
			return $this->restore_locked( $id, $taxonomy, $result );
		} finally {
			$this->tree->release_structure_lock( $taxonomy, $lock );
		}
	}

	/**
	 * @param array{restored:bool, fallbackRoot:bool, parent:int} $result
	 * @return array{restored:bool, fallbackRoot:bool, parent:int}
	 */
	private function restore_locked(int $id, string $taxonomy, array $result): array
	{
		// Не в корзине — восстанавливать нечего.
		if ( (string) $this->repository->get_meta( $id, FolderTrashService::META_TRASHED ) !== '1' ) {
			return $result;
		}

		$saved_parent   = (int) $this->repository->get_meta( $id, FolderTrashService::META_PARENT );
		$saved_position = (int) $this->repository->get_meta( $id, FolderTrashService::META_POSITION );

		$target_parent = $this->resolve_parent( $saved_parent, $taxonomy );
		$fallback_root = ( $saved_parent > 0 && $target_parent === 0 );

		// Вернуть term на место: parent + позиция.
		$this->repository->update_parent( $id, $target_parent, $taxonomy );
		$this->repository->set_meta( $id, PLATHIX_TERM_POSITION, $saved_position );

		// Снять трэш-меты — папка снова живая.
		$this->repository->delete_meta( $id, FolderTrashService::META_TRASHED );
		$this->repository->delete_meta( $id, FolderTrashService::META_TIME );
		$this->repository->delete_meta( $id, FolderTrashService::META_PARENT );
		$this->repository->delete_meta( $id, FolderTrashService::META_POSITION );

		// [internal]: если папка ранее прошла через коллизионный auto-rename в `{id}__trashed`
		// (FolderTreeService::create()), вернуть человекочитаемое имя. Мета отсутствует для
		// обычного trash/restore без коллизии — тогда этот блок не срабатывает вовсе.
		$this->restore_original_name( $id, $target_parent, $taxonomy );

		// Довоз файлов ([internal]): при опции «удалять файлы с папкой» файлы уехали в
		// WP-trash (FolderTrashService::maybe_trash_files). Восстановление папки обязано вернуть
		// их — симметрия удаления.
		//
		// НЕСУЩИЙ ИНВАРИАНТ ПОРЯДКА ([internal]): update_parent() ВЫШЕ обязан выполниться
		// ДО этого вызова. Явный агрегат +own_count отсюда удалён — дельты применяет
		// FolderCountLifecycle из per-file событий (wp_untrash_post -> untrashed_post), и цепочка
		// предков для них резолвится через get_ancestors ПО ТЕКУЩЕМУ родителю папки: события,
		// отстрелянные до реparent'а, уехали бы по СТАРОЙ (корзинной) цепочке. Тест
		// FolderCascadeCountLifecycleTest::testRestoreEventsRippleThroughTheNewParentChain
		// ловит нарушение этого порядка.
		$this->restore_folder_files( $id, $taxonomy );

		// Папка вернулась в дерево — сбросить versioned-кэш get_all_cached (иначе сайдбар отдаёт
		// старый снимок без восстановленной папки, как поймал E2E [internal]).
		$this->count_service->invalidate( $taxonomy );

		return [ 'restored' => true, 'fallbackRoot' => $fallback_root, 'parent' => $target_parent ];
	}

	/**
	 * Возвращает файлы папки из WP-trash при опции «удалять файлы с папкой» ([internal]).
	 *
	 * Критерий выборки — term-relation (get_objects_in_term): покрывает и старые (без меты), и
	 * новые файлы единообразно (relation при трэше не рвётся). Guard post_status==='trash'
	 * ОБЯЗАТЕЛЕН: без него wp_untrash_post зацепил бы файлы, вручную вынутые пользователем из
	 * нативной WP-корзины, а их untrash сорвал бы им _plathix_trash_time (Module::on_untrashed_post)
	 * и сломал retention (находка WP Senior Dev). Опция ВЫКЛ: файлы не в trash → guard их пропустит,
	 * метод отработает вхолостую (корректно). Guard перечитывается ПОД per-attachment
	 * MediaTrashLock ([internal], [internal]) — закрывает interleaving с конкурентным
	 * cron permanent-delete того же файла.
	 */
	private function restore_folder_files(int $folder_id, string $taxonomy): void
	{
		if ( get_option( TrashSettings::OPTION_DELETE_FILES, '' ) !== '1' ) {
			return;
		}

		$object_ids = get_objects_in_term( $folder_id, $taxonomy );
		if ( is_wp_error( $object_ids ) ) {
			return;
		}

		$untrashed = 0;
		foreach ( (array) $object_ids as $object_id ) {
			$object_id = (int) $object_id;

			// [internal] ([internal], путь 7): per-attachment lock+recheck — тот же
			// shape, что MediaDeleteService::bulk_restore(). Закрывает interleaving с
			// TrashCleanupJobRunner::run() (файловая половина): без лока cron мог пройти свой
			// re-check и добить wp_delete_post($id, true) на файле, который этот метод только
			// что untrash'нул вне лока — permanent delete только что восстановленного файла.
			// [internal]: is_processing-маркер и native-хук-декремент, на которые ссылался
			// прежний комментарий, удалены — единственный владелец дельт теперь
			// Core\FolderCountLifecycle, применяющий +1 синхронно из хука untrashed_post,
			// который wp_untrash_post() ниже стреляет внутри этого же лока.
			$lock = ( new MediaTrashLock() )->acquire( $object_id );
			if ( is_wp_error( $lock ) ) {
				continue;
			}

			try {
				$post = get_post( $object_id );
				// Только реально «в корзине» файлы этой папки — не трогаем вручную вынутые.
				if ( $post instanceof \WP_Post && $post->post_status === 'trash' ) {
					wp_untrash_post( $object_id );
					++$untrashed;
				}
			} finally {
				( new MediaTrashLock() )->release( $object_id, $lock['token'] ?? '' );
			}
		}

		// Файлы вернулись в грид — сбросить attachment-cache (счётчики папок), но только если
		// реально что-то довезли (не вхолостую при опции ВЫКЛ / пустой папке). Находка WP Senior Dev:
		// count_service->invalidate чистит term-дерево, но не attachment-контур.
		if ( $untrashed > 0 ) {
			Cache::on_attachment_change( null, $taxonomy );
		}
	}

	/**
	 * Восстанавливает человекочитаемое имя папки, если оно было заменено на техническое
	 * `{id}__trashed` при коллизии с новой папкой ([internal], FolderTreeService::create()).
	 * Нет меты — обычный trash/restore без коллизии, метод отрабатывает вхолостую.
	 *
	 * slug НЕ восстанавливается побайтово — WP пересчитывает его из name (тот же паттерн,
	 * что FolderTreeService::rename()). При коллизии имени (кто-то создал папку с тем же
	 * именем, пока эта лежала в корзине) — пробуем disambiguated-суффикс "(2)", "(3)"...
	 * до первого свободного варианта или до лимита попыток; при исчерпании — папка остаётся
	 * с текущим именем, залогировано (тот же graceful-degradation, что resolve_parent()
	 * применяет к недоступному parent).
	 */
	private function restore_original_name(int $id, int $parent, string $taxonomy): void
	{
		$original_name = (string) $this->repository->get_meta( $id, FolderTrashService::META_ORIGINAL_NAME );
		if ( $original_name === '' ) {
			return;
		}

		$result = $this->repository->update( $id, [ 'name' => $original_name ], $taxonomy );

		if ( is_wp_error( $result ) ) {
			$unique_name = $this->generate_unique_name( $original_name, $parent, $taxonomy );
			if ( $unique_name !== null ) {
				$result = $this->repository->update( $id, [ 'name' => $unique_name ], $taxonomy );
			}

			if ( $unique_name === null || is_wp_error( $result ) ) {
				Logger::warning(
					'Folder restore: could not restore original name, kept technical placeholder',
					[ 'folder_id' => $id, 'original_name' => $original_name, 'taxonomy' => $taxonomy ]
				);
			}
		}

		$this->repository->delete_meta( $id, FolderTrashService::META_ORIGINAL_NAME );
	}

	/**
	 * Пробует "{name} (2)", "{name} (3)"... до первого свободного варианта. Лимит попыток —
	 * защита от аномального цикла (не ожидается в реальном использовании), не product-фича.
	 */
	private function generate_unique_name(string $base_name, int $parent, string $taxonomy): ?string
	{
		for ( $suffix = 2; $suffix <= 50; $suffix++ ) {
			$candidate = $base_name . ' (' . $suffix . ')';
			if ( ! term_exists( $candidate, $taxonomy, $parent ) ) {
				return $candidate;
			}
		}

		return null;
	}

	/**
	 * Определяет реальный parent для восстановления: сохранённый, если он ещё существует и НЕ в
	 * корзине сам; иначе корень (0).
	 */
	private function resolve_parent(int $saved_parent, string $taxonomy): int
	{
		if ( $saved_parent <= 0 ) {
			return 0;
		}

		$parent_term = $this->repository->get_by_id( $saved_parent, $taxonomy );
		if ( ! $parent_term instanceof \WP_Term ) {
			return 0; // родитель физически удалён
		}

		if ( (string) $this->repository->get_meta( $saved_parent, FolderTrashService::META_TRASHED ) === '1' ) {
			return 0; // родитель сам ещё в корзине — не прячем ребёнка под него
		}

		return $saved_parent;
	}
}
