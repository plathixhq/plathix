<?php

declare(strict_types=1);

namespace Plathix\Core;

use Plathix\Infrastructure\Cache;
use Plathix\Infrastructure\Keys;

final class FolderCountService
{
	private const CACHE_TTL = 300;
	private const ALL_FILES_ID = FolderId::ROOT;
	// [internal] MSC-103: single owner for "how many files in this folder,
	// including subfolders" — termmeta point-update (+1/-1 on layout-change events),
	// not native $term->count (already rejected above, misleading on hierarchical
	// taxonomies) and not per-read recompute (too expensive for the recursive case:
	// recomputing a subtree sum on every cache-cold read would be O(subtree) per hit).
	// Direct $wpdb, not FolderRepository::set_meta() — that clears the WHOLE runtime
	// cache on every call, too heavy for a counter incremented on every layout event.
	private const RECURSIVE_COUNT_META_KEY = '_plathix_folder_count_recursive';
	/** @var array<string, bool> */
	private array $bulk_invalidations = [];
	private readonly FolderCountCalculator $calculator;

	public function __construct(
		private readonly FolderRepository $repository,
		private readonly Cache $cache
	) {
		// Чистый подсчёт вынесен в FolderCountCalculator ([internal]). Создаётся здесь, НЕ
		// инжектится снаружи — публичный конструктор (FolderRepository, Cache) сохраняется 1:1,
		// потребители (25 Free + PRO) не перелинковываются. calculator stateless (чистый SQL,
		// без своего состояния — repository ему не нужен, счёт идёт через $wpdb + static-хелперы).
		$this->calculator = new FolderCountCalculator();
	}

	public function get_count(int $folder_id, string $taxonomy): int {
		$class = $this->classify_folder( $folder_id, $taxonomy );
		if ( 'trash' === $class ) {
			return $this->calculator->trash_items_count();
		}
		if ( 'uncategorized' === $class ) {
			return $this->calculator->uncategorized_items_count( $taxonomy );
		}

		$key = $this->cache->versioned_key( 'folders_' . $taxonomy, 'count_' . $folder_id );
		$cached = $this->cache->get( $key );
		if ( is_int( $cached ) ) {
			return $cached;
		}

		$lock_key = Keys::lock( 'recount_' . get_current_blog_id() . '_' . $taxonomy . '_' . $folder_id );
		if ( ! wp_cache_add( $lock_key, 1, 'plathix', 5 ) ) {
			return 0;
		}

		try {
			// Use direct SQL (same as get_batch_counts) to count only items
			// directly in this folder — never including descendants.
			// WordPress's $term->count on hierarchical taxonomies aggregates
			// children, which gives misleading numbers.
			$counts = $this->calculator->batch_counts( [ $folder_id ], $taxonomy );
			if ( null === $counts ) {
				// [internal]: a failed query is not "0 items" — do not cache a value we
				// know is wrong; the next read gets a fresh chance to succeed.
				return 0;
			}
			$count = $counts[ $folder_id ] ?? 0;
			$this->cache->set( $key, $count, self::CACHE_TTL );

			return $count;
		} finally {
			wp_cache_delete( $lock_key, 'plathix' );
		}
	}

	/**
	 * Единая классификация папки, используемая get_count() и get_counts_for() — гейты
	 * trash/uncategorized живут ровно в одном месте ([internal],
	 * условие architecture-скептика: без общего classify два публичных метода со временем
	 * тихо разойдутся на одном и том же id).
	 *
	 * @return 'trash'|'uncategorized'|'normal'
	 */
	private function classify_folder(int $folder_id, string $taxonomy): string {
		if ( $folder_id > 0 && $folder_id === TrashFolder::id( $taxonomy ) ) {
			return 'trash';
		}

		if ( $this->repository->is_uncategorized_folder( $folder_id, $taxonomy ) ) {
			return 'uncategorized';
		}

		return 'normal';
	}

	/**
	 * Batch-версия get_count() для нескольких папок разом — один SELECT...GROUP BY вместо
	 * N вызовов get_count() ([internal]: клиент читает готовые числа из ответа move вместо
	 * второго REST round-trip). Гейты те же, что у get_count() (см. classify_folder()) —
	 * НЕ реализовано циклом по get_count(): при неудаче lock get_count() отдаёт 0 (строка
	 * выше), а кэш после end_bulk_write() гарантированно холодный сразу после bulk-мутации,
	 * так что лок-ветка отработала бы систематически и тихо занулила бы счётчики.
	 *
	 * Soft-trashed папки (HiddenFolders::ids()) исключаются из результата — их нет в живом
	 * дереве (get_all_cached() их тоже не показывает), отдавать для них число не нужно.
	 *
	 * @param int[] $folder_ids
	 * @return array<int,int> term_id => count
	 */
	public function get_counts_for(array $folder_ids, string $taxonomy): array {
		$hidden = array_flip( HiddenFolders::ids( $taxonomy ) );
		$result = [];
		$normal_ids = [];

		foreach ( $folder_ids as $folder_id ) {
			$folder_id = (int) $folder_id;
			if ( $folder_id <= 0 || isset( $hidden[ $folder_id ] ) ) {
				continue;
			}

			$class = $this->classify_folder( $folder_id, $taxonomy );
			if ( 'trash' === $class ) {
				$result[ $folder_id ] = $this->calculator->trash_items_count();
			} elseif ( 'uncategorized' === $class ) {
				$result[ $folder_id ] = $this->calculator->uncategorized_items_count( $taxonomy );
			} else {
				$normal_ids[] = $folder_id;
			}
		}

		if ( $normal_ids !== [] ) {
			// [internal]: ключ фиксируется ДО SQL-снапшота (паттерн остальных 10 мест,
			// см. get_count()/get_all_cached()/get_children() ниже) — get_counts_for()
			// была единственным исключением (ключ считался ПОСЛЕ batch_counts()).
			// Конкурентная мутация, бампнувшая версию группы между строками, теперь уходит
			// в СТАРЫЙ (уже вычисленный) ключ — безвредный мусор, а не отравление
			// СВЕЖЕЙ версии стейл-данными на CACHE_TTL.
			$keys_before_sql = [];
			foreach ( $normal_ids as $folder_id ) {
				$keys_before_sql[ $folder_id ] = $this->cache->versioned_key( 'folders_' . $taxonomy, 'count_' . $folder_id );
			}

			$counts = $this->calculator->batch_counts( $normal_ids, $taxonomy );
			foreach ( $normal_ids as $folder_id ) {
				// [internal]: null = the batch query failed, not "these are all 0" — return
				// 0 for this response without caching it as a fact the next read would trust.
				if ( null === $counts ) {
					$result[ $folder_id ] = 0;
					continue;
				}

				$count = $counts[ $folder_id ] ?? 0;
				$result[ $folder_id ] = $count;

				// Прогрев того же versioned-кэша, что читает get_count() — следующий
				// одиночный get_count() на этот id попадёт в тёплый кэш, а не в холодный SQL.
				$this->cache->set( $keys_before_sql[ $folder_id ], $count, self::CACHE_TTL );
			}
		}

		return $result;
	}

	/**
	 * Returns the number of files in $folder_id INCLUDING all subfolders (recursive),
	 * unlike get_count() which is direct-children-only. Backed by termmeta
	 * (RECURSIVE_COUNT_META_KEY), point-updated by increment_recursive_chain() on every
	 * layout-change event ([internal] MSC-103) — this method itself does not
	 * recompute on every read, it only lazily seeds a cold (never-yet-written) folder.
	 *
	 * Special folders (Trash/Uncategorized/All Files) have no subtree — same value as
	 * get_count() for them, no termmeta involved.
	 */
	public function get_recursive_count(int $folder_id, string $taxonomy): int {
		if ( $folder_id <= 0 || $folder_id === TrashFolder::id( $taxonomy ) || $this->repository->is_uncategorized_folder( $folder_id, $taxonomy ) ) {
			return $this->get_count( $folder_id, $taxonomy );
		}

		$warm = $this->read_warm_recursive_count( $folder_id );
		if ( null !== $warm ) {
			return $warm;
		}

		return $this->seed_recursive_count_if_cold( $folder_id, $taxonomy );
	}

	/**
	 * Returns the already-written termmeta value, or null if the row is cold (missing
	 * or non-numeric) — the shared "is this folder warm?" check used by both
	 * get_recursive_count() (which seeds on a miss) and increment_recursive_chain()
	 * ([internal], [internal] — which must know whether a seed just happened, so it does
	 * not double-apply a delta already baked into a fresh seed's SQL snapshot).
	 */
	private function read_warm_recursive_count(int $folder_id): ?int {
		$raw = get_term_meta( $folder_id, self::RECURSIVE_COUNT_META_KEY, true );
		if ( '' !== $raw && is_numeric( $raw ) ) {
			return max( 0, (int) $raw );
		}

		return null;
	}

	/**
	 * Cold folder (created before this package, or termmeta lost) — lazy seed: compute
	 * the real value once via SQL, write it, never recompute again until the next
	 * layout-change event increments/decrements it in place.
	 *
	 * [internal]: if the SQL count itself failed (calculate_recursive_count_from_scratch()
	 * returns null — a genuine query error, not "this folder has 0 items"), do NOT write
	 * anything to termmeta. write_recursive_count()'s upsert is a deliberate no-op on an
	 * existing row (see its own docblock) — a wrong 0 written here would never self-correct
	 * and every future read would trust it as the real count. Returning 0 for THIS response
	 * without writing it leaves the folder cold, so the next read gets a fresh chance.
	 */
	private function seed_recursive_count_if_cold(int $folder_id, string $taxonomy): int {
		$count = $this->calculate_recursive_count_from_scratch( $folder_id, $taxonomy );
		if ( null === $count ) {
			return 0;
		}

		$this->write_recursive_count( $folder_id, $count );

		return $count;
	}

	/**
	 * Cold-path only: sums this folder's own count plus every descendant's own count,
	 * via a single term tree walk (get_terms 'child_of') + one batch_counts() call —
	 * not N recursive get_count() calls. Used only by get_recursive_count() when
	 * termmeta is missing (first read after folder creation / migration).
	 *
	 * Returns null ([internal]) if the underlying batch_counts() query failed — the caller
	 * must not treat that the same as "this subtree really has 0 items".
	 */
	private function calculate_recursive_count_from_scratch(int $folder_id, string $taxonomy): ?int {
		$descendant_terms = get_terms( [
			'taxonomy'   => $taxonomy,
			'child_of'   => $folder_id,
			'hide_empty' => false,
			'fields'     => 'ids',
		] );
		$subtree_ids = is_array( $descendant_terms ) ? array_map( 'intval', $descendant_terms ) : [];
		$subtree_ids[] = $folder_id;

		$counts = $this->calculator->batch_counts( $subtree_ids, $taxonomy );
		if ( null === $counts ) {
			return null;
		}

		return array_sum( $counts );
	}

	/**
	 * Point-update: apply $delta to $folder_id's own recursive count AND to every
	 * ancestor's recursive count up to the taxonomy root (a file added to a deeply
	 * nested folder must also increment the recursive total of every parent folder
	 * above it, not just the immediate one).
	 *
	 * The actual +/- is a single atomic SQL statement per ancestor (meta_value =
	 * meta_value + delta), not a PHP read-then-write — concurrent events on the same
	 * folder (two uploads landing at once) must not lose an update to a race between
	 * two PHP-level reads of the same stale baseline.
	 *
	 * Cold ancestors (no termmeta row yet) are seeded BEFORE the atomic increment would
	 * run — but the seed itself (calculate_recursive_count_from_scratch(), a live SQL
	 * COUNT) already reflects the CURRENT database state, and every caller of this
	 * method applies its mutation (wp_set_object_terms/wp_trash_post/term reparenting/
	 * etc.) before calling here. So a cold seed already includes this exact $delta as a
	 * fact of the database — applying apply_recursive_count_delta() on top of it would
	 * double-count the same change ([internal], [internal]). Only a warm id (seed not
	 * needed) gets the delta applied; a freshly-seeded id is left as-is.
	 *
	 * @param int $folder_id Direct folder the layout-change event happened in — 0/special
	 *                       folders are no-ops (Trash/Uncategorized do not carry a subtree).
	 */
	public function increment_recursive_chain(int $folder_id, string $taxonomy, int $delta): void {
		if ( $folder_id <= 0 || 0 === $delta || $folder_id === TrashFolder::id( $taxonomy ) || $this->repository->is_uncategorized_folder( $folder_id, $taxonomy ) ) {
			return;
		}

		// get_ancestors() is the existing WP-native primitive already used elsewhere in
		// this codebase for the same taxonomy (FolderSwitchField) — not a new mechanism.
		$chain = array_merge( [ $folder_id ], array_map( 'intval', get_ancestors( $folder_id, $taxonomy, 'taxonomy' ) ) );

		foreach ( $chain as $id ) {
			$was_warm = null !== $this->read_warm_recursive_count( $id );
			if ( ! $was_warm ) {
				// Cold: seeding via a live SQL COUNT already captures this mutation —
				// applying delta on top would double-count it. No-op past the seed.
				$this->seed_recursive_count_if_cold( $id, $taxonomy );
				continue;
			}

			$this->apply_recursive_count_delta( $id, $delta );
		}
	}

	/**
	 * Atomic SQL increment/decrement (meta_value = meta_value + %d), not
	 * FolderRepository::set_meta()/update_term_meta() — those either clear the whole
	 * repository runtime cache (set_meta) or do a non-atomic SELECT-then-UPDATE round
	 * trip (update_term_meta), which loses updates under concurrent writers. Requires
	 * the row to already exist (guaranteed by get_recursive_count() seeding above) —
	 * GREATEST(0, ...) clamps against the count ever going negative from a race between
	 * two concurrent decrements past a stale read.
	 */
	private function apply_recursive_count_delta(int $folder_id, int $delta): void {
		global $wpdb;
		$meta_key = self::RECURSIVE_COUNT_META_KEY;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- $folder_id/$delta are int-cast, $meta_key is a hardcoded internal constant, $wpdb->termmeta is a core table name. %i technically available on current min WP 7.0 but adds no security benefit here ([internal]), left as-is.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->termmeta}
				    SET meta_value = GREATEST(0, CAST(meta_value AS SIGNED) + %d)
				  WHERE term_id = %d AND meta_key = %s",
				$delta,
				$folder_id,
				$meta_key
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery

		wp_cache_delete( (string) $folder_id, 'term_meta' );
	}

	/**
	 * Seed-if-missing write for the one-time cold seed in get_recursive_count() — NOT used
	 * for the +/- path (see apply_recursive_count_delta() for why that must be a separate
	 * atomic UPDATE, not this insert).
	 *
	 * [internal]: this used to be a single `INSERT ... ON DUPLICATE KEY UPDATE meta_value =
	 * meta_value` upsert, relying on a unique index on (term_id, meta_key) to detect an
	 * existing row and no-op on it. Live `SHOW INDEX FROM wp_termmeta` on the WP core schema
	 * shows no such index exists — only `PRIMARY KEY (meta_id)` is unique; `term_id` and
	 * `meta_key` are plain (non-unique) `KEY`s. `ON DUPLICATE KEY UPDATE` never triggered on
	 * this condition: every call physically inserted a NEW row instead of recognizing an
	 * existing one. Practical damage was limited (read_warm_recursive_count() uses
	 * get_term_meta(..., true), which reads the first row by meta_id;
	 * apply_recursive_count_delta()'s UPDATE has no LIMIT, so it updates every duplicate
	 * identically) but real: unbounded duplicate-row growth on repeated cold-seed attempts
	 * for the same folder, and a docblock claiming a conflict guard that was never real.
	 *
	 * Fixed as an explicit read-before-write guard instead of a migration to a real unique
	 * index (out of this package's scope — a schema change is a different, larger blast
	 * radius than this issue's contour). A race between two concurrent cold-seed calls for
	 * the same folder can still produce a duplicate row in the narrow TOCTOU window between
	 * the read and the write below — but that is strictly no worse than before (previously
	 * EVERY call produced a duplicate, not just a raced one), and the existing
	 * read/apply-delta behavior already tolerates duplicate rows as described above.
	 */
	private function write_recursive_count(int $folder_id, int $count): void {
		if ( null !== $this->read_warm_recursive_count( $folder_id ) ) {
			// A row already exists — kept in sync by apply_recursive_count_delta() on every
			// event since it was first seeded; must not be overwritten by a second, possibly
			// stale concurrent seed attempt racing the same cold read.
			return;
		}

		global $wpdb;
		$meta_key = self::RECURSIVE_COUNT_META_KEY;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- $folder_id/$count are int-cast, $meta_key is a hardcoded internal constant, $wpdb->termmeta is a core table name. %i technically available on current min WP 7.0 but adds no security benefit here ([internal]), left as-is.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->termmeta} (term_id, meta_key, meta_value)
				 VALUES (%d, %s, %d)",
				$folder_id,
				$meta_key,
				$count
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery

		wp_cache_delete( (string) $folder_id, 'term_meta' );
	}

	/**
	 * Явный override write-no-op-инварианта {@see write_recursive_count()} — ТОЛЬКО для
	 * reconcile-джобы ([internal], `Infrastructure\Jobs\FolderCountReconcileJobRunner`).
	 * Termmeta без TTL держится «never recompute», пока дельты честны; reconcile — единый
	 * механизм, которому разрешено переписать уже существующую строку живой SQL-истиной.
	 * Не использовать нигде за пределами reconcile-раннера — это сознательное, узкое
	 * исключение из инварианта, а не альтернативный путь записи для обычного кода.
	 *
	 * INSERT ... ON DUPLICATE KEY UPDATE не годится по той же причине, что и в
	 * write_recursive_count() ([internal] — уникального индекса `(term_id, meta_key)` в
	 * схеме `wp_termmeta` не существует): read-then-branch на существование строки.
	 */
	public function overwrite_recursive_count(int $folder_id, int $count): void {
		global $wpdb;
		$meta_key = self::RECURSIVE_COUNT_META_KEY;
		$count    = max( 0, $count );

		if ( null === $this->read_warm_recursive_count( $folder_id ) ) {
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- см. write_recursive_count() выше, идентичное обоснование.
			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$wpdb->termmeta} (term_id, meta_key, meta_value)
					 VALUES (%d, %s, %d)",
					$folder_id,
					$meta_key,
					$count
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		} else {
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- $folder_id/$count int-cast, $meta_key — константа, $wpdb->termmeta — core-таблица.
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->termmeta} SET meta_value = %d WHERE term_id = %d AND meta_key = %s",
					$count,
					$folder_id,
					$meta_key
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		}

		wp_cache_delete( (string) $folder_id, 'term_meta' );
	}

	/**
	 * Returns the number of user folders (excluding special folders) for $taxonomy.
	 * Reads from the 'all' cache entry when warm — no extra SQL in that case.
	 * Falls back to a single COUNT(*) query when the cache is cold.
	 */
	public function count_all(string $taxonomy): int {
		$cache_key = $this->cache->versioned_key( 'folders_' . $taxonomy, 'all' );
		$cached    = $this->cache->get( $cache_key );
		if ( is_array( $cached ) ) {
			// Subtract the three protected special folders (All Files, Uncategorized, Trash).
			$special = array_filter( $cached, static fn (\Plathix\Core\FolderDTO $f): bool => $f->isProtected );
			return max( 0, count( $cached ) - count( $special ) );
		}

		// Cold-path COUNT вынесен в FolderCountCalculator ([internal]): «cache не содержит count-правил».
		return $this->calculator->count_user_folders( $taxonomy );
	}

	/** @return FolderDTO[] */
	public function get_all_cached(string $taxonomy): array {
		$cache_key = $this->cache->versioned_key( 'folders_' . $taxonomy, 'all' );
		$cached = $this->cache->get( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$uncategorized_id = $this->repository->get_uncategorized_term_id( $taxonomy );
		$trash_id         = TrashFolder::id( $taxonomy );
		$pt_obj   = get_post_type_object( Taxonomy::post_type_for_taxonomy( $taxonomy ) );
		$all_label = $pt_obj?->labels->all_items ?? __( 'All Files', 'plathix' );
		$items = [
			new FolderDTO( self::ALL_FILES_ID, $all_label, FolderId::ROOT, -100, '', '', $this->calculator->total_items_count( $taxonomy ), $taxonomy, true ),
		];

		if ( $uncategorized_id > 0 ) {
			$items[] = new FolderDTO( $uncategorized_id, __( 'Uncategorized', 'plathix' ), FolderId::ROOT, -90, '', '', $this->calculator->uncategorized_items_count( $taxonomy ), $taxonomy, true );
		}

		// Папки в корзине (soft-trash, [internal]) не показываются в живом дереве — их выводит
		// отдельный блок корзины папок. Один meta-JOIN-запрос (не N+1), результат под versioned-кэшем
		// этого метода; инвалидируется при trash/restore (count_service->invalidate).
		// Вычисляем ДО Trash-DTO: число папок едет в folders_count Trash-DTO ([internal]).
		$trashed_ids = array_flip( HiddenFolders::ids( $taxonomy ) );

		$post_type = Taxonomy::post_type_for_taxonomy( $taxonomy );
		if ( $post_type === 'attachment' && $trash_id > 0 ) {
			// count = число ФАЙЛОВ; folders_count = число ПАПОК — узел показывает «N Ф / N П»
			// раздельно (файл≠папка, НЕ сумма, [internal]).
			$items[] = new FolderDTO( $trash_id, __( 'Trash', 'plathix' ), FolderId::ROOT, -80, '', '', $this->calculator->trash_items_count(), $taxonomy, true, false, count( $trashed_ids ) );
		}

		$all_terms = $this->repository->get_all( $taxonomy );

		// Collect custom term IDs (excluding the uncategorized special term and trashed folders).
		$custom_term_ids = [];
		foreach ( $all_terms as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			$term_id = (int) $term->term_id;
			if ( isset( $trashed_ids[ $term_id ] ) ) {
				continue;
			}
			if ( $uncategorized_id <= 0 || $term_id !== $uncategorized_id ) {
				$custom_term_ids[] = $term_id;
			}
		}

		// Count items per folder in a single SQL query instead of relying on
		// term_taxonomy.count, which WordPress may not keep up-to-date.
		// [internal]/#798: null = the batch query failed, not "these are all 0" — degrade
		// to an empty map for THIS response (every folder falls back to its own ?? 0
		// below), but do NOT cache the degraded result below (sql_batch_failed flag) —
		// caching it would freeze the false "everything is 0" fact for CACHE_TTL, exactly
		// the class of bug #692 closed for get_count()/get_counts_for() and #798 found
		// still open here.
		$raw_batch_counts = $this->calculator->batch_counts( $custom_term_ids, $taxonomy );
		$sql_batch_failed = null === $raw_batch_counts;
		$batch_counts     = $raw_batch_counts ?? [];

		// [internal] MSC-104: batch-prime the recursive-count termmeta for the WHOLE
		// tree in one query (WP-native update_termmeta_cache), so the per-folder
		// get_recursive_count() calls below hit warm object cache instead of one SQL
		// SELECT each — the same N+1-avoidance shape as batch_counts() above, applied to
		// the termmeta-backed recursive counter from MSC-103.
		update_termmeta_cache( $custom_term_ids );

		foreach ( $all_terms as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}

			if ( $uncategorized_id > 0 && (int) $term->term_id === $uncategorized_id ) {
				continue;
			}

			if ( $trash_id > 0 && (int) $term->term_id === $trash_id ) {
				continue;
			}

			// Папки в корзине (soft-trash) не попадают в живое дерево ([internal]).
			if ( isset( $trashed_ids[ (int) $term->term_id ] ) ) {
				continue;
			}

			$items[] = new FolderDTO(
				(int) $term->term_id,
				(string) $term->name,
				(int) $term->parent,
				(int) $this->repository->get_meta( (int) $term->term_id, PLATHIX_TERM_POSITION ),
				(string) $this->repository->get_meta( (int) $term->term_id, PLATHIX_TERM_COLOR ),
				'',
				$batch_counts[ (int) $term->term_id ] ?? 0,
				$taxonomy,
				false,
				false,
				null,
				$this->get_recursive_count( (int) $term->term_id, $taxonomy )
			);
		}

		usort(
			$items,
			static fn (FolderDTO $a, FolderDTO $b): int => $a->parentId <=> $b->parentId ?: $a->position <=> $b->position ?: strcasecmp( $a->name, $b->name )
		);

		$filtered = apply_filters( 'plathix/folder/list', $items, $taxonomy );
		$result = is_array( $filtered ) ? $filtered : $items;

		// [internal]: SQL-fail must not be cached as fact — next read gets a fresh chance.
		if ( ! $sql_batch_failed ) {
			$this->cache->set( $cache_key, $result, self::CACHE_TTL );
		}

		return $result;
	}

	/**
	 * Returns direct children of $parent_id with counts and has_children flag.
	 * Does NOT include special folders (All Files, Uncategorized, Trash) — those
	 * belong to full-tree / root-level logic only.
	 * Does NOT apply the plathix/folder/list filter — this is a partial result.
	 * Cache is invalidated by the same version bump as get_all_cached.
	 *
	 * [internal]: soft-trashed folders (HiddenFolders::ids()) are excluded, symmetric to
	 * get_all_cached() — before this fix, this method only filtered $uncategorized_id/
	 * $trash_id, so a trashed folder stayed visible here (unlike in get_all_cached()) at
	 * deferred-bootstrap root-level reads (get_children(0)).
	 *
	 * @return FolderDTO[]
	 */
	public function get_children(int $parent_id, string $taxonomy): array {
		$cache_key = $this->cache->versioned_key( 'folders_' . $taxonomy, 'children_' . $parent_id );
		$cached    = $this->cache->get( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$uncategorized_id = $this->repository->get_uncategorized_term_id( $taxonomy );
		$trash_id         = TrashFolder::id( $taxonomy );
		$hidden_ids       = array_flip( HiddenFolders::ids( $taxonomy ) );

		$terms = get_terms(
			[
				'taxonomy'   => $taxonomy,
				'parent'     => $parent_id,
				'hide_empty' => false,
			]
		);

		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return [];
		}

		$child_ids = [];
		foreach ( $terms as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			$id = (int) $term->term_id;
			if ( $id === $uncategorized_id || $id === $trash_id || isset( $hidden_ids[ $id ] ) ) {
				continue;
			}
			$child_ids[] = $id;
		}

		if ( empty( $child_ids ) ) {
			// Реально пустой уровень — валидный результат, кэшируется как факт.
			$this->cache->set( $cache_key, [], self::CACHE_TTL );
			return [];
		}

		// [internal]/#798: null = the batch query failed, not "these are all 0" — degrade
		// to an empty map for THIS response, symmetric to get_all_cached() above; do NOT
		// cache the degraded result (sql_batch_failed flag) — same class as #798 found
		// still open here.
		$raw_batch_counts = $this->calculator->batch_counts( $child_ids, $taxonomy );
		$sql_batch_failed = null === $raw_batch_counts;
		$batch_counts     = $raw_batch_counts ?? [];

		// Determine which children have their own children — one SQL query via repository.
		$parents_with_children = $this->repository->get_parent_ids_that_have_children( $child_ids, $taxonomy );
		$grandchild_parent_ids = array_fill_keys( $parents_with_children, true );

		// [internal] MSC-104: batch-prime recursive-count termmeta for this level in
		// one query — same rationale as get_all_cached() above.
		update_termmeta_cache( $child_ids );

		$items = [];
		foreach ( $terms as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			$id = (int) $term->term_id;
			if ( $id === $uncategorized_id || $id === $trash_id || isset( $hidden_ids[ $id ] ) ) {
				continue;
			}
			$items[] = new FolderDTO(
				$id,
				(string) $term->name,
				(int) $term->parent,
				(int) $this->repository->get_meta( $id, PLATHIX_TERM_POSITION ),
				(string) $this->repository->get_meta( $id, PLATHIX_TERM_COLOR ),
				'',
				$batch_counts[ $id ] ?? 0,
				$taxonomy,
				false,
				isset( $grandchild_parent_ids[ $id ] ),
				null,
				$this->get_recursive_count( $id, $taxonomy )
			);
		}

		usort(
			$items,
			static fn (FolderDTO $a, FolderDTO $b): int => $a->position <=> $b->position ?: strcasecmp( $a->name, $b->name )
		);

		// [internal]: SQL-fail must not be cached as fact — next read gets a fresh chance.
		if ( ! $sql_batch_failed ) {
			$this->cache->set( $cache_key, $items, self::CACHE_TTL );
		}

		return $items;
	}

	/**
	 * Применяет $delta к счётчикам папок записи НЕ-attachment типа при событии её
	 * жизненного цикла (корзина / восстановление / удаление мимо корзины).
	 *
	 * Зачем отдельная точка входа: рекурсивный счётчик живёт в termmeta и не имеет TTL —
	 * он обновляется исключительно {@see self::increment_recursive_chain()}. Для медиа эту
	 * цепочку зовут медиа-пути (Upload, MediaDeleteService, Trash-сервисы); для post/page/CPT
	 * такого вызова не существовало ни одного, а `set_object_terms` на trash/untrash не
	 * стреляет — термы записи при этом не меняются. Итог: отправленная в корзину запись
	 * оставалась учтённой в рекурсивном числе НАВСЕГДА, с накоплением ошибки на каждом
	 * событии. Own-счётчик в той же ситуации самопочинялся за TTL, рекурсивный — нет.
	 *
	 * attachment сюда не попадает намеренно: его путь уже покрыт перечисленными выше
	 * сервисами, и второй декремент здесь дал бы двойной учёт.
	 *
	 * @param int $post_id Запись, с которой произошло событие.
	 * @param int $delta   -1 при уходе записи из видимого множества, +1 при возврате.
	 */
	public function adjust_for_post(int $post_id, int $delta): void {
		if ( $post_id <= 0 || 0 === $delta ) {
			return;
		}

		$post_type = (string) get_post_type( $post_id );
		if ( '' === $post_type || 'attachment' === $post_type ) {
			return;
		}

		// Тот же резолв, что использует REST-контур (RestControllerHelpers::request_taxonomy):
		// произвольный post_type -> его plathix-таксономия, с проверкой, что она реально
		// зарегистрирована. Собственного списка типов здесь не заводится — включённые типы
		// принадлежат PRO, Free о них знать не обязан.
		$taxonomy = TaxonomyResolver::fromPostType( $post_type );
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return;
		}

		// [internal] (perf): сперва прогретый WP term cache — пост уже загружен ядром
		// к моменту lifecycle-хука; голый wp_get_object_terms шёл бы в SQL на каждое
		// событие (1000 SQL на bulk-trash 1000 постов PRO-таксономии).
		$folder_ids = null;
		if ( function_exists( 'get_object_term_cache' ) ) {
			$cached = get_object_term_cache( $post_id, $taxonomy );
			if ( is_array( $cached ) ) {
				$folder_ids = array_map( static fn (\WP_Term $t): int => (int) $t->term_id, $cached );
			}
		}
		if ( null === $folder_ids ) {
			$folder_ids = wp_get_object_terms( $post_id, $taxonomy, [ 'fields' => 'ids' ] );
			if ( is_wp_error( $folder_ids ) ) {
				return;
			}
		}
		if ( [] === $folder_ids ) {
			return;
		}

		foreach ( $folder_ids as $folder_id ) {
			$this->increment_recursive_chain( (int) $folder_id, $taxonomy, $delta );
		}

		// Own-счётчик живёт в другом кэше (versioned_key группы folders_<tax>) и
		// termmeta-инкрементом не чинится — его инвалидируем отдельно.
		$this->invalidate( $taxonomy );
	}

	// Delete-путь записей больше не живёт в этом классе ([internal]): прямой −1 на
	// before_delete_post нарушал FCCOLD-инвариант — cold-seed предка на pre-mutation хуке
	// включал ещё-живую запись и съедал дельту (вечный +1). Теперь его ведёт
	// FolderCountLifecycle парой «стэш на pre-хуке → apply на deleted_post»; guard «из
	// корзины не вычитать второй раз» переехал туда же.

	public function invalidate(string $taxonomy): void {
		if ( isset( $this->bulk_invalidations[ $taxonomy ] ) ) {
			$this->bulk_invalidations[ $taxonomy ] = true;
			return;
		}

		$this->cache->delete_group( 'folders_' . $taxonomy );
		FolderRepository::clear_runtime_cache();
	}

	public function invalidate_coalesced(string $taxonomy, int $debounce_ttl = 5): void {
		$blog_id = get_current_blog_id();
		$flag_key = 'debounce_' . $blog_id . '_' . md5( $taxonomy );
		if ( ! wp_cache_add( $flag_key, 1, 'plathix', $debounce_ttl ) ) {
			return;
		}

		$this->invalidate( $taxonomy );
	}

	public function invalidate_all_taxonomies(): void {
		$taxonomies = array_unique( array_merge( [ PLATHIX_TAXONOMY ], Taxonomy::get_enabled_taxonomies() ) );
		foreach ( $taxonomies as $taxonomy ) {
			$this->invalidate_coalesced( (string) $taxonomy );
		}
	}

	public function begin_bulk_write(string $taxonomy): void {
		$this->bulk_invalidations[ $taxonomy ] = false;
	}

	public function end_bulk_write(string $taxonomy): void {
		if ( ! array_key_exists( $taxonomy, $this->bulk_invalidations ) ) {
			return;
		}

		unset( $this->bulk_invalidations[ $taxonomy ] );
		$this->cache->delete_group( 'folders_' . $taxonomy );
		FolderRepository::clear_runtime_cache();
	}
}
