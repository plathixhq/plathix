<?php

declare(strict_types=1);

namespace Plathix\Modules\Trash;

use Plathix\Core\FolderCountService;
use Plathix\Core\FolderRepository;
use Plathix\Core\FolderTreeService;
use Plathix\Core\MediaTrashLock;
use Plathix\Core\Taxonomy;
use Plathix\Infrastructure\Cache;

/**
 * Recurring-раннер очистки корзины по retention-порогу ([internal] + [internal]).
 *
 * Удаляет навсегда attachment'ы И папки, пролежавшие в корзине дольше `plathix_trash_retention_days`
 * (дефолт 30, до 180). Отсчёт файлов — по мете `_plathix_trash_time` (пишет Module на trashed_post,
 * снимая нативную `_wp_trash_meta_time`, чтобы wp_scheduled_delete не удалил на 30-й день); отсчёт
 * папок — по `_plathix_folder_trash_time` (пишет FolderTrashService). Так retention переопределяет
 * дефолт WP БЕЗ правки wp-config. Окончательный снос папки = физический wp_delete_term (осиротевшие
 * файлы теряют привязку → в несортированные, существующее поведение WP).
 *
 * Прецедент раннера + trash-guard — Infrastructure\Jobs\OrphanCleanupJobRunner.
 */
final class TrashCleanupJobRunner
{
	private const META_KEY = '_plathix_trash_time';
	private const DEFAULT_DAYS = 30;
	// [internal]: defense-in-depth предохранитель — даже гипотетический будущий регресс в
	// query-логике (аналог #263) не должен удалить произвольно большой объём данных за
	// один cron-прогон. Остаток обрабатывается следующим ежедневным прогоном.
	private const MAX_ITEMS_PER_RUN = 100;

	/** @var (callable(int): void) Очистка папок по cutoff; инъектируема для теста. */
	private $folder_cleanup;

	/**
	 * @param (callable(int): void)|null $folder_cleanup Дефолт создаёт Core-контур (Repository+Tree);
	 *                                                    тест передаёт свой, чтобы не тащить Cache::make.
	 */
	public function __construct(?callable $folder_cleanup = null)
	{
		$this->folder_cleanup = $folder_cleanup ?? [ $this, 'cleanup_folders' ];
	}

	/**
	 * @param array<string, mixed> $args Аргументы job (blog_id и пр.) — не используются.
	 */
	public function run(array $args = []): void
	{
		$days   = $this->retention_days();
		$cutoff = time() - ( $days * DAY_IN_SECONDS );

		( $this->folder_cleanup )( $cutoff );

		// [internal]: top-level meta_key/meta_type/meta_compare/meta_value_num НЕ транслируются
		// в SQL-условие по значению в WP_Query (подтверждено живым SQL-дампом на проде — итоговый
		// запрос содержал только "meta_key = ...", без сравнения по cutoff, что удаляло абсолютно
		// все трэшенные файлы независимо от возраста). Вложенный meta_query — документированный,
		// надёжный путь построения meta-условий с оператором сравнения.
		$ids = get_posts(
			[
				'post_type'      => 'attachment',
				'post_status'    => 'trash',
				'posts_per_page' => self::MAX_ITEMS_PER_RUN,
				'fields'         => 'ids',
				'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the trash-timestamp meta is the only way to find expired items; runs in a scheduled background job, never on a user request
					[
						'key'     => self::META_KEY,
						'value'   => $cutoff,
						'compare' => '<',
						'type'    => 'NUMERIC',
					],
				],
				'no_found_rows'  => true,
			]
		);

		foreach ( (array) $ids as $id ) {
			$id = (int) $id;

			// [internal] ([internal]): per-attachment advisory lock закрывает гонку
			// cron-permanent-delete ↔ конкурентный restore того же ID (MediaDeleteService::
			// bulk_restore() держит тот же лок). Занят → честный skip: следующий ежедневный
			// прогон подхватит этот ID снова, если он всё ещё просрочен (идемпотентно, как и
			// существующий post_status-guard ниже).
			$lock = ( new MediaTrashLock() )->acquire( $id );
			if ( is_wp_error( $lock ) ) {
				continue;
			}

			try {
				// Страховка: удаляем только если реально в trash (мета-сирота после restore игнорируется).
				$post = get_post( $id );
				if ( ! $post || 'trash' !== $post->post_status ) {
					continue;
				}
				wp_delete_post( $id, true );
			} finally {
				( new MediaTrashLock() )->release( $id, $lock['token'] ?? '' );
			}
		}
	}

	/**
	 * Окончательно удаляет папки, пролежавшие в корзине дольше cutoff ([internal]).
	 *
	 * Guard: только реально помеченные (`_plathix_folder_trashed`) с истёкшим `_plathix_folder_trash_time`
	 * — живые папки и мета-сироты (после restore) не трогаются. Физический снос = delete_recursive_body
	 * (осиротевшие файлы теряют term-связь → в несортированные).
	 *
	 * [internal] ([internal]): structure-lock берётся ОДИН раз на весь per-taxonomy
	 * проход, ДО чтения guard'а — так guard-чтение и физическое удаление происходят под одним
	 * и тем же локом, что и восстановление (FolderRestoreService::restore()). Раньше guard
	 * читался вне лока, а лок брала только внутренняя delete_recursive_permanent() — конкурентный
	 * restore мог успеть завершиться в окне между чтением guard и захватом лока, и cron сносил
	 * только что восстановленное поддерево. Вызов идёт через delete_recursive_under_lock()
	 * (не delete_recursive_permanent()) — та берёт тот же лок сама, повторный acquire_order()
	 * с тем же именем внутри уже открытого лока был бы self-deadlock (GET_LOCK не реентрантен).
	 */
	public function cleanup_folders(int $cutoff): void
	{
		$repository = new FolderRepository();
		$tree       = new FolderTreeService( $repository, new FolderCountService( $repository, Cache::make() ) );

		foreach ( Taxonomy::get_enabled_taxonomies() as $taxonomy ) {
			$lock = $tree->acquire_structure_lock( $taxonomy );
			if ( 'none' === $lock['mode'] ) {
				continue;
			}

			try {
				// [internal]: срез ПОСЛЕ получения полного списка — get_trashed_ids() общий
				// метод (второй потребитель — Module::resolve_hidden_folder_ids(), ему нужен
				// полный список), лимит не может жить внутри репозитория. Per-taxonomy предел
				// (не суммарный по всем taxonomy) — симметрично тому, как run() лимитирует
				// одним get_posts() вызовом на один тип поста. orderby по trash_time (не WP
				// default `orderby=name`) — иначе срез берёт первые 100 папок по алфавиту
				// имени, а не по возрасту trash; если они все ещё не просрочены, просроченные
				// за пределами среза не удаляются никогда (starvation, [internal]).
				$ids = array_slice( $repository->get_trashed_ids( $taxonomy, FolderTrashService::META_TIME ), 0, self::MAX_ITEMS_PER_RUN );
				foreach ( $ids as $id ) {
					// Guard времени: удаляем только если срок истёк (мета-сирота без времени игнорируется).
					// Читается ПОД уже захваченным локом — это и есть re-check, закрывающий гонку.
					$trashed_at = (int) $repository->get_meta( $id, FolderTrashService::META_TIME );
					if ( $trashed_at <= 0 || $trashed_at >= $cutoff ) {
						continue;
					}
					$tree->delete_recursive_under_lock( $id, $taxonomy, 'delete' );
				}
			} finally {
				$tree->release_structure_lock( $taxonomy, $lock );
			}
		}
	}

	/**
	 * Порог хранения в днях: option `plathix_trash_retention_days`, зажат в 1..180.
	 * Дефолт 30 (= нативный WP EMPTY_TRASH_DAYS). UI-настройку даёт слайс B.
	 */
	private function retention_days(): int
	{
		$days = (int) get_option( 'plathix_trash_retention_days', self::DEFAULT_DAYS );

		return max( 1, min( 180, $days ) );
	}
}
