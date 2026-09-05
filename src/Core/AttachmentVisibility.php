<?php

declare(strict_types=1);

namespace Plathix\Core;

/**
 * Единый источник правила «этот attachment реально показывается в медиа-гриде».
 *
 * Предыстория ([internal]): счётчики папок считают все attachment,
 * привязанные к терму, а WordPress-медиа-грид может часть из них НЕ показывать —
 * плагины-генераторы (напр. Elementor) вешают подписчика на `ajax_query_attachments_args`
 * и прячут свои служебные скриншоты из грида (`_elementor_is_screenshot NOT EXISTS`).
 * Из-за этого счётчик = 324, а грид = 319.
 *
 * Правило исключения здесь — НЕ реэкспорт чужого admin-AJAX-хука (это подчинило бы
 * Core-сервис admin-media-подсистеме и дало бы N+1 на дереве папок — отклонено двумя
 * скептиками), а собственный доменный предикат: «не считать attachment, помеченные как
 * служебные». Список meta-ключей служебности расширяется через фильтр
 * {@see AttachmentVisibility::EXCLUDE_META_FILTER}, которым владеет Plathix; по умолчанию —
 * `_elementor_is_screenshot`.
 *
 * Класс контекст-независим: читает только фильтр и БД, не трогает `$_REQUEST`,
 * `wp_doing_ajax()` или current_screen — поэтому даёт одинаковый результат в REST, CLI,
 * cron, дереве сайдбара и админ-гриде.
 *
 * НЕ основан на отсутствии `_wp_attachment_metadata`: замер на стенде показал, что грид
 * рисует image-без-metadata как плитку-заглушку — значит отсутствие metadata не является
 * признаком невидимости, а такой критерий вырезал бы легитимные битые/без-превью картинки.
 */
final class AttachmentVisibility
{
	/**
	 * Фильтр: список meta-ключей, наличие которых помечает attachment как служебный
	 * (скрытый из медиа-грида генератором) → не считать в счётчиках.
	 *
	 * @param string[] $keys По умолчанию `['_elementor_is_screenshot']`.
	 * @return string[]
	 */
	public const EXCLUDE_META_FILTER = 'plathix/count_exclude_meta';

	/**
	 * Собрать список исключаемых meta-ключей (через фильтр, один раз на вызов).
	 * Отсеивает не-строки и пустые значения, чтобы фильтр не мог сломать SQL.
	 *
	 * @return string[]
	 */
	public static function exclude_meta_keys(): array {
		$default = [ '_elementor_is_screenshot' ];
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- EXCLUDE_META_FILTER constant already resolves to the prefixed literal 'plathix/count_exclude_meta'; static analysis doesn't evaluate the constant.
		$keys = apply_filters( self::EXCLUDE_META_FILTER, $default );

		if ( ! is_array( $keys ) ) {
			return $default;
		}

		$clean = [];
		foreach ( $keys as $key ) {
			if ( is_string( $key ) && $key !== '' ) {
				$clean[] = $key;
			}
		}

		// Дедуп, значения на выходе используются только внутри esc_sql-ветки sql_predicate().
		return array_values( array_unique( $clean ) );
	}

	/**
	 * SQL-предикат для WHERE: истина для attachment, которые НЕ помечены служебными.
	 * Один коррелированный NOT EXISTS — без per-row PHP, без чужого хука.
	 *
	 * Пустой список ключей → `'1=1'` (no-op): без активных генераторов фикс ничего не режет.
	 *
	 * @param string $posts_alias Alias таблицы posts в запросе (напр. `p`).
	 */
	public static function sql_predicate(string $posts_alias): string {
		$keys = self::exclude_meta_keys();
		if ( $keys === [] ) {
			return '1=1';
		}

		global $wpdb;
		$in_list = implode(
			',',
			array_map( static fn (string $k): string => "'" . esc_sql( $k ) . "'", $keys )
		);

		// $posts_alias — caller-controlled идентификатор; $in_list собран из esc_sql'нутых
		// строк; $wpdb->postmeta — core-имя таблицы. Фрагмент безопасен для интерполяции.
		return "NOT EXISTS (
			SELECT 1 FROM {$wpdb->postmeta} plx_vis_m
			 WHERE plx_vis_m.post_id = {$posts_alias}.ID
			   AND plx_vis_m.meta_key IN ({$in_list})
		)";
	}

	/**
	 * Свести список attachment-ID к тем, что реально показываются в гриде — тем же
	 * правилом, что {@see sql_predicate()}. Для мест, где ID уже на руках (грид-total),
	 * без повторного term-join. Сохраняет порядок вызывающего.
	 *
	 * @param array<int> $ids
	 * @return array<int>
	 */
	public static function filter_ids(array $ids): array {
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		if ( $ids === [] ) {
			return [];
		}

		$keys = self::exclude_meta_keys();
		if ( $keys === [] ) {
			return $ids; // no-op: нечего исключать
		}

		global $wpdb;
		$id_list = implode( ',', $ids );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $id_list is absint'd, predicate keys are esc_sql'd, $wpdb->* are core table names.
		$visible = $wpdb->get_col(
			"SELECT p.ID
			   FROM {$wpdb->posts} p
			  WHERE p.ID IN ({$id_list})
			    AND " . self::sql_predicate( 'p' )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$visible_set = array_flip( array_map( 'intval', (array) $visible ) );

		return array_values( array_filter( $ids, static fn (int $id): bool => isset( $visible_set[ $id ] ) ) );
	}

	/**
	 * Единый visibility-aware подсчёт attachment для user-facing счётчиков (dashboard «типы контента»,
	 * «N файлов», «потерянные»): COUNT по заданным post_status, исключая generator-hidden вложения тем
	 * же правилом {@see sql_predicate()}.
	 *
	 * Введён, чтобы убрать копии `count_published_posts` (FolderStatsService, ContentTypesWidget),
	 * из-за которых [internal] всплывал по частям — новый счётчик наследует предикат отсюда, а не
	 * забывает его добавить своей копией `wp_count_posts` (техдолг #121, пакет [internal]).
	 * Набор статусов — параметр: счётчики различаются семантикой (storage-total считает `inherit+private`
	 * per [internal], грид-видимые — только `inherit`). Метод остаётся чисто-инфраструктурным — знает про
	 * «видимые attachment по статусам», не про dashboard-семантику.
	 *
	 * @param list<string> $statuses Разрешённые post_status (напр. ['inherit'] или ['inherit','private']).
	 *                               Санитайзятся через esc_sql; пустой список → 0.
	 */
	public static function count_visible(array $statuses): int {
		$statuses = array_values( array_unique( array_filter(
			array_map( static fn ($s): string => is_string( $s ) ? $s : '', $statuses ),
			static fn (string $s): bool => $s !== ''
		) ) );
		if ( $statuses === [] ) {
			return 0;
		}

		global $wpdb;
		$status_list = implode(
			',',
			array_map( static fn (string $s): string => "'" . esc_sql( $s ) . "'", $statuses )
		);
		$visible_predicate = self::sql_predicate( 'p' );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $status_list is esc_sql'd, $visible_predicate is a self-built fragment, $wpdb->posts is a core table name; no raw user input
		$count = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} p
			  WHERE p.post_type = 'attachment'
			    AND p.post_status IN ({$status_list})
			    AND {$visible_predicate}"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return max( 0, (int) $count );
	}

	/**
	 * [internal]: единый источник критерия «не в корзине, не auto-draft» для attachment —
	 * три места (FolderCountCalculator::total_items_count/batch_counts,
	 * FolderReadController::load_folder_items) независимо реализовывали один и тот же
	 * post_status-денylist. НЕ используется для FolderCountCalculator::uncategorized_items_count()
	 * (allowlist IN('inherit','private') — намеренно другой критерий, [internal])
	 * и НЕ для MediaStatsService (single-status allowlist 'inherit' — семантически другое
	 * множество статусов, отдельный техдолг [internal]).
	 *
	 * @var string[]
	 */
	private const NON_VISIBLE_STATUSES = [ 'trash', 'auto-draft' ];

	/**
	 * SQL-предикат для WHERE: истина для attachment, чей post_status НЕ в корзине и не
	 * auto-draft. Та же денylist-семантика в PHP-форме — {@see is_visible_status()}.
	 *
	 * @param string $posts_alias Alias таблицы posts в запросе (напр. `p`).
	 */
	public static function status_sql_predicate(string $posts_alias): string {
		$in_list = implode(
			',',
			array_map( static fn (string $s): string => "'" . esc_sql( $s ) . "'", self::NON_VISIBLE_STATUSES )
		);

		return "{$posts_alias}.post_status NOT IN ({$in_list})";
	}

	/**
	 * PHP-side эквивалент {@see status_sql_predicate()} — для мест, где post_status уже
	 * на руках (не строится SQL-запрос), напр. FolderReadController::load_folder_items().
	 */
	public static function is_visible_status(string $post_status): bool {
		return ! in_array( $post_status, self::NON_VISIBLE_STATUSES, true );
	}

	/**
	 * PHP-side эквивалент {@see sql_predicate()} — для мест, где нужно проверить ОДИН уже
	 * известный `$attachment_id` (не строится SQL-запрос с JOIN на посты), напр.
	 * Upload::assign_folder_on_upload() перед точечным termmeta-инкрементом ([internal]):
	 * без этой проверки upload безусловно инкрементировал рекурсивный счётчик даже для
	 * generator-hidden attachment (напр. `_elementor_is_screenshot`), которые
	 * FolderCountCalculator::batch_counts() (SQL source of truth для cold-seed) никогда не
	 * считает — расхождение росло с каждой такой загрузкой.
	 *
	 * Читает `get_post_meta()` по одному ключу за раз вместо единого SQL `NOT EXISTS`
	 * ({@see sql_predicate()}) — дешевле для одного уже известного id прямо в hot
	 * upload-пути, чем отдельный JOIN-запрос ради одной строки.
	 */
	public static function is_visible_by_meta(int $attachment_id): bool {
		foreach ( self::exclude_meta_keys() as $key ) {
			if ( metadata_exists( 'post', $attachment_id, $key ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Как {@see is_visible_by_meta()}, но игнорирует один конкретный exclude-ключ —
	 * нужен подписчику visibility-переходов ([internal], FolderCountLifecycle):
	 * на `added_post_meta` спорный ключ УЖЕ записан, и вопрос «был ли файл счётным до
	 * этого события» требует предиката «visible, если бы не этот ключ». Файл с двумя
	 * exclude-ключами был hidden и до события — дельты не полагается.
	 */
	public static function is_visible_by_meta_except(int $attachment_id, string $ignored_key): bool {
		foreach ( self::exclude_meta_keys() as $key ) {
			if ( $key === $ignored_key ) {
				continue;
			}
			if ( metadata_exists( 'post', $attachment_id, $key ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * [internal]: единый источник allowlist-критерия post_status как SQL-фрагмент — для мест
	 * с `GROUP BY` (MediaStatsService::mime_stats()/upload_activity()), где готовый
	 * {@see count_visible()} (возвращает `int`) не подходит.
	 *
	 * Без параметра $statuses, в отличие от {@see status_sql_predicate()}: денylist там один
	 * фиксированный набор, одинаковый для всех вызывающих (`NON_VISIBLE_STATUSES`), а allowlist —
	 * множественный и вызывающе-специфичный, ровно как у {@see count_visible()} (`['inherit']` у
	 * PRO ContentTypesWidget, `['inherit','private']` у FolderStatsService). Асимметрия пары
	 * denylist/allowlist осознанная, не забытая унификация (см. spec
	 * [internal], Rejected Alternatives §2).
	 *
	 * `count_visible()` НЕ рефакторится на этот метод: у него живой cross-repo потребитель
	 * (PRO `ContentTypesWidget::count_visible(['inherit'])`), не покрытый тестами/CI этого
	 * репозитория — небольшое дублирование санитайза статусов между двумя методами дешевле
	 * непроверяемого риска для PRO.
	 *
	 * @param list<string> $statuses    Разрешённые post_status. Санитайзятся через esc_sql;
	 *                                  пустой список после санитайза → `'1=0'` (allowlist без
	 *                                  разрешённых статусов не матчит ничего — не `'1=1'`, как
	 *                                  в {@see sql_predicate()}, где пустой exclude-список значит
	 *                                  «ничего не исключаем»: разная семантика denylist/allowlist).
	 * @param string        $posts_alias Alias таблицы posts в запросе (напр. `p`).
	 */
	public static function statusInPredicate(array $statuses, string $posts_alias): string {
		$statuses = array_values( array_unique( array_filter(
			array_map( static fn ($s): string => is_string( $s ) ? $s : '', $statuses ),
			static fn (string $s): bool => $s !== ''
		) ) );
		if ( $statuses === [] ) {
			return '1=0';
		}

		$in_list = implode(
			',',
			array_map( static fn (string $s): string => "'" . esc_sql( $s ) . "'", $statuses )
		);

		return "{$posts_alias}.post_status IN ({$in_list})";
	}
}
