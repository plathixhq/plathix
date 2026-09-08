<?php

declare(strict_types=1);

namespace Plathix\Core;

final class FolderItemsLoader
{
	/**
	 * @param string[] $fields
	 * @return array{items: list<array<string, int|string>>, total: int, page: int, per_page: int}
	 */

	public function load(int $folder_id, string $post_type, string $taxonomy, int $page, int $per_page, array $fields): array {
		global $wpdb;
		$posts_alias = $wpdb->posts;
		$predicate   = AttachmentVisibility::sqlPredicate( $posts_alias ) . ' AND ' . AttachmentVisibility::statusSqlPredicate( $posts_alias );
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
			$items[] = $this->buildFolderItemRow( $post, $fields );
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
	private function buildFolderItemRow(\WP_Post $post, array $fields): array {
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
