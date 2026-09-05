<?php

declare(strict_types=1);

namespace Plathix\Http;

use Plathix\Core\FolderAssignmentService;
use Plathix\Core\FolderCountService;
use Plathix\Core\FolderRepository;
use Plathix\Core\FolderTreeService;
use Plathix\Core\MediaMoveOrchestrator;
use Plathix\Core\TaxonomyResolver;
use Plathix\Infrastructure\Cache;
use Plathix\Infrastructure\RateLimiter;
use Plathix\Loader;
use Plathix\User\AccessLevel;
use Plathix\User\AccessResolver;

class AjaxRouter
{
	public function __construct(
		private readonly FolderRepository $repository,
		// [internal]: get_folders() удалён (мёртвый legacy AJAX) — поле больше нигде не
		// читается. DI-параметр сохранён как есть, чтобы не менять конструктор-контракт
		// Plugin.php/тестов ради находки вне заявленного scope этого пакета.
		// @phpstan-ignore property.onlyWritten
		private readonly FolderCountService $folders,
		// [internal]: create_folder()/rename_folder() удалены (мёртвый legacy AJAX) — поле
		// больше нигде не читается. Тот же DI-contract-сохраняющий паттерн, что $assignment.
		// @phpstan-ignore property.onlyWritten
		private readonly FolderTreeService $tree,
		// [internal]: move_items() теперь идёт через MediaMoveOrchestrator::route(), не через
		// это поле напрямую — DI-параметр сохранён как есть, чтобы не ломать
		// конструктор-контракт Plugin.php/тестов ради находки вне scope trash-routing фикса.
		// @phpstan-ignore property.onlyWritten
		private readonly FolderAssignmentService $assignment,
		private readonly ?Loader $loader = null,
		private readonly ?RateLimiter $rate_limiter = null
	) {
		if ( $this->loader ) {
			$this->register_with_loader();
		}
	}

	public function register(): void {
		if ( $this->loader ) {
			return;
		}

		$this->register_with_wp();
	}

	private function rate_limiter(): RateLimiter {
		return $this->rate_limiter ?? new RateLimiter( Cache::make() );
	}

	private function register_with_loader(): void {
		foreach ( $this->actions_map() as $action => $method ) {
			$this->loader?->add_action( 'wp_ajax_' . $action, $this, $method );
		}
	}

	private function register_with_wp(): void {
		foreach ( $this->actions_map() as $action => $method ) {
			/** @var callable(): void $callback */
			$callback = [ $this, $method ];
			add_action( 'wp_ajax_' . $action, $callback );
		}
	}

	/**
	 * @return array<string, string>
	 */
	private function actions_map(): array {
		return [
			// [internal]: get_folders/create_folder/rename_folder/save_open_folder удалены как
			// мёртвый legacy AJAX (тот же класс, что delete_folder, [internal]) — фронт
			// использует только REST-эквиваленты (resources/js/sidebar/api.js). move_items
			// НЕ трогается — explicit exclusion, занят [internal] ([internal]).
			'plathix_move_items' => 'move_items',
			// plathix_delete_all_data вынесен в Modules\DataWipe ([internal] / T2): модуль вешает
			// свой wp_ajax_-хендлер сам, платформа его больше не хардкодит.
			'plathix_refresh_nonce' => 'refresh_nonce',
		];
	}

	public function move_items(): void {
		$this->guard( AccessLevel::Upload );

		if ( ! $this->rate_limiter()->attempt( 'move_items_bulk', get_current_user_id(), max: 120, window: 60 ) ) {
			wp_send_json_error( [ 'code' => 'rate_limit', 'message' => __( 'Too many requests. Please try again later.', 'plathix' ) ], 429 );
		}

		$normalized_ids = $this->request_item_ids();
		$folder_id = absint( wp_unslash( $_POST['folder_id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() above calls Nonce::verify_or_die()
		if ( $normalized_ids === [] ) {
			wp_send_json_error( [ 'message' => __( 'No items selected.', 'plathix' ) ], 422 );
		}

		$taxonomy = $this->request_taxonomy();
		if ( $folder_id > 0 && ! $this->repository->get_by_id( $folder_id, $taxonomy ) instanceof \WP_Term ) {
			wp_send_json_error( [ 'message' => __( 'Folder no longer exists.', 'plathix' ) ], 410 );
		}

		// Trash-aware маршрутизация ([internal]/#235) через общий источник истины —
		// раньше этот легаси-AJAX путь вызывал move_items_bulk напрямую, обходя её ([internal]).
		$result = MediaMoveOrchestrator::route( $normalized_ids, $folder_id, $taxonomy );

		do_action( 'plathix/audit/record',
			'items_moved_bulk',
			[
				'objectType' => 'folder',
				'objectId'   => $folder_id,
				'itemsCount' => count( $normalized_ids ),
				'summary'     => sprintf( 'Moved %d items', count( $normalized_ids ) ),
				'context'     => [
					'taxonomy'  => $taxonomy,
					'post_type' => $this->request_post_type(),
					'item_ids'  => array_slice( $normalized_ids, 0, 20 ),
					'result'    => [
						'moved'    => $result->moved,
						'skipped'  => $result->skipped,
						'failed'   => count( $result->failed ),
						'restored' => count( $result->restored ),
						'trashed'  => count( $result->trashed ),
					],
				],
			]
		);

		wp_send_json_success( $result->toArray() );
	}

	public function refresh_nonce(): void {
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( [ 'message' => __( 'Not logged in.', 'plathix' ) ], 401 );
		}

		if ( AccessResolver::for_current_user() === AccessLevel::None ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'plathix' ) ], 403 );
		}

		wp_send_json_success(
			[
				'nonce' => Nonce::create(),
				'restNonce' => wp_create_nonce( 'wp_rest' ),
			]
		);
	}

	/**
	 * Авторизация AJAX-роута — тонкая обёртка над общим Plathix\Http\AjaxGuard::require
	 * ([internal] #135). Остаётся protected: тест-шов TestableAjaxRouter::call_guard
	 * шпионит исход через subclass. Post-type для гейта+cap-резолва берётся из request_post_type()
	 * (дефолт 'attachment'), поэтому post-type-ветка guard для роутера всегда активна.
	 */
	protected function guard(AccessLevel $required, string $required_capability = ''): void {
		AjaxGuard::require( $required, $required_capability, $this->request_post_type() );
	}

	private function request_post_type(): string {
		return sanitize_key( (string) wp_unslash( $_POST['post_type'] ?? $_REQUEST['post_type'] ?? 'attachment' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- post_type read for cap resolution; all write-actions call guard() -> AjaxGuard::require() -> Nonce::verify_or_die() before this
	}

	private function request_taxonomy(): string {
		$taxonomy = TaxonomyResolver::fromPostType( $this->request_post_type() );

		return taxonomy_exists( $taxonomy ) ? $taxonomy : PLATHIX_TAXONOMY;
	}

	/**
	 * @return int[]
	 */
	private function request_item_ids(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- guard() above calls Nonce::verify_or_die(); IDs sanitized below via array_map('absint', ...)
		$ids = (array) wp_unslash( $_POST['ids'] ?? $_POST['post_ids'] ?? [] );
		$ids_json = (string) wp_unslash( $_POST['ids_json'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- guard() above; passed to json_decode(), IDs go through absint()

		if ( $ids === [] && $ids_json !== '' ) {
			$decoded = json_decode( $ids_json, true );
			$ids = is_array( $decoded ) ? $decoded : [];
		}

		return array_values( array_filter( array_map( 'absint', $ids ) ) );
	}
}
