<?php

declare(strict_types=1);

namespace Plathix\Http;

use Plathix\Core\FolderCountService;
use Plathix\Core\FolderMutationService;
use Plathix\Core\FolderRepository;
use Plathix\Core\FolderTreeService;
use Plathix\Infrastructure\RateLimiter;

/**
 * Пакетные REST-операции по папкам: batch create/delete/update, recount, reorder tree.
 * Часть распила FolderController ([internal] #94) — batch слой.
 *
 * run_batch_update_folders применяет per-item изменения через общий
 * Core\FolderMutationService::apply_changes (тот же применитель, что single-update — прежний
 * приватный apply_batch_folder_updates удалён, дубль устранён). Batch НЕ эмитит per-item
 * аудит (apply_changes аудит не трогает → инвариант сохранён автоматически); эмитится только
 * bulk-аудит (folders_*_bulk). Аккумулятор failed[] (RestFailureValues) сохранён 1:1.
 *
 * injected-раннеры ($runner_override) — тест-seam через run_optional_override; механизм
 * сохранён. Маршрутизация и permission_callback остаются в RestController/RestRouteRegistry.
 */
final class FolderBatchController
{
	use RestControllerHelpers;

	public function __construct(
		private readonly FolderRepository $repository,
		private readonly FolderCountService $folders,
		private readonly FolderTreeService $tree,
		private readonly RateLimiter $rate_limiter,
		private readonly FolderMutationService $mutations,
	) {
	}

	public function batch_create_folders(\WP_REST_Request $request, ?\Closure $runner_override = null, ?\Closure $audit_runner = null): \WP_REST_Response {
		// [internal]: порог здесь (20 запросов/60с) — per-BATCH-CALL unit, отличается от
		// single AjaxRouter::create_folder() (RateLimiter::ACTION_LIMITS['create_folder'],
		// 30/60с — per-FOLDER unit). Осознанно не унифицировано: один batch-запрос создаёт
		// много папок разом, унификация либо задушила бы легитимные одиночные bursts, либо
		// недостаточно троттлила бы большие batch-payloads (WP Architecture skeptic review).
		if ( ! $this->rate_limiter->attempt( 'batch_create_folders', get_current_user_id(), max: 20, window: 60 ) ) {
			return new \WP_REST_Response( [ 'message' => __( 'Too many requests.', 'plathix' ) ], 429 );
		}

		$taxonomy = $this->request_taxonomy( $request );
		$items    = array_values( array_filter( (array) $request->get_param( 'items' ), 'is_array' ) );

		if ( $items === [] ) {
			return new \WP_REST_Response( [ 'message' => __( 'No folders selected.', 'plathix' ) ], 422 );
		}

		$result = $this->run_optional_override(
			$runner_override,
			fn (): array => $this->run_batch_create_folders( $items, $taxonomy ),
			$items,
			$taxonomy
		);

		$this->record_audit(
			'folders_created_bulk',
			[
				'objectType' => 'folder',
				'itemsCount' => count( (array) ( $result['created'] ?? [] ) ),
				'summary'    => sprintf( 'Created %d folders', count( (array) ( $result['created'] ?? [] ) ) ),
				'context'    => [
					'taxonomy'  => $taxonomy,
					'post_type'  => (string) $request->get_param( 'post_type' ),
					'created_ids' => array_column( (array) ( $result['created'] ?? [] ), 'id' ),
				],
			],
			$audit_runner
		);

		return new \WP_REST_Response( $result );
	}

	public function batch_delete_folders(\WP_REST_Request $request, ?\Closure $runner_override = null, ?\Closure $audit_runner = null): \WP_REST_Response {
		// [internal]: та же per-batch-call vs per-folder единица измерения, что у
		// batch_create_folders() выше — см. комментарий там, не унифицировано осознанно.
		if ( ! $this->rate_limiter->attempt( 'batch_delete_folders', get_current_user_id(), max: 10, window: 60 ) ) {
			return new \WP_REST_Response( [ 'message' => __( 'Too many requests.', 'plathix' ) ], 429 );
		}

		$taxonomy    = $this->request_taxonomy( $request );
		$ids         = $this->sanitize_ids_param( $request->get_param( 'ids' ) );
		$on_children = in_array( (string) $request->get_param( 'on_children' ), [ 'reattach', 'delete' ], true )
			? (string) $request->get_param( 'on_children' )
			: 'reattach';

		if ( $ids === [] ) {
			return new \WP_REST_Response( [ 'message' => __( 'No folders selected.', 'plathix' ) ], 422 );
		}

		$result = $this->run_optional_override(
			$runner_override,
			fn (): array => $this->run_batch_delete_folders( $ids, $taxonomy, $on_children ),
			$ids,
			$taxonomy,
			$on_children
		);

		$this->record_audit(
			'folders_deleted_bulk',
			[
				'objectType' => 'folder',
				'itemsCount' => count( (array) ( $result['deleted'] ?? [] ) ),
				'summary'    => sprintf( 'Deleted %d folders', count( (array) ( $result['deleted'] ?? [] ) ) ),
				'context'    => [
					'taxonomy'     => $taxonomy,
					'post_type'     => (string) $request->get_param( 'post_type' ),
					'on_children'   => $on_children,
					'deleted_ids'   => (array) ( $result['deleted'] ?? [] ),
					'deleted_names' => (array) ( $result['deleted_names'] ?? [] ),
				],
			],
			$audit_runner
		);

		// deleted_names — только для audit context выше, REST response contract (deleted как
		// плоский список id) не расширяется (golden-master фиксирует форму ответа).
		unset( $result['deleted_names'] );

		return new \WP_REST_Response( $result );
	}

	public function batch_update_folders(\WP_REST_Request $request, ?\Closure $runner_override = null, ?\Closure $audit_runner = null): \WP_REST_Response {
		// [internal]: та же per-BATCH-CALL логика, что batch_create_folders (20/60) —
		// сравнимая тяжесть операции (batch update, не рекурсивная мутация дерева).
		if ( ! $this->rate_limiter->attempt( 'batch_update_folders', get_current_user_id(), max: 20, window: 60 ) ) {
			return new \WP_REST_Response( [ 'message' => __( 'Too many requests.', 'plathix' ) ], 429 );
		}

		$taxonomy = $this->request_taxonomy( $request );
		$items    = array_values( array_filter( (array) $request->get_param( 'items' ), 'is_array' ) );

		if ( $items === [] ) {
			return new \WP_REST_Response( [ 'message' => __( 'No folders selected.', 'plathix' ) ], 422 );
		}

		$result = $this->run_optional_override(
			$runner_override,
			fn (): array => $this->run_batch_update_folders( $items, $taxonomy ),
			$items,
			$taxonomy
		);

		$this->record_audit(
			'folders_updated_bulk',
			[
				'objectType' => 'folder',
				'itemsCount' => count( (array) ( $result['updated'] ?? [] ) ),
				'summary'    => sprintf( 'Updated %d folders', count( (array) ( $result['updated'] ?? [] ) ) ),
				'context'    => [
					'taxonomy'   => $taxonomy,
					'post_type'   => (string) $request->get_param( 'post_type' ),
					'updated_ids' => (array) ( $result['updated'] ?? [] ),
				],
			],
			$audit_runner
		);

		return new \WP_REST_Response( $result );
	}

	public function recount_folders(\WP_REST_Request $request, ?\Closure $runner_override = null): \WP_REST_Response {
		$taxonomy = $this->request_taxonomy( $request );
		$result   = $this->run_optional_override(
			$runner_override,
			fn (): array => $this->run_recount_folders( $taxonomy ),
			$taxonomy
		);

		return new \WP_REST_Response(
			[
				'success'  => (bool) ( $result['success'] ?? false ),
				'taxonomy' => $taxonomy,
			]
		);
	}

	public function reorder_tree(\WP_REST_Request $request, ?\Closure $runner_override = null, ?\Closure $audit_runner = null): \WP_REST_Response {
		// [internal]: reorder — самая тяжёлая batch-операция слоя, per-item move+set_order
		// (N мутаций дерева на один запрос), ниже порог, чем batch_create/update (20/60):
		// по аналогии с batch_delete_folders (10/60) — та же тяжесть per-item-мутации дерева.
		if ( ! $this->rate_limiter->attempt( 'reorder_tree', get_current_user_id(), max: 10, window: 60 ) ) {
			return new \WP_REST_Response( [ 'message' => __( 'Too many requests.', 'plathix' ) ], 429 );
		}

		$taxonomy = $this->request_taxonomy( $request );
		$items    = array_values( array_filter( (array) $request->get_param( 'items' ), 'is_array' ) );

		if ( $items === [] ) {
			return new \WP_REST_Response( [ 'message' => __( 'No folders selected.', 'plathix' ) ], 422 );
		}

		$result = $this->run_optional_override(
			$runner_override,
			fn (): array => $this->run_reorder_tree( $items, $taxonomy ),
			$items,
			$taxonomy
		);

		$this->record_audit(
			'folders_reordered_bulk',
			[
				'objectType' => 'folder',
				'itemsCount' => count( (array) ( $result['reordered'] ?? [] ) ),
				'summary'    => sprintf( 'Reordered %d folders', count( (array) ( $result['reordered'] ?? [] ) ) ),
				'context'    => [
					'taxonomy'  => $taxonomy,
					'post_type'  => (string) $request->get_param( 'post_type' ),
					'reordered' => (array) ( $result['reordered'] ?? [] ),
				],
			],
			$audit_runner
		);

		return new \WP_REST_Response( $result );
	}

	/**
	 * @param array<int, array<string, mixed>> $items
	 * @return array{created: list<array{id: int, name: string, parentId: int}>, failed: list<array{name: string, parentId: int, code: string, message: string}|array{name: string, message: string}>}
	 */
	private function run_batch_create_folders(array $items, string $taxonomy): array {
		$created = [];
		$failed  = [];

		foreach ( $items as $item ) {
			$name      = sanitize_text_field( (string) ( $item['name'] ?? '' ) );
			$parent_id = absint( $item['parent_id'] ?? 0 );
			if ( $name === '' ) {
				$failed[] = RestFailureValues::empty_folder_name();
				continue;
			}

			$result = $this->tree->create( $name, $parent_id, $taxonomy );
			if ( is_wp_error( $result ) ) {
				/** @var \WP_Error $result Narrowed inside is_wp_error() guard (see [internal] #6). */
				$failed[] = RestFailureValues::batch_create( $name, $parent_id, $result );
				continue;
			}
			/** @var int $result Narrowed after is_wp_error() guard (see [internal] #6). */

			$created[] = RestFailureValues::batch_created_row( (int) $result, $name, $parent_id );
		}

		return [ 'created' => $created, 'failed' => $failed ];
	}

	/**
	 * @param array<int, int|string> $ids
	 * @return array{deleted: list<int>, deleted_names: list<string>, failed: list<array{id: int, message: string}>}
	 */
	private function run_batch_delete_folders(array $ids, string $taxonomy, string $on_children): array {
		$deleted       = [];
		$deleted_names = [];
		$failed        = [];

		foreach ( $ids as $id ) {
			$folder_id = (int) $id;
			if ( $folder_id <= 0 ) {
				$failed[] = RestFailureValues::invalid_folder( $folder_id );
				continue;
			}

			// [internal]: имя читаем ДО удаления — после delete_recursive() term уже не
			// существует, id ничего не резолвит постфактум для audit-разбора инцидента.
			$term = $this->repository->get_by_id( $folder_id, $taxonomy );
			$name = $term instanceof \WP_Term ? $term->name : '';

			if ( $this->tree->delete_recursive( $folder_id, $taxonomy, $on_children ) ) {
				$deleted[]       = $folder_id;
				$deleted_names[] = $name;
				continue;
			}

			$failed[] = RestFailureValues::delete_folder( $folder_id );
		}

		return [ 'deleted' => $deleted, 'deleted_names' => $deleted_names, 'failed' => $failed ];
	}

	/**
	 * Per-item применение изменений через общий FolderMutationService::apply_changes (то же
	 * ядро, что single-update). $item несёт id + любое подмножество name/parent_id/position/color;
	 * apply_changes читает только свои ключи (array_key_exists), лишний id игнорируется.
	 *
	 * @param array<int, array<string, mixed>> $items
	 * @return array{updated: list<int>, failed: list<array{id: int, message: string}|array{id: int, code: string, message: string}>}
	 */
	private function run_batch_update_folders(array $items, string $taxonomy): array {
		$updated = [];
		$failed  = [];

		foreach ( $items as $item ) {
			$id = absint( $item['id'] ?? 0 );
			if ( $id <= 0 ) {
				$failed[] = RestFailureValues::invalid_folder();
				continue;
			}

			$term = $this->repository->get_by_id( $id, $taxonomy );
			if ( ! $term instanceof \WP_Term ) {
				$failed[] = RestFailureValues::missing_folder( $id );
				continue;
			}

			$error = $this->mutations->apply_changes( $id, $item, $taxonomy );
			if ( is_wp_error( $error ) ) {
				/** @var \WP_Error $error Narrowed inside is_wp_error() guard (see [internal] #6). */
				$failed[] = RestFailureValues::wp_error( $id, $error );
				continue;
			}

			$updated[] = $id;
		}

		return [ 'updated' => $updated, 'failed' => $failed ];
	}

	/**
	 * @param array<int, array<string, mixed>> $items
	 * @return array{reordered: list<array{id: int, parent_id: int, position: int}>, failed: list<array{id: int, message: string}|array{id: int, code: string, message: string}>}
	 */
	private function run_reorder_tree(array $items, string $taxonomy): array {
		$reordered = [];
		$failed    = [];

		foreach ( $items as $item ) {
			$id        = absint( $item['id'] ?? 0 );
			$parent_id = absint( $item['parent_id'] ?? 0 );
			$position  = absint( $item['position'] ?? 0 );

			if ( $id <= 0 ) {
				$failed[] = RestFailureValues::invalid_folder();
				continue;
			}

			$moved = $this->tree->move( $id, $parent_id, $taxonomy );
			if ( is_wp_error( $moved ) ) {
				/** @var \WP_Error $moved Narrowed inside is_wp_error() guard (see [internal] #6). */
				$failed[] = RestFailureValues::wp_error( $id, $moved );
				continue;
			}

			$ordered = $this->tree->set_order( $id, $position, $taxonomy );
			if ( is_wp_error( $ordered ) ) {
				/** @var \WP_Error $ordered Narrowed inside is_wp_error() guard (see [internal] #6). */
				$failed[] = RestFailureValues::wp_error( $id, $ordered );
				continue;
			}

			$reordered[] = [
				'id'        => $id,
				'parent_id' => $parent_id,
				'position'  => $position,
			];
		}

		return [ 'reordered' => $reordered, 'failed' => $failed ];
	}

	/**
	 * @return array{success: bool}
	 */
	private function run_recount_folders(string $taxonomy): array {
		$this->folders->invalidate( $taxonomy );
		return [ 'success' => true ];
	}
}
