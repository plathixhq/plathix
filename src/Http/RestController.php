<?php

declare(strict_types=1);

namespace Plathix\Http;

use Plathix\Core\FolderAssignmentService;
use Plathix\Core\FolderCountService;
use Plathix\Core\FolderMutationService;
use Plathix\Core\FolderRepository;
use Plathix\Core\FolderTreeService;
use Plathix\Infrastructure\Cache;
use Plathix\Infrastructure\RateLimiter;
use Plathix\Loader;
use Plathix\User\AccessLevel;
use Plathix\User\AccessResolver;

final class RestController implements RestRouteHandlers, RestRoutePermissions
{
	use RestControllerHelpers;

	public const API_VERSION = 'v1';

	private const NAMESPACE = Rest::NAMESPACE;

	public static function restRouteFallbackBase(): string {
		return esc_url_raw( home_url( '/index.php?rest_route=/plathix/' . self::API_VERSION . '/' ) );
	}


	private FolderCountService $folders;
	private FolderTreeService $tree;
	private FolderAssignmentService $assignment;
	private FolderRepository $repository;
	private Cache $cache;

	private ?FolderReadController $folderReadController = null;
	private ?FolderMutationController $folderMutationController = null;
	private ?FolderBatchController $folderBatchController = null;
	private ?FolderTrashController $folderTrashController = null;
	private ?FolderMutationService $folderMutations = null;
	private ?PreferencesController $preferencesController = null;
	private ?\Closure $single_folder_loader = null;
	private ?\Closure $folders_batch_create_runner = null;
	private ?\Closure $folders_batch_delete_runner = null;
	private ?\Closure $folders_batch_update_runner = null;
	private ?\Closure $folders_reorder_runner = null;
	private ?\Closure $folders_recount_runner = null;
	private ?\Closure $folder_items_loader = null;
	private ?RestRouteRegistry $routeRegistry = null;
	private ?MediaController $mediaController = null;

	private static ?self $latest = null;

	public static function latest(): ?self {
		return self::$latest;
	}

	public function __construct(
		private readonly Loader $loader,
		private readonly RateLimiter $rateLimiter
	) {
		self::$latest = $this;
		$this->cache      = Cache::make();
		$this->repository = new FolderRepository();
		$this->folders    = new FolderCountService( $this->repository, $this->cache );
		$this->tree       = new FolderTreeService( $this->repository, $this->folders );
		$this->assignment = new FolderAssignmentService( $this->repository, $this->folders, $this->cache );
		$this->routeRegistry = new RestRouteRegistry();
		$this->loader->addAction( 'rest_api_init', $this, 'registerRoutes' );
	}

	public function registerRoutes(): void {
		$this->routeRegistry()->register( self::NAMESPACE, $this );
	}

	public static function check(string $operation, string $post_type = ''): bool {
		$post_type = sanitize_key( $post_type );

		if ( ! apply_filters( 'plathix/rest/post_type_allowed', true, $post_type ) ) {
			return false;
		}

		$effective_type = '' === $post_type ? 'attachment' : $post_type;
		if ( 'attachment' !== $effective_type ) {
			return false;
		}

		return Authorization::capability( $operation, $post_type );
	}

	/**
	 * @return array<string, bool>
	 */
	public static function getCapMapForJs(string $post_type): array {
		$caps = [
			'canView'   => self::check( 'view', $post_type ),
			'canAssign' => self::check( 'assign', $post_type ),
			'canManage' => self::check( 'manage', $post_type ),
		];

		/**
		 * @param array<string, bool> $caps
		 * @param string              $post_type
		 */

		return (array) apply_filters( 'plathix/sidebar/caps', $caps, $post_type );
	}

	public function canView(\WP_REST_Request $request): bool {
		return self::check( 'view', self::requestScalar( $request->get_param( 'post_type' ) ) );
	}

	public function canEdit(\WP_REST_Request $request): bool {
		return self::check( 'assign', self::requestScalar( $request->get_param( 'post_type' ) ) );
	}

	public function canManage(\WP_REST_Request $request): bool {
		return self::check( 'manage', self::requestScalar( $request->get_param( 'post_type' ) ) );
	}

	public function getFolders(\WP_REST_Request $request): \WP_REST_Response {
		return $this->folderReadController()->getFolders( $request );
	}

	public function getFolder(\WP_REST_Request $request): \WP_REST_Response {
		return $this->folderReadController()->getFolder( $request, $this->single_folder_loader );
	}

	public function batchCreateFolders(\WP_REST_Request $request): \WP_REST_Response {
		return $this->folderBatchController()->batchCreateFolders( $request, $this->folders_batch_create_runner );
	}

	public function batchDeleteFolders(\WP_REST_Request $request): \WP_REST_Response {
		return $this->folderBatchController()->batchDeleteFolders( $request, $this->folders_batch_delete_runner );
	}

	public function batchUpdateFolders(\WP_REST_Request $request): \WP_REST_Response {
		return $this->folderBatchController()->batchUpdateFolders( $request, $this->folders_batch_update_runner );
	}

	public function recountFolders(\WP_REST_Request $request): \WP_REST_Response {
		return $this->folderBatchController()->recountFolders( $request, $this->folders_recount_runner );
	}

	public function reorderTree(\WP_REST_Request $request): \WP_REST_Response {
		return $this->folderBatchController()->reorderTree( $request, $this->folders_reorder_runner );
	}

	public function createFolder(\WP_REST_Request $request): \WP_REST_Response {
		return $this->folderMutationController()->createFolder( $request );
	}

	public function updateFolder(\WP_REST_Request $request): \WP_REST_Response {
		return $this->folderMutationController()->updateFolder( $request );
	}

	public function deleteFolder(\WP_REST_Request $request): \WP_REST_Response {
		return $this->folderMutationController()->deleteFolder( $request );
	}

	public function restoreFolder(\WP_REST_Request $request): \WP_REST_Response {
		return $this->folderTrashController()->restoreFolder( $request );
	}

	public function trashedFolders(\WP_REST_Request $request): \WP_REST_Response {
		return $this->folderTrashController()->trashedFolders( $request );
	}

	public function purgeFolder(\WP_REST_Request $request): \WP_REST_Response {
		return $this->folderTrashController()->purgeFolder( $request );
	}

	public function bulkTrashMedia(\WP_REST_Request $request): \WP_REST_Response {
		return $this->mediaController()->bulkTrashMedia( $request );
	}

	public function bulkRestoreMedia(\WP_REST_Request $request): \WP_REST_Response {
		return $this->mediaController()->bulkRestoreMedia( $request );
	}

	public function setItems(\WP_REST_Request $request): \WP_REST_Response {
		return $this->mediaController()->setItems( $request );
	}

	public function moveItems(\WP_REST_Request $request): \WP_REST_Response {
		return $this->mediaController()->moveItems( $request );
	}

	public function unassignItems(\WP_REST_Request $request): \WP_REST_Response {
		return $this->mediaController()->unassignItems( $request );
	}

	public function getFolderItems(\WP_REST_Request $request): \WP_REST_Response {
		return $this->folderReadController()->getFolderItems( $request, $this->folder_items_loader );
	}


	public function updatePreferences(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
		return $this->preferencesController()->updatePreferences( $request );
	}

	private function routeRegistry(): RestRouteRegistry {
		return $this->routeRegistry ??= new RestRouteRegistry();
	}

	private function folderReadController(): FolderReadController {
		if ( $this->folderReadController === null ) {
			$repo  = isset( $this->repository ) ? $this->repository : new FolderRepository();
			$count = isset( $this->folders ) ? $this->folders : new FolderCountService( $repo, $this->cache );
			$this->folderReadController = new FolderReadController( $count );
		}

		return $this->folderReadController;
	}

	private function folderMutationController(): FolderMutationController {
		if ( $this->folderMutationController === null ) {
			$repo = isset( $this->repository ) ? $this->repository : new FolderRepository();
			$tree = $this->folderTree( $repo );
			$this->folderMutationController = new FolderMutationController( $repo, $tree, $this->rateLimiter, $this->folderMutations( $tree ) );
		}

		return $this->folderMutationController;
	}

	private function folderBatchController(): FolderBatchController {
		if ( $this->folderBatchController === null ) {
			$repo  = isset( $this->repository ) ? $this->repository : new FolderRepository();
			$count = isset( $this->folders ) ? $this->folders : new FolderCountService( $repo, $this->cache );
			$tree  = $this->folderTree( $repo );
			$this->folderBatchController = new FolderBatchController( $repo, $count, $tree, $this->rateLimiter, $this->folderMutations( $tree ) );
		}

		return $this->folderBatchController;
	}

	private function folderTrashController(): FolderTrashController {
		if ( $this->folderTrashController === null ) {
			$repo = isset( $this->repository ) ? $this->repository : new FolderRepository();
			$this->folderTrashController = new FolderTrashController( $repo, $this->folderTree( $repo ) );
		}

		return $this->folderTrashController;
	}

	private function folderTree(FolderRepository $repo): FolderTreeService {
		if ( isset( $this->tree ) ) {
			return $this->tree;
		}
		$count = isset( $this->folders ) ? $this->folders : new FolderCountService( $repo, $this->cache );
		return new FolderTreeService( $repo, $count );
	}

	private function folderMutations(FolderTreeService $tree): FolderMutationService {
		if ( $this->folderMutations === null ) {
			$repo  = isset( $this->repository ) ? $this->repository : new FolderRepository();
			$count = isset( $this->folders ) ? $this->folders : new FolderCountService( $repo, $this->cache );
			$this->folderMutations = new FolderMutationService( $tree, $count );
		}

		return $this->folderMutations;
	}

	private function preferencesController(): PreferencesController {
		return $this->preferencesController ??= new PreferencesController();
	}

	private function mediaController(): MediaController {
		if ( $this->mediaController === null ) {
			$repo       = isset( $this->repository ) ? $this->repository : new FolderRepository();
			$count      = isset( $this->folders ) ? $this->folders : new FolderCountService( $repo, $this->cache );
			$assignment = isset( $this->assignment ) ? $this->assignment : new FolderAssignmentService( $repo, $count, $this->cache );
			$this->mediaController = new MediaController( $repo, $assignment, $this->rateLimiter );
		}

		return $this->mediaController;
	}
}
