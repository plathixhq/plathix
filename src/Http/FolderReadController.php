<?php

declare(strict_types=1);

namespace Plathix\Http;

use Plathix\Core\AttachmentVisibility;
use Plathix\Core\FolderCountService;

/**
 * REST read-запросы по папкам: список дерева, одна папка, элементы папки (grid-DTO).
 * Часть распила FolderController ([internal] #94) — read-query слой.
 *
 * Маршрутизация и permission_callback остаются в RestController/RestRouteRegistry.
 * Зависимость — только FolderCountService (read читает кэш дерева; repository/tree/
 * rate_limiter read НЕ нужны — не мутирует, не лимитируется). build_folder_item_row —
 * приватный DTO-маппер (единственный потребитель — здесь), НЕ отдельный класс.
 */
final class FolderReadController
{
	use RestControllerHelpers;

	public function __construct(
		private readonly FolderCountService $folders,
	) {
	}

	public function get_folders(\WP_REST_Request $request): \WP_REST_Response {
		$taxonomy  = $this->request_taxonomy( $request );
		$search    = sanitize_text_field( (string) $request->get_param( 'search' ) );
		$ids       = $this->sanitize_ids_param( $request->get_param( 'ids' ) );
		$parent_id = $request->has_param( 'parent_id' ) ? absint( $request->get_param( 'parent_id' ) ) : null;
		// sanitize_key() lowercase'ит значения (WP core, не меняем) — fields=hasChildren
		// приходит как 'haschildren'. Сравниваем со shape-ключами через ту же sanitize_key(),
		// применённую к обеим сторонам — не полагаемся на strtolower() отдельно, чтобы не
		// разойтись с sanitize_key() при любых её нюансах (см. [internal]).
		$fields    = array_values(
			array_filter(
				array_map(
					'sanitize_key',
					preg_split( '/\s*,\s*/', (string) $request->get_param( 'fields' ) ) ?: []
				)
			)
		);
		$has_field = static function (string $field) use ($fields): bool {
			return in_array( sanitize_key( $field ), $fields, true );
		};

		// Partial path: only direct children of a single parent, no search, no id filter.
		// FolderCountService::get_children fetches only the needed subset — no full tree load.
		$use_partial = null !== $parent_id && $search === '' && $ids === [];
		if ( $use_partial ) {
			$folders = $this->folders->get_children( $parent_id, $taxonomy );
			$payload = array_map(
				static function (\Plathix\Core\FolderDTO $folder) use ($fields, $has_field): array {
					$data = $folder->to_array();
					if ( array_key_exists( 'count', $data ) && $data['count'] === null ) {
						$data['count'] = 0;
					}
					// hasChildren is already set by get_children — no map needed.
					if ( $fields === [] ) {
						return $data;
					}
					$subset = [];
					foreach ( $data as $key => $value ) {
						if ( $has_field( (string) $key ) ) {
							$subset[ $key ] = $value;
						}
					}
					if ( $has_field( 'id' ) && ! array_key_exists( 'id', $subset ) && array_key_exists( 'id', $data ) ) {
						$subset['id'] = $data['id'];
					}
					if ( $has_field( 'count' ) && ! array_key_exists( 'count', $subset ) ) {
						$subset['count'] = 0;
					}
					if ( $has_field( 'hasChildren' ) && ! array_key_exists( 'hasChildren', $subset ) ) {
						$subset['hasChildren'] = false;
					}
					return $subset;
				},
				$folders
			);
			return new \WP_REST_Response( [ 'folders' => $payload, 'taxonomy' => $taxonomy, 'fullTree' => false ] );
		}

		// Full-tree path: search, ids filter, or no parent_id specified.
		$folders = $this->folders->get_all_cached( $taxonomy );
		$has_children_map = [];
		foreach ( $folders as $folder ) {
			$folder_parent_id = (int) $folder->parentId;
			if ( $folder_parent_id > 0 ) {
				$has_children_map[ $folder_parent_id ] = true;
			}
		}

		if ( $search !== '' ) {
			$folders = array_values(
				array_filter(
					$folders,
					static function (\Plathix\Core\FolderDTO $folder) use ($search): bool {
						return str_contains( strtolower( $folder->name ), strtolower( $search ) );
					}
				)
			);
		}

		if ( $ids !== [] ) {
			$index = array_fill_keys( array_map( 'strval', $ids ), true );
			$folders = array_values(
				array_filter(
					$folders,
					static function (\Plathix\Core\FolderDTO $folder) use ($index): bool {
						return isset( $index[ (string) $folder->id ] );
					}
				)
			);
		}

		$payload = array_map(
			static function (\Plathix\Core\FolderDTO $folder) use ($fields, $has_field, $has_children_map): array {
				$data = $folder->to_array();
				$data['hasChildren'] = ! empty( $has_children_map[ (int) ( $data['id'] ?? 0 ) ] );
				if ( array_key_exists( 'count', $data ) && $data['count'] === null ) {
					$data['count'] = 0;
				}

				if ( $fields === [] ) {
					return $data;
				}

				$subset = [];
				foreach ( $data as $key => $value ) {
					if ( $key === 'count' && $value === null ) {
						$value = 0;
					}
					if ( $has_field( (string) $key ) ) {
						$subset[ $key ] = $value;
					}
				}

				if ( $has_field( 'id' ) && ! array_key_exists( 'id', $subset ) && array_key_exists( 'id', $data ) ) {
					$subset['id'] = $data['id'];
				}
				if ( $has_field( 'count' ) && ! array_key_exists( 'count', $subset ) ) {
					$subset['count'] = 0;
				}
				if ( $has_field( 'hasChildren' ) && ! array_key_exists( 'hasChildren', $subset ) ) {
					$subset['hasChildren'] = false;
				}

				return $subset;
			},
			$folders
		);

		// fullTree: true только если ответ действительно содержит весь список папок —
		// не урезан search/ids фильтром ([internal]). НЕ зависит от fields: fields режет
		// поля внутри записи, не состав списка. Единственный источник истины для клиента
		// (navigation.js читает это поле вместо реконструкции по параметрам запроса).
		return new \WP_REST_Response( [ 'folders' => $payload, 'taxonomy' => $taxonomy, 'fullTree' => $search === '' && $ids === [] ] );
	}

	public function get_folder(\WP_REST_Request $request, ?\Closure $loader_override = null): \WP_REST_Response {
		$id       = absint( $request->get_param( 'id' ) );
		$taxonomy = $this->request_taxonomy( $request );

		$folder = $this->run_optional_override(
			$loader_override,
			fn (): array => $this->load_single_folder( $id, $taxonomy ),
			$id,
			$taxonomy
		);

		if ( ! is_array( $folder ) || $folder === [] ) {
			return new \WP_REST_Response( [ 'message' => __( 'Folder no longer exists.', 'plathix' ) ], 404 );
		}

		return new \WP_REST_Response( [ 'folder' => $folder, 'taxonomy' => $taxonomy ] );
	}

	public function get_folder_items(\WP_REST_Request $request, ?\Closure $loader_override = null): \WP_REST_Response {
		$folder_id = absint( $request->get_param( 'id' ) );
		$post_type = sanitize_key( (string) ( $request->get_param( 'post_type' ) ?: 'attachment' ) );
		$taxonomy  = $this->request_taxonomy( $request );
		$page      = max( 1, absint( $request->get_param( 'paged' ) ) ?: 1 );
		$per_page  = min( 200, max( 1, absint( $request->get_param( 'per_page' ) ) ?: 50 ) );
		$fields    = array_values(
			array_filter(
				array_map(
					'sanitize_key',
					preg_split( '/\s*,\s*/', (string) $request->get_param( 'fields' ) ) ?: []
				)
			)
		);

		$result = $this->run_optional_override(
			$loader_override,
			fn (): array => $this->load_folder_items( $folder_id, $post_type, $taxonomy, $page, $per_page, $fields ),
			$folder_id,
			$post_type,
			$taxonomy,
			$page,
			$per_page,
			$fields
		);

		return new \WP_REST_Response( RestFolderResponseBuilders::folder_items( $result, $taxonomy, $folder_id, $page, $per_page ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function load_single_folder(int $id, string $taxonomy): array {
		$folders = $this->folders->get_all_cached( $taxonomy );
		foreach ( $folders as $folder ) {
			$data = $folder instanceof \Plathix\Core\FolderDTO ? $folder->to_array() : (array) $folder;
			if ( (int) ( $data['id'] ?? 0 ) === $id ) {
				return $data;
			}
		}

		return [];
	}

	/**
	 * Единый WP_Query для items+total ([internal] MSC-101, [internal]/#464-scale):
	 * заменяет "тянуть ВСЕ id папки через get_objects_in_term(), затем array_slice в PHP"
	 * (O(вся папка) на каждый листинг, плюс поштучный get_post_status() в цикле —
	 * добавлен сегодняшними [internal]/[internal] при закрытии #457
	 * корректности, но не масштаба). tax_query LIMIT'ится в БД по индексу
	 * term_relationships (PK object_id+term_taxonomy_id), SQL_CALC_FOUND_ROWS даёт total
	 * тем же запросом, одним правилом видимости.
	 *
	 * Видимость (generator-hidden + trash/auto-draft) инжектируется через `posts_where`
	 * — тот же предикат, что уже владеет AttachmentVisibility (FolderCountCalculator/
	 * MediaStatsService используют его в сыром SQL; здесь тот же текст, но внутри
	 * WP_Query). Фильтр снимается сразу после query() — не должен просочиться в другие
	 * запросы того же request lifecycle.
	 *
	 * Алиас таблицы posts, передаваемый в предикат, ОБЯЗАН совпадать с тем, что реально
	 * ссылается в SQL, который строит WP_Query для этого набора параметров — здесь это
	 * голое имя таблицы ($wpdb->posts), не алиас 'p' ([internal]: WP_Query в этом
	 * запросе не алиасит posts, захардкоженный 'p' давал "Unknown column 'p.ID'",
	 * молча проглоченную $wpdb, found_posts=0 для любой папки).
	 *
	 * @param string[] $fields
	 * @return array{items: list<array<string, int|string>>, total: int, page: int, per_page: int}
	 */
	private function load_folder_items(int $folder_id, string $post_type, string $taxonomy, int $page, int $per_page, array $fields): array {
		global $wpdb;
		$posts_alias = $wpdb->posts;
		$predicate   = AttachmentVisibility::sql_predicate( $posts_alias ) . ' AND ' . AttachmentVisibility::status_sql_predicate( $posts_alias );
		$where_filter = static function (string $where) use ($predicate): string {
			return $where . ' AND ' . $predicate;
		};

		add_filter( 'posts_where', $where_filter );
		try {
			$query = new \WP_Query( [
				'post_type'              => $post_type,
				'post_status'            => 'any',
				'tax_query'              => [ [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- indexed term_relationships JOIN, LIMIT applied by WP_Query itself (the O(all-ids) scan this replaces)
					'taxonomy' => $taxonomy,
					'field'    => 'term_id',
					'terms'    => $folder_id,
				] ],
				'paged'                   => $page,
				'posts_per_page'          => $per_page,
				'orderby'                 => 'ID',
				'order'                   => 'DESC',
				'no_found_rows'           => false,
				'update_post_meta_cache'  => false,
				'update_post_term_cache'  => false,
			] );
		} finally {
			remove_filter( 'posts_where', $where_filter );
		}

		$items = [];
		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			$items[] = $this->build_folder_item_row( $post, $fields );
		}

		return [
			'items'    => $items,
			'total'    => (int) $query->found_posts,
			'page'     => $page,
			'per_page' => $per_page,
		];
	}

	/**
	 * @param string[] $fields
	 * @return array<string, int|string>
	 */
	private function build_folder_item_row(\WP_Post $post, array $fields): array {
		/** @var \WP_Post&object{ID:int,post_title:string,post_date_gmt:string,post_author:string,post_parent:int,post_name:string,post_mime_type:string} $post -- phpstan-wordpress stub omits declared WP_Post properties */
		$data = [
			'id'     => (int) $post->ID,
			'title'  => (string) $post->post_title,
			'status' => (string) $post->post_status,
			'type'   => (string) $post->post_type,
			'date'   => (string) $post->post_date_gmt,
			'author' => (int) $post->post_author,
			'parent' => (int) $post->post_parent,
			'slug'   => (string) $post->post_name,
			'mime'   => (string) $post->post_mime_type,
		];

		if ( $fields === [] ) {
			return $data;
		}

		$data = array_intersect_key( $data, array_fill_keys( $fields, true ) );
		if ( ! array_key_exists( 'id', $data ) ) {
			$data['id'] = (int) $post->ID;
		}

		return $data;
	}
}
