<?php

declare(strict_types=1);

namespace Plathix\Http;

use Plathix\Core\FolderAssignmentService;
use Plathix\Core\FolderCountService;
use Plathix\Core\FolderRepository;
use Plathix\Core\FolderTreeService;
use Plathix\Core\MediaMoveOrchestrator;
use Plathix\Core\TaxonomyResolver;
use Plathix\Helpers\Sanitize;
use Plathix\Infrastructure\Cache;
use Plathix\Infrastructure\RateLimiter;
use Plathix\Loader;
use Plathix\User\AccessLevel;
use Plathix\User\AccessResolver;

class AjaxRouter
{
	public function __construct(
		private readonly FolderRepository $repository,

		// @phpstan-ignore property.onlyWritten
		private readonly FolderCountService $folders,

		// @phpstan-ignore property.onlyWritten
		private readonly FolderTreeService $tree,

		// @phpstan-ignore property.onlyWritten
		private readonly FolderAssignmentService $assignment,
		private readonly ?Loader $loader = null,
		private readonly ?RateLimiter $rateLimiter = null
	) {
		if ( $this->loader ) {
			$this->registerWithLoader();
		}
	}

	public function register(): void {
		if ( $this->loader ) {
			return;
		}

		$this->registerWithWp();
	}

	private function rateLimiter(): RateLimiter {
		return $this->rateLimiter ?? new RateLimiter( Cache::make() );
	}

	private function registerWithLoader(): void {
		foreach ( $this->actionsMap() as $action => $method ) {
			$this->loader?->addAction( 'wp_ajax_' . $action, $this, $method );
		}
	}

	private function registerWithWp(): void {
		foreach ( $this->actionsMap() as $action => $method ) {
			/** @var callable(): void $callback */
			$callback = [ $this, $method ];
			add_action( 'wp_ajax_' . $action, $callback );
		}
	}

	/**
	 * @return array<string, string>
	 */
	private function actionsMap(): array {
		return [

			'plathix_move_items' => 'moveItems',

			'plathix_refresh_nonce' => 'refreshNonce',
		];
	}

	public function moveItems(): void {
		$this->guard( AccessLevel::Upload );

		if ( ! $this->rateLimiter()->attempt( 'moveItemsBulk', get_current_user_id(), max: 120, window: 60 ) ) {
			wp_send_json_error( [ 'code' => 'rate_limit', 'message' => __( 'Too many requests. Please try again later.', 'plathix' ) ], 429 );
		}

		$normalized_ids = $this->requestItemIds();
		$folder_id = absint( wp_unslash( $_POST['folder_id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() above calls Nonce::verifyOrDie()
		if ( $normalized_ids === [] ) {
			wp_send_json_error( [ 'message' => __( 'No items selected.', 'plathix' ) ], 422 );
		}

		$taxonomy = $this->requestTaxonomy();
		if ( $folder_id > 0 && ! $this->repository->getById( $folder_id, $taxonomy ) instanceof \WP_Term ) {
			wp_send_json_error( [ 'message' => __( 'Folder no longer exists.', 'plathix' ) ], 410 );
		}

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
					'post_type' => $this->requestPostType(),
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

	public function refreshNonce(): void {
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( [ 'message' => __( 'Not logged in.', 'plathix' ) ], 401 );
		}

		if ( AccessResolver::forCurrentUser() === AccessLevel::None ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'plathix' ) ], 403 );
		}

		wp_send_json_success(
			[
				'nonce' => Nonce::create(),
				'restNonce' => wp_create_nonce( 'wp_rest' ),
			]
		);
	}

	protected function guard(AccessLevel $required, string $required_capability = ''): void {
		AjaxGuard::require( $required, $required_capability, $this->requestPostType() );
	}

	private function requestPostType(): string {
		return sanitize_key( (string) wp_unslash( $_POST['post_type'] ?? $_REQUEST['post_type'] ?? 'attachment' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- post_type read for cap resolution; all write-actions call guard() -> AjaxGuard::require() -> Nonce::verifyOrDie() before this
	}

	private function requestTaxonomy(): string {
		return TaxonomyResolver::fromPostTypeOrFallback( $this->requestPostType() );
	}

	/**
	 * @return int[]
	 */
	private function requestItemIds(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- guard() above calls Nonce::verifyOrDie(); IDs sanitized below via Sanitize::idsFromCsvOrArray()
		$ids = wp_unslash( $_POST['ids'] ?? $_POST['post_ids'] ?? [] );
		$ids_json = (string) wp_unslash( $_POST['ids_json'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- guard() above; passed to json_decode(), IDs go through Sanitize::ids()

		if ( ( $ids === [] || $ids === '' ) && $ids_json !== '' ) {
			$decoded = json_decode( $ids_json, true );
			$ids = is_array( $decoded ) ? $decoded : [];
		}

		return Sanitize::idsFromCsvOrArray( $ids );
	}
}
