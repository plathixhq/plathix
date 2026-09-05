<?php

declare(strict_types=1);

namespace Plathix\Modules\Preset;

final class PresetRepository
{
	/**
	 * @param array<string, mixed> $record
	 * @return int|\WP_Error
	 */
	public function create(array $record): int|\WP_Error {
		global $wpdb;

		$normalized = $this->normalize_record($record);

		// Если слаг занят, берём свободный с суффиксом (-2, -3…). Идентичные дубли
		// уже отсечены валидатором (PresetValidator::structures_equal) — сюда доходит
		// только новый пресет с конфликтующим именем, ему нужен уникальный слаг.
		$normalized['slug'] = $this->unique_slug($normalized['slug']);

		if ( ! \is_object($wpdb) || ! \method_exists($wpdb, 'insert') ) {
			return new \WP_Error('preset_storage_unavailable', __('Preset storage is unavailable.', 'plathix'));
		}

		// Retry при slug-гонке (G3, [internal]): unique_slug (check) и insert (use) не
		// атомарны — параллельный create мог занять слаг между ними, тогда insert падает на
		// UNIQUE blog_slug. Распознаём duplicate по факту занятости слага (надёжнее парсинга
		// last_error), перегенерируем и повторяем. Не duplicate (тип/соединение) → сразу
		// ошибка. Инвариант [internal] не затронут: идентичные дубли отсечены
		// валидатором ДО create, retry срабатывает только на гонке двух РАЗНЫХ create.
		$formats = [ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' ];

		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			$inserted = $wpdb->insert( PresetSchema::table_name(), $normalized, $formats ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- INSERT into the plugin's own preset table; nothing to cache on a write

			if ( $inserted !== false ) {
				return (int) ( $wpdb->insert_id ?? 0 );
			}

			// insert упал: если слаг теперь занят — это гонка, берём новый и повторяем.
			// Если слаг свободен — причина не в дубле, дальше повторять бессмысленно.
			if ( $this->find_by_slug($normalized['slug']) === null ) {
				break;
			}

			$normalized['slug'] = $this->unique_slug($normalized['slug']);
		}

		return new \WP_Error('preset_insert_failed', __('Failed to store preset record.', 'plathix'));
	}

	/**
	 * @param array<string, mixed> $record
	 * @return int|\WP_Error
	 */
	public function upsert_by_slug(array $record): int|\WP_Error {
		$existing = $this->find_by_slug( (string) ($record['slug'] ?? ''));

		if ( $existing === null ) {
			return $this->create($record);
		}

		$updated = $this->update( (int) ($existing['id'] ?? 0), $record);
		if ( is_wp_error($updated) ) {
			/** @var \WP_Error $updated Narrowed inside is_wp_error() guard (see [internal] #6). */
			return $updated;
		}

		return (int) ($existing['id'] ?? 0);
	}

	/**
	 * @param array<string, mixed> $record
	 */
	public function update(int $id, array $record): bool|\WP_Error {
		global $wpdb;

		if ( $id <= 0 ) {
			return new \WP_Error('preset_invalid_id', __('Invalid preset id.', 'plathix'));
		}

		if ( ! \is_object($wpdb) || ! \method_exists($wpdb, 'update') ) {
			return new \WP_Error('preset_storage_unavailable', __('Preset storage is unavailable.', 'plathix'));
		}

		$current = $this->find($id);
		if ( $current === null ) {
			return new \WP_Error('preset_not_found', __('Preset record was not found.', 'plathix'));
		}

		$merged = array_merge($current, $record);
		unset($merged['id']);
		$normalized = $this->normalize_record($merged, false);

		$slug_owner = $this->find_by_slug($normalized['slug']);
		if ( $slug_owner !== null && (int) ($slug_owner['id'] ?? 0) !== $id ) {
			return new \WP_Error('preset_slug_conflict', __('Preset slug already exists.', 'plathix'));
		}

		$updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- UPDATE of the plugin's own preset row; nothing to cache on a write
			PresetSchema::table_name(),
			$normalized,
			[ 'id' => $id, 'blog_id' => (int) get_current_blog_id() ],
			[ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' ],
			[ '%d', '%d' ]
		);

		return $updated !== false;
	}

	/** @return array<string, mixed>|null */
	public function find(int $id): ?array {
		if ( $id <= 0 ) {
			return null;
		}

		return $this->find_one_by('id', $id);
	}

	/** @return array<string, mixed>|null */
	public function find_by_slug(string $slug): ?array {
		$slug = sanitize_key($slug);
		if ( $slug === '' ) {
			return null;
		}

		return $this->find_one_by('slug', $slug);
	}

	/**
	 * [internal] ([internal]): единственная точка одиночного чтения пресета.
	 *
	 * find() и find_by_slug() были дословными близнецами, отличаясь колонкой и типом
	 * плейсхолдера, — и несли по два одинаковых phpcs:ignore каждый. Колонка приходит
	 * ТОЛЬКО из белого списка ниже: имя колонки нельзя связать плейсхолдером, поэтому
	 * единственная защита от инъекции через идентификатор — то, что значение никогда не
	 * приходит из запроса.
	 *
	 * Имя таблицы связывается нативным %i (WP 6.2+; наш минимум — 7.0).
	 *
	 * @param 'id'|'slug' $column
	 * @return array<string, mixed>|null
	 */
	private function find_one_by(string $column, int|string $value): ?array {
		global $wpdb;

		// Белый список: колонка приходит только из кода. Имя колонки нельзя связать
		// плейсхолдером, поэтому единственная защита — то, что значение не из запроса.
		if ( ! in_array( $column, [ 'id', 'slug' ], true ) || ! \is_object($wpdb) || ! \method_exists($wpdb, 'prepare') || ! \method_exists($wpdb, 'get_row') ) {
			return null;
		}

		// Плейсхолдер НЕ интерполируется в строку: Plugin Check (инструмент WP.org) считает
		// плейсхолдеры только в литерале запроса и на подставленном переменной выдаёт два
		// ложных WARNING (ReplacementsWrongNumber + UnescapedDBParameter, проверено на стенде).
		// Поэтому ветка на две литеральные строки — одна на колонку, обе с явным %d/%s.
		$table = PresetSchema::table_name();

		$sql = $column === 'id'
			? $wpdb->prepare( 'SELECT * FROM ' . $table . ' WHERE id = %d AND blog_id = %d LIMIT 1', $value, (int) get_current_blog_id() ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix + a schema constant, never user input; both values are bound as placeholders. %i not used here: verified on stand it triggers two false Plugin Check WARNINGs (ReplacementsWrongNumber + UnescapedDBParameter) when the table name is a variable instead of a literal (see class docblock above) — string concatenation is the deliberate choice, not an unreviewed gap.
			: $wpdb->prepare( 'SELECT * FROM ' . $table . ' WHERE slug = %s AND blog_id = %d LIMIT 1', $value, (int) get_current_blog_id() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix + a schema constant, never user input; both values are bound as placeholders. %i not used here: same Plugin Check false-WARNING reason as the branch above (see class docblock).

		$row = $wpdb->get_row($sql, ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $sql is the return value of $wpdb->prepare() above, which the sniff cannot follow across the assignment; custom preset table, single-row read on a rare admin screen, never on the front-end path

		return \is_array($row) ? $this->hydrate_row($row) : null;
	}

	/**
	 * Возвращает последний применённый пресет текущего блога (max last_applied_at) или null,
	 * если ни один пресет ещё не применялся. Один целевой запрос вместо загрузки всего каталога
	 * и перебора в PHP — дашборд читает только одну строку.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find_last_applied(): ?array {
		global $wpdb;

		if ( ! \is_object($wpdb) || ! \method_exists($wpdb, 'prepare') || ! \method_exists($wpdb, 'get_row') ) {
			return null;
		}

		$sql = $wpdb->prepare(
			'SELECT * FROM ' . PresetSchema::table_name() . ' WHERE blog_id = %d AND last_applied_at IS NOT NULL ORDER BY last_applied_at DESC, id DESC LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- custom preset table (PresetSchema::table_name(), prefix+const); blog_id bound via %d; single-row dashboard read; caching not worthwhile for a rare admin-only lookup. %i not used: same false-Plugin-Check-WARNING reason as find_one_by() above (verified on stand, see class docblock at the top of this file).
			(int) get_current_blog_id()
		);
		$row = $wpdb->get_row($sql, ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $sql prepared above; custom preset table; single-row rare admin read (dashboard), high-cardinality → cache N/A, not on front path

		return \is_array($row) ? $this->hydrate_row($row) : null;
	}

	/**
	 * Возвращает свободный слаг: исходный, если не занят; иначе `slug-2`, `slug-3`…
	 *
	 * Используется при импорте, когда новый пресет (с отличающимся содержимым)
	 * приходит с уже занятым слагом — чтобы загрузить его как отдельный пресет, не
	 * перезаписывая существующий. Результат остаётся валидным (sanitize_key).
	 */
	public function unique_slug(string $slug): string {
		$slug = sanitize_key($slug);
		if ( $slug === '' || $this->find_by_slug($slug) === null ) {
			return $slug;
		}

		$n = 2;
		while ( true ) {
			$candidate = sanitize_key($slug . '-' . $n);
			if ( $this->find_by_slug($candidate) === null ) {
				return $candidate;
			}
			$n++;
		}
	}

	/**
	 * @param array<string, mixed> $filters
	 * @return array<int, array<string, mixed>>
	 */
	public function list(array $filters = []): array {
		global $wpdb;

		if ( ! \is_object($wpdb) || ! \method_exists($wpdb, 'prepare') || ! \method_exists($wpdb, 'get_results') ) {
			return [];
		}

		$where  = [ 'blog_id = %d' ];
		$params = [ (int) get_current_blog_id() ];

		$source_type = sanitize_key( (string) ($filters['source_type'] ?? ''));
		if ( $source_type !== '' ) {
			$where[]  = 'source_type = %s';
			$params[] = $source_type;
		}

		$validation_status = sanitize_key( (string) ($filters['validation_status'] ?? ''));
		if ( $validation_status !== '' ) {
			$where[]  = 'validation_status = %s';
			$params[] = $validation_status;
		}

		$sql = $wpdb->prepare(
			'SELECT * FROM ' . PresetSchema::table_name() . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY title ASC, id ASC', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- table name via PresetSchema::table_name() (prefix+const); placeholders come from interpolated $where clauses (each carries %s/%d); values bound via ...$params; not injectable. %i not used for the table name: same false-Plugin-Check-WARNING reason as find_one_by()/find_last_applied() above (verified on stand, see class docblock).
			...$params
		);
		$rows = $wpdb->get_results($sql, ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql prepared via $wpdb->prepare() above; custom preset table list read, rare admin-screen access, high-cardinality filters → cache hit-rate near-zero without persistent backend; not on front-end path

		if ( ! \is_array($rows) ) {
			return [];
		}

		return \array_values(\array_map(fn (array $row): array => $this->hydrate_row($row), $rows));
	}

	public function delete(int $id): bool {
		global $wpdb;

		if ( $id <= 0 || ! \is_object($wpdb) || ! \method_exists($wpdb, 'delete') ) {
			return false;
		}

		$deleted = $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- DELETE from the plugin's own preset table; nothing to cache on a write
			PresetSchema::table_name(),
			[ 'id' => $id, 'blog_id' => (int) get_current_blog_id() ],
			[ '%d', '%d' ]
		);

		return $deleted !== false;
	}

	/**
	 * @param array<string, mixed> $record
	 * @return array<string, mixed>
	 */
	private function normalize_record(array $record, bool $include_created_at = true): array {
		$now = gmdate('Y-m-d H:i:s');
		$created_at = $include_created_at
			? $now
			: (string) ($record['created_at'] ?? $now);
		$updated_at = $now;
		// Кап описания: у title/author/url лимиты есть, у description не было, а
		// колонка TEXT примет мегабайт и вынесет его на карточку пресета.
		$description = \mb_substr(\trim( wp_strip_all_tags( (string) ($record['description'] ?? '')) ), 0, 2000);
		$title       = \trim( sanitize_text_field( (string) ($record['title'] ?? '')) );
		$slug        = sanitize_key( (string) ($record['slug'] ?? ''));

		return [
			'blog_id'           => (int) get_current_blog_id(),
			'source_type'       => PresetSourceType::normalize( (string) ($record['source_type'] ?? PresetSourceType::CUSTOM)),
			'slug'              => $slug,
			'title'             => \mb_substr($title, 0, 191),
			'version'           => \mb_substr(\trim( (string) ($record['version'] ?? '1')), 0, 32),
			'description'       => $description,
			'tags_json'         => wp_json_encode(\array_values(\array_map('strval', (array) ($record['tags'] ?? [])))),
			'author_name'       => \mb_substr(\trim( sanitize_text_field( (string) ($record['author_name'] ?? $record['author'] ?? '')) ), 0, 191),
			// esc_url_raw со списком схем: поле пришло из чужого файла, и первая же
			// доработка UI, повесившая его на href, получила бы `javascript:`.
			'author_url'        => \mb_substr(esc_url_raw(\trim( (string) ($record['author_url'] ?? '')), ['http', 'https']), 0, 255),
			'preview_ref'       => \mb_substr(\trim( (string) ($record['preview_ref'] ?? '')), 0, 255),
			'storage_path'      => \mb_substr(\trim( (string) ($record['storage_path'] ?? '')), 0, 255),
			'validation_status' => \mb_substr(sanitize_key( (string) ($record['validation_status'] ?? 'valid')), 0, 32),
			'last_error_code'   => \mb_substr(sanitize_key( (string) ($record['last_error_code'] ?? '')), 0, 64),
			'folder_count'      => max(0, (int) ($record['folder_count'] ?? 0)),
			'structure_json'    => wp_json_encode($record['structure'] ?? []),
			'created_at'        => $created_at,
			'updated_at'        => $updated_at,
			'last_applied_at'   => $this->normalize_nullable_datetime($record['last_applied_at'] ?? null),
		];
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function hydrate_row(array $row): array {
		return [
			'id'                => (int) ($row['id'] ?? 0),
			'blog_id'           => (int) ($row['blog_id'] ?? 0),
			'source_type'       => (string) ($row['source_type'] ?? ''),
			'slug'              => (string) ($row['slug'] ?? ''),
			'title'             => (string) ($row['title'] ?? ''),
			'version'           => (string) ($row['version'] ?? '1'),
			'description'       => (string) ($row['description'] ?? ''),
			'tags'              => $this->decode_json_array($row['tags_json'] ?? '[]'),
			'author_name'       => (string) ($row['author_name'] ?? ''),
			'author_url'        => (string) ($row['author_url'] ?? ''),
			'preview_ref'       => (string) ($row['preview_ref'] ?? ''),
			'storage_path'      => (string) ($row['storage_path'] ?? ''),
			'validation_status' => (string) ($row['validation_status'] ?? 'valid'),
			'last_error_code'   => (string) ($row['last_error_code'] ?? ''),
			'folder_count'      => (int) ($row['folder_count'] ?? 0),
			'structure'         => $this->decode_json_array($row['structure_json'] ?? '[]'),
			'created_at'        => (string) ($row['created_at'] ?? ''),
			'updated_at'        => (string) ($row['updated_at'] ?? ''),
			'last_applied_at'   => $row['last_applied_at'] ?: null,
		];
	}

	/** @return array<int|string, mixed> */
	private function decode_json_array(mixed $value): array {
		if ( ! \is_string($value) || $value === '' ) {
			return [];
		}

		$decoded = \json_decode($value, true);

		return \is_array($decoded) ? $decoded : [];
	}

	private function normalize_nullable_datetime(mixed $value): ?string {
		if ( ! \is_string($value) || \trim($value) === '' ) {
			return null;
		}

		return \trim($value);
	}
}
