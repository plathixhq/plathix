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
	// Namespace берётся из платформенного REST-фундамента (единый источник).
	private const NAMESPACE = Rest::NAMESPACE;

	/**
	 * Единственный источник rest_route-fallback base ([internal],
	 * [internal], [internal]). Нужен, когда сервер (nginx/LiteSpeed/WAF) режет
	 * write-запросы к pretty /wp-json/ (405) — транспорт при 405 повторяет write сюда:
	 * /index.php?rest_route=/plathix/v1/. Доходит до WP, минуя pretty-permalink location.
	 * Централизует формулу, ранее продублированную в 4 местах (3 Free + 1 PRO).
	 */
	public static function rest_route_fallback_base(): string {
		return esc_url_raw( home_url( '/index.php?rest_route=/plathix/' . self::API_VERSION . '/' ) );
	}

	// CTAN-101 ([internal]): карта "operation→AccessLevel" и cap-резолв
	// перенесены в {@see Authorization} — единый публичный владелец оси view/assign/manage
	// для REST, AJAX и PRO-маршрутов ([internal] сохранён внутри него).

	private FolderCountService $folders;
	private FolderTreeService $tree;
	private FolderAssignmentService $assignment;
	private FolderRepository $repository;
	private Cache $cache;
	// [internal] #94: FolderController распилён на 4 автономных суб-контроллера
	// (read/mutation/batch/trash) + общий Core\FolderMutationService. RestController делегирует
	// в них напрямую (прежний двойной фасад RestController→FolderController удалён).
	private ?FolderReadController $folder_read_controller = null;
	private ?FolderMutationController $folder_mutation_controller = null;
	private ?FolderBatchController $folder_batch_controller = null;
	private ?FolderTrashController $folder_trash_controller = null;
	private ?FolderMutationService $folder_mutations = null;
	private ?PreferencesController $preferences_controller = null;
	private ?\Closure $single_folder_loader = null;
	private ?\Closure $folders_batch_create_runner = null;
	private ?\Closure $folders_batch_delete_runner = null;
	private ?\Closure $folders_batch_update_runner = null;
	private ?\Closure $folders_reorder_runner = null;
	private ?\Closure $folders_recount_runner = null;
	private ?\Closure $folder_items_loader = null;
	private ?RestRouteRegistry $route_registry = null;
	private ?MediaController $media_controller = null;

	/** Последний сконструированный инстанс — шов для PRO-регистрации маршрутов под
	 * собственным namespace (CTAN-302): PRO переиспользует handlers/sanitize этого
	 * инстанса через RestRouteRegistry, не создавая второй (конструктор вешает
	 * rest_api_init — второй инстанс продублировал бы Free-роуты). */
	private static ?self $latest = null;

	public static function latest(): ?self {
		return self::$latest;
	}

	public function __construct(
		private readonly Loader $loader,
		private readonly RateLimiter $rate_limiter
	) {
		self::$latest = $this;
		$this->cache      = Cache::make();
		$this->repository = new FolderRepository();
		$this->folders    = new FolderCountService( $this->repository, $this->cache );
		$this->tree       = new FolderTreeService( $this->repository, $this->folders );
		$this->assignment = new FolderAssignmentService( $this->repository, $this->folders, $this->cache );
		$this->route_registry = new RestRouteRegistry();
		$this->loader->add_action( 'rest_api_init', $this, 'register_routes' );
	}

	public function register_routes(): void {
		$this->route_registry()->register( self::NAMESPACE, $this );
	}

	public static function check(string $operation, string $post_type = ''): bool {
		$post_type = sanitize_key( $post_type );
		// Post-type-гейт активного сервис-токена ([internal]). Раньше — прямой
		// статик-вызов ApiKeyAuthenticator (feature-модуль ApiKey). Платформа-труба не должна
		// знать конкретный модуль: публикуем extension point. ApiKey-модуль подписывается в
		// boot() и возвращает false, когда активный токен не разрешает $post_type. Дефолт true:
		// нет ApiKey (или нет активного токена) → ограничивать по post_type нечего, дальше
		// решают current_user_can + AccessResolver (остаются во Free). Вынос ApiKey в PRO →
		// фильтр без подписчика → true → cookie-доступ жив, Free не падает.
		if ( ! apply_filters( 'plathix/rest/post_type_allowed', true, $post_type ) ) {
			return false;
		}

		// CTAN-201: Free attachment-native — жёсткий type-gate ([internal]).
		// Список/фильтр типов удалены из Free целиком; PRO-типы идут через plathix-pro/v1
		// со своим permission (свой type-check по PRO-опции + Authorization::authorize).
		$effective_type = '' === $post_type ? 'attachment' : $post_type;
		if ( 'attachment' !== $effective_type ) {
			return false;
		}

		// CTAN-101: cap+satisfies-ось — в Authorization (фильтр post_type_allowed уже пройден
		// выше, поэтому capability(), не authorize() — двойной вызов фильтра запрещён).
		return Authorization::capability( $operation, $post_type );
	}

	/**
	 * @return array<string, bool>
	 */
	public static function get_cap_map_for_js(string $post_type): array {
		$caps = [
			'canView'   => self::check( 'view', $post_type ),
			'canAssign' => self::check( 'assign', $post_type ),
			'canManage' => self::check( 'manage', $post_type ),
		];

		/**
		 * Позволяет модулям добавлять свои cap-флаги в JS-конфиг сайдбара.
		 *
		 * @param array<string, bool> $caps
		 * @param string              $post_type
		 */
		return (array) apply_filters( 'plathix/sidebar/caps', $caps, $post_type );
	}

	public function can_view(\WP_REST_Request $request): bool {
		return self::check( 'view', self::request_scalar( $request->get_param( 'post_type' ) ) );
	}

	public function can_edit(\WP_REST_Request $request): bool {
		return self::check( 'assign', self::request_scalar( $request->get_param( 'post_type' ) ) );
	}

	public function can_manage(\WP_REST_Request $request): bool {
		return self::check( 'manage', self::request_scalar( $request->get_param( 'post_type' ) ) );
	}

	public function get_folders(\WP_REST_Request $request): \WP_REST_Response {
		return $this->folder_read_controller()->get_folders( $request );
	}

	public function get_folder(\WP_REST_Request $request): \WP_REST_Response {
		return $this->folder_read_controller()->get_folder( $request, $this->single_folder_loader );
	}

	public function batch_create_folders(\WP_REST_Request $request): \WP_REST_Response {
		return $this->folder_batch_controller()->batch_create_folders( $request, $this->folders_batch_create_runner );
	}

	public function batch_delete_folders(\WP_REST_Request $request): \WP_REST_Response {
		return $this->folder_batch_controller()->batch_delete_folders( $request, $this->folders_batch_delete_runner );
	}

	public function batch_update_folders(\WP_REST_Request $request): \WP_REST_Response {
		return $this->folder_batch_controller()->batch_update_folders( $request, $this->folders_batch_update_runner );
	}

	public function recount_folders(\WP_REST_Request $request): \WP_REST_Response {
		return $this->folder_batch_controller()->recount_folders( $request, $this->folders_recount_runner );
	}

	public function reorder_tree(\WP_REST_Request $request): \WP_REST_Response {
		return $this->folder_batch_controller()->reorder_tree( $request, $this->folders_reorder_runner );
	}

	public function create_folder(\WP_REST_Request $request): \WP_REST_Response {
		return $this->folder_mutation_controller()->create_folder( $request );
	}

	public function update_folder(\WP_REST_Request $request): \WP_REST_Response {
		return $this->folder_mutation_controller()->update_folder( $request );
	}

	public function delete_folder(\WP_REST_Request $request): \WP_REST_Response {
		return $this->folder_mutation_controller()->delete_folder( $request );
	}

	public function restore_folder(\WP_REST_Request $request): \WP_REST_Response {
		return $this->folder_trash_controller()->restore_folder( $request );
	}

	public function trashed_folders(\WP_REST_Request $request): \WP_REST_Response {
		return $this->folder_trash_controller()->trashed_folders( $request );
	}

	public function purge_folder(\WP_REST_Request $request): \WP_REST_Response {
		return $this->folder_trash_controller()->purge_folder( $request );
	}

	public function bulk_trash_media(\WP_REST_Request $request): \WP_REST_Response {
		return $this->media_controller()->bulk_trash_media( $request );
	}

	public function bulk_restore_media(\WP_REST_Request $request): \WP_REST_Response {
		return $this->media_controller()->bulk_restore_media( $request );
	}

	public function set_items(\WP_REST_Request $request): \WP_REST_Response {
		return $this->media_controller()->set_items( $request );
	}

	public function move_items(\WP_REST_Request $request): \WP_REST_Response {
		return $this->media_controller()->move_items( $request );
	}

	public function unassign_items(\WP_REST_Request $request): \WP_REST_Response {
		return $this->media_controller()->unassign_items( $request );
	}

	public function get_folder_items(\WP_REST_Request $request): \WP_REST_Response {
		return $this->folder_read_controller()->get_folder_items( $request, $this->folder_items_loader );
	}

	// Фича размеров папок уехала в PRO (модуль FolderInfo регистрирует свой REST-маршрут).

	public function update_preferences(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
		return $this->preferences_controller()->update_preferences( $request );
	}

	private function route_registry(): RestRouteRegistry {
		return $this->route_registry ??= new RestRouteRegistry();
	}

	private function folder_read_controller(): FolderReadController {
		if ( $this->folder_read_controller === null ) {
			$repo  = isset( $this->repository ) ? $this->repository : new FolderRepository();
			$count = isset( $this->folders ) ? $this->folders : new FolderCountService( $repo, $this->cache );
			$this->folder_read_controller = new FolderReadController( $count );
		}

		return $this->folder_read_controller;
	}

	private function folder_mutation_controller(): FolderMutationController {
		if ( $this->folder_mutation_controller === null ) {
			$repo = isset( $this->repository ) ? $this->repository : new FolderRepository();
			$tree = $this->folder_tree( $repo );
			$this->folder_mutation_controller = new FolderMutationController( $repo, $tree, $this->rate_limiter, $this->folder_mutations( $tree ) );
		}

		return $this->folder_mutation_controller;
	}

	private function folder_batch_controller(): FolderBatchController {
		if ( $this->folder_batch_controller === null ) {
			$repo  = isset( $this->repository ) ? $this->repository : new FolderRepository();
			$count = isset( $this->folders ) ? $this->folders : new FolderCountService( $repo, $this->cache );
			$tree  = $this->folder_tree( $repo );
			$this->folder_batch_controller = new FolderBatchController( $repo, $count, $tree, $this->rate_limiter, $this->folder_mutations( $tree ) );
		}

		return $this->folder_batch_controller;
	}

	private function folder_trash_controller(): FolderTrashController {
		if ( $this->folder_trash_controller === null ) {
			$repo = isset( $this->repository ) ? $this->repository : new FolderRepository();
			$this->folder_trash_controller = new FolderTrashController( $repo, $this->folder_tree( $repo ) );
		}

		return $this->folder_trash_controller;
	}

	private function folder_tree(FolderRepository $repo): FolderTreeService {
		if ( isset( $this->tree ) ) {
			return $this->tree;
		}
		$count = isset( $this->folders ) ? $this->folders : new FolderCountService( $repo, $this->cache );
		return new FolderTreeService( $repo, $count );
	}

	/**
	 * Общий (stateless) FolderMutationService для single-mutation и batch-контроллеров —
	 * один инстанс, состояния нет (зависит только от tree/folders).
	 */
	private function folder_mutations(FolderTreeService $tree): FolderMutationService {
		if ( $this->folder_mutations === null ) {
			$repo  = isset( $this->repository ) ? $this->repository : new FolderRepository();
			$count = isset( $this->folders ) ? $this->folders : new FolderCountService( $repo, $this->cache );
			$this->folder_mutations = new FolderMutationService( $tree, $count );
		}

		return $this->folder_mutations;
	}

	private function preferences_controller(): PreferencesController {
		return $this->preferences_controller ??= new PreferencesController();
	}

	private function media_controller(): MediaController {
		if ( $this->media_controller === null ) {
			$repo       = isset( $this->repository ) ? $this->repository : new FolderRepository();
			$count      = isset( $this->folders ) ? $this->folders : new FolderCountService( $repo, $this->cache );
			$assignment = isset( $this->assignment ) ? $this->assignment : new FolderAssignmentService( $repo, $count, $this->cache );
			$this->media_controller = new MediaController( $repo, $assignment, $this->rate_limiter );
		}

		return $this->media_controller;
	}
}
