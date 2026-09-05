<?php

declare(strict_types=1);

namespace Plathix\Modules\Preset;

use Plathix\Core\AdminLayout;

final class PresetsPage
{
	public const PAGE_SLUG = 'plathix-presets';

	// APPLY/UPLOAD/SCRATCH публичны: Free-визард (Modules\FreeFirstRun\FreeWizard) строит на них URL кнопок.
	public const APPLY_ACTION   = 'plathix_preset_apply';
	// DELETE_ACTION public: генератор nonce (render_card) в PresetsPage + верификатор
	// (Modules\Preset\PresetPostActions::handle_delete) читают один источник ([internal]).
	public const DELETE_ACTION  = 'plathix_preset_delete';
	public const UPLOAD_ACTION  = 'plathix_preset_upload';
	// [internal]: AJAX action name для dry-run проверки ZIP до показа success-уведомления.
	// Nonce переиспользует UPLOAD_ACTION (тот же scope прав), это отдельно только имя wp_ajax_-хука.
	public const VALIDATE_ACTION = 'plathix_preset_validate';
	public const SCRATCH_ACTION = 'plathix_preset_scratch';
	// SKIP_ACTION / RESET_WIZARD_ACTION перенесены в WizardController ([internal]).

	// [internal]: явный маппинг notice-типа (plathix_ntype) в реально существующий CSS-модификатор
	// plathix-notice--*. CSS определяет только сокращения (ok/err/warn/info), не полные слова
	// allowed_types в get_notice() — прежний инлайн-тернарник мапил только success→ok, для error
	// подставлял 'error' буквально, а такого класса нет в admin-ui.css.
	private const NOTICE_TYPE_TO_CSS_CLASS = [
		'success' => 'ok',
		'error'   => 'err',
		'warning' => 'warn',
		'info'    => 'info',
	];

	public function __construct(
		private readonly PresetRepository $repository = new PresetRepository(),
		private readonly BuiltInPresetDiscovery $discovery = new BuiltInPresetDiscovery(),
	) {
	}

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_page' ], 11 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
		// admin_post_* обработчики пресетов + admin_notices scratch-notice регистрирует
		// Modules\Preset\PresetPostActions ([internal]) — из Preset\Module::boot(), не здесь.
		add_filter( 'plathix/admin/menu_pages', static function (array $pages): array {
			$pages[] = [
				'slug'            => self::PAGE_SLUG,
				'label'           => __( 'Presets', 'plathix' ),
				'is_plathix_page' => true,
				'section'         => 'main',
				'order'           => 30,
				'is_ui_page'      => true,
				'icon'            => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>',
			];
			return $pages;
		} );
	}

	public function enqueue_scripts(): void {
		$screen = get_current_screen();
		if ( ! $screen || $screen->base !== 'plathix_page_' . self::PAGE_SLUG ) {
			return;
		}

		$asset_file = defined( 'PLATHIX_PATH' ) ? PLATHIX_PATH . 'assets/js/admin-ui/preset.asset.php' : '';
		$asset      = ( $asset_file && file_exists( $asset_file ) )
			? include $asset_file
			: [ 'dependencies' => [], 'version' => defined( 'PLATHIX_VERSION' ) ? PLATHIX_VERSION : '1' ];

		wp_enqueue_script(
			'plathix-presets-ui',
			defined( 'PLATHIX_ASSETS_URL' ) ? PLATHIX_ASSETS_URL . 'js/admin-ui/preset.js' : '',
			(array) ( $asset['dependencies'] ?? [] ),
			$asset['version'],
			true
		);
		wp_set_script_translations( 'plathix-presets-ui', 'plathix', PLATHIX_PATH . 'languages' );

		// CSS страницы Пресетов co-located в модуле ([internal], #113): вынесен из
		// общего admin-ui.css в preset.css и грузится ТОЛЬКО здесь, на странице Пресетов.
		// dep ['plathix-admin-ui'] гарантирует порядок: .plathix-btn / .plathix-card / .plathix-modal__box /
		// переменные из admin-ui.css приезжают первыми. Зеркало dashboard.css (WizardAssets).
		if ( defined( 'PLATHIX_PATH' ) && file_exists( PLATHIX_PATH . 'assets/css/admin-ui/preset.css' ) ) {
			wp_enqueue_style(
				'plathix-preset',
				defined( 'PLATHIX_ASSETS_URL' ) ? PLATHIX_ASSETS_URL . 'css/admin-ui/preset.css' : '',
				[ 'plathix-admin-ui' ],
				$asset['version']
			);
		}
	}

	public function add_page(): void {
		add_submenu_page(
			(string) apply_filters( 'plathix/admin/root_slug', 'plathix' ),
			__( 'Presets', 'plathix' ),
			__( 'Presets', 'plathix' ),
			'manage_options',
			self::PAGE_SLUG,
			[ $this, 'render' ]
		);
	}

	// ── Main render ────────────────────────────────────────────────────────────

	public function render(): void {
		AdminLayout::render_page( self::PAGE_SLUG, function (): void {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have sufficient permissions.', 'plathix' ) );
			}

		// Sync built-in presets on page open (spec §15: "scanned when opening Presets page")
			$this->discovery->discover();

			$all_presets_full = $this->repository->list( [ 'validation_status' => 'valid' ] );
			$all_presets      = $all_presets_full;

			$tag_filter = $this->normalize_tag( (string) ( $_GET['plathix_tag'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Recommended -- read-only display filter for page render; value normalized/whitelisted in normalize_tag(); no form processing, no DB write

			if ( $tag_filter !== '' ) {
				$all_presets = array_filter(
				$all_presets,
				function (array $preset) use ($tag_filter): bool {
					$tags = array_map( [ $this, 'normalize_tag' ], (array) ( $preset['tags'] ?? [] ) );
					$tags = array_filter( $tags, static fn(string $tag): bool => $tag !== '' );
					return in_array( strtolower( $tag_filter ), $tags, true );
				}
				);
			}

			$groups = [
			PresetSourceType::BUILTIN   => [],
			PresetSourceType::CUSTOM    => [],
			PresetSourceType::COMMUNITY => [],
			PresetSourceType::EXPORTED  => [],
			];

			foreach ( $all_presets as $preset ) {
				$type = (string) ( $preset['source_type'] ?? PresetSourceType::CUSTOM );
				if ( isset( $groups[ $type ] ) ) {
					$groups[ $type ][] = $preset;
				} else {
					$groups[ PresetSourceType::CUSTOM ][] = $preset;
				}
			}

			$all_tags      = $this->collect_tags( $all_presets_full );
			$notice        = $this->get_notice();
			$page_url      = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
			$upload_nonce  = wp_create_nonce( self::UPLOAD_ACTION );
			?>
		<div class="plathix-page plathix-page--presets">

			<?php // Free-визард (picker) уехал на Dashboard-хук plathix/onboarding/render_modal
				// (Modules\FreeFirstRun\FreeWizard, [internal]) — страница Пресетов больше не перехватывается
				// overlay при первом запуске. ?>
			<?php if ( $notice ) : ?>
			<div class="plathix-notice plathix-notice--<?php echo esc_attr( $notice['css_class'] ); ?> plathix-presets__notice">
				<?php echo esc_html( $notice['message'] ); ?>
			</div>
			<?php endif; ?>

		<div class="plathix-page__head">
				<div>
					<h1 class="plathix-page__title"><?php esc_html_e( 'Presets', 'plathix' ); ?></h1>
					<div class="plathix-page__desc"><?php esc_html_e( 'Apply a folder structure preset, upload your own package, or start from scratch.', 'plathix' ); ?></div>
				</div>
				<div class="plathix-presets__actions">
					<button type="button" class="plathix-btn" data-open-upload-modal>
						<?php esc_html_e( 'Upload preset (.zip)', 'plathix' ); ?>
					</button>
					<?php $this->render_scratch_button(); ?>
				<?php
				/**
				 * Extension point ([internal]): кнопку «Reset wizard» рендерит FreeFirstRun,
				 * подписавшись в Module::boot() — PresetsPage больше не создаёт WizardController
				 * напрямую по FQCN. Деградация допустима: без FreeFirstRun (например выключен)
				 * кнопка просто не появляется, остальная страница работает.
				 */
				do_action( 'plathix/preset/reset_wizard_button' );
				?>
				</div>
			</div>

		<div class="plathix-presets-bar">
			<input type="search" id="plathix-preset-search" class="plathix-input plathix-presets-bar__search"
				   placeholder="<?php esc_attr_e( 'Search presets…', 'plathix' ); ?>">
			<select id="plathix-preset-sort" class="plathix-select plathix-presets-bar__sort">
				<option value="default"><?php esc_html_e( 'Most Popular', 'plathix' ); ?></option>
				<option value="alpha"><?php esc_html_e( 'A → Z', 'plathix' ); ?></option>
				<option value="builtin"><?php esc_html_e( 'Built-in first', 'plathix' ); ?></option>
				<option value="custom"><?php esc_html_e( 'Custom / Uploaded', 'plathix' ); ?></option>
			</select>
		</div>

		<div class="plathix-presets-tags">
			<a href="<?php echo esc_url( $page_url ); ?>"
			   class="plathix-presets-tag <?php echo $tag_filter === '' ? 'is-active' : ''; ?>">
				<?php esc_html_e( 'All', 'plathix' ); ?>
			</a>
			<?php foreach ( $all_tags as $tag_slug => $tag_label ) :
				$is_active = $tag_slug === strtolower( $tag_filter );
				$url = $is_active ? $page_url : add_query_arg( 'plathix_tag', rawurlencode( $tag_slug ), $page_url );
				?>
				<a href="<?php echo esc_url( $url ); ?>"
				   class="plathix-presets-tag <?php echo $is_active ? 'is-active' : ''; ?>">
					<?php echo esc_html( $tag_label ); ?>
				</a>
			<?php endforeach; ?>
		</div>

			<?php
			$group_labels = [
			PresetSourceType::BUILTIN   => __( 'Built-in', 'plathix' ),
			PresetSourceType::CUSTOM    => __( 'Custom', 'plathix' ),
			PresetSourceType::COMMUNITY => __( 'Community', 'plathix' ),
			PresetSourceType::EXPORTED  => __( 'Exported', 'plathix' ),
			];

			$any_preset = false;
			?>
		<div id="plathix-presets-catalog" class="plathix-presets-catalog">
			<?php
			foreach ( $groups as $type => $presets ) {
				if ( empty( $presets ) ) {
					continue;
				}
				$any_preset = true;
				?>
			<section class="plathix-presets-section" data-preset-group="<?php echo esc_attr( $type ); ?>">
				<div class="plathix-presets-section__title">
					<?php echo esc_html( $group_labels[ $type ] ); ?>
				</div>
				<div class="plathix-presets-grid">
					<?php foreach ( $presets as $preset ) : ?>
						<?php $this->render_card( $preset ); ?>
					<?php endforeach; ?>
				</div>
			</section>
				<?php
			}

			if ( ! $any_preset ) : ?>
			<div class="plathix-card">
				<div class="plathix-card__body">
					<p class="plathix-empty"><?php esc_html_e( 'No presets found.', 'plathix' ); ?></p>
				</div>
			</div>
			<?php endif; ?>
		</div>
		<div id="plathix-presets-no-results" class="plathix-presets__no-results" style="display:none;">
			<?php esc_html_e( 'No presets match your search.', 'plathix' ); ?>
		</div>

		</div>

			<?php $this->render_upload_modal( $upload_nonce ); ?>
			<?php
		} );
	}

	// ── Card ───────────────────────────────────────────────────────────────────

	/** @param array<string, mixed> $preset */
	private function render_card(array $preset): void {
		$id          = (int) ( $preset['id'] ?? 0 );
		$title       = (string) ( $preset['title'] ?? '' );
		$description = (string) ( $preset['description'] ?? '' );
		$author      = (string) ( $preset['author_name'] ?? '' );
		$folder_cnt  = (int) ( $preset['folder_count'] ?? 0 );
		$source_type = (string) ( $preset['source_type'] ?? '' );
		$tags        = (array) ( $preset['tags'] ?? [] );
		$structure   = (array) ( $preset['structure'] ?? [] );
		$is_builtin  = $source_type === PresetSourceType::BUILTIN;

		$apply_url  = wp_nonce_url(
			admin_url( 'admin-post.php?action=' . self::APPLY_ACTION . '&preset_id=' . $id ),
			self::APPLY_ACTION . '_' . $id
		);
		$delete_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=' . self::DELETE_ACTION . '&preset_id=' . $id ),
			self::DELETE_ACTION . '_' . $id
		);

		$page_url    = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		$tag_search  = $this->normalize_tag( (string) ( $_GET['plathix_tag'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read-only display filter; value normalized/whitelisted in normalize_tag()
		?>
		<div class="plathix-preset-card"
			 data-preset-id="<?php echo esc_attr( (string) $id ); ?>"
			 data-title="<?php echo esc_attr( strtolower( $title ) ); ?>"
			 data-desc="<?php echo esc_attr( strtolower( $description ) ); ?>">

			<?php if ( $is_builtin ) : ?>
				<div class="plathix-preset-card__accent-bar"></div>
			<?php endif; ?>

			<div class="plathix-preset-card__body">

				<div class="plathix-preset-card__badges">
					<?php if ( $is_builtin ) : ?>
						<span class="plathix-badge plathix-badge--ok plathix-preset-card__badge--sm"><?php esc_html_e( 'Official', 'plathix' ); ?></span>
					<?php else : ?>
						<span class="plathix-badge plathix-badge--neutral plathix-preset-card__badge--sm"><?php echo esc_html( ucfirst( $source_type ) ); ?></span>
					<?php endif; ?>
				</div>

				<div class="plathix-preset-card__title-row">
					<span class="plathix-preset-card__icon">📂</span>
					<div>
						<div class="plathix-preset-card__name"><?php echo esc_html( $title ); ?></div>
						<?php if ( $author !== '' ) : ?>
							<div class="plathix-preset-card__category"><?php echo esc_html( $author ); ?></div>
						<?php endif; ?>
					</div>
				</div>

				<?php if ( $description !== '' ) : ?>
					<p class="plathix-preset-card__desc"><?php echo esc_html( $description ); ?></p>
				<?php endif; ?>

				<?php if ( ! empty( $structure ) ) : ?>
					<div class="plathix-preset-card__tree">
						<?php
						$shown = 0;
						foreach ( $structure as $item ) :
							if ( $shown >= 6 ) {
								break;
							}
							$name      = (string) ( $item['name'] ?? '' );
							$depth     = (int) ( $item['depth'] ?? 0 );
							$prefix    = $depth > 0 ? str_repeat( '  ', $depth ) . '└─ ' : '📁 ';
							echo esc_html( $prefix . $name ) . '<br>';
							$shown++;
						endforeach;
						?>
					</div>
				<?php endif; ?>

				<div class="plathix-preset-card__sep"></div>

				<?php if ( $folder_cnt > 0 ) : ?>
					<div class="plathix-preset-card__meta">
						<strong><?php echo esc_html( (string) $folder_cnt ); ?></strong>
						<?php echo esc_html( ' ' . _n( 'folder', 'folders', $folder_cnt, 'plathix' ) . ' ' . __( '· Media Library only', 'plathix' ) ); ?>
					</div>
				<?php endif; ?>

				<?php if ( ! empty( $tags ) ) : ?>
					<div class="plathix-preset-card__tags">
						<?php foreach ( $tags as $tag ) :
							$tag_slug = $this->normalize_tag( (string) $tag );
							if ( $tag_slug === '' ) {
								continue;
							}
							$tag_label = $this->display_tag( (string) $tag );
							$tag_url  = add_query_arg( 'plathix_tag', rawurlencode( $tag_slug ), $page_url );
							$is_active = $tag_slug === $tag_search;
							?>
							<a href="<?php echo esc_url( $tag_url ); ?>"
							   class="plathix-badge plathix-preset-card__tag <?php echo $is_active ? 'plathix-badge--ok' : 'plathix-badge--neutral'; ?>">
								<?php echo esc_html( $tag_label ); ?>
							</a>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>

				<a href="<?php echo esc_url( $apply_url ); ?>"
				   class="plathix-btn plathix-btn--primary plathix-preset-card__cta"
				   onclick="return confirm('<?php echo esc_js( __( 'Apply this preset? It adds a ready-made folder structure to your media library. Your existing folders and media are not deleted; folders with matching names are created with an "— imported" suffix.', 'plathix' ) ); ?>')">
					<?php esc_html_e( 'Apply Preset ›', 'plathix' ); ?>
				</a>

				<?php if ( ! $is_builtin ) : ?>
					<a href="<?php echo esc_url( $delete_url ); ?>"
					   class="plathix-btn plathix-btn--danger plathix-btn--sm plathix-preset-card__delete"
					   onclick="return confirm('<?php echo esc_js( __( 'Delete this preset record? This cannot be undone.', 'plathix' ) ); ?>')">
						<?php esc_html_e( 'Delete', 'plathix' ); ?>
					</a>
				<?php endif; ?>

			</div>
		</div>
		<?php
	}

	// ── Upload Modal (drag-drop) ───────────────────────────────────────────────

	private function render_upload_modal(string $nonce): void {
		?>
		<div id="plathix-upload-modal" class="plathix-modal__backdrop" style="display:none;">
			<div class="plathix-modal__box">
				<div class="plathix-modal__head">
					<span class="plathix-presets-upload__title"><?php esc_html_e( 'Upload preset package (.zip)', 'plathix' ); ?></span>
					<button class="plathix-modal__close" id="plathix-upload-close" aria-label="<?php esc_attr_e( 'Close', 'plathix' ); ?>">✕</button>
				</div>
				<div id="plathix-upload-idle">
					<div id="plathix-drop-zone" class="plathix-drop-zone">
						<div class="plathix-presets-upload__icon" aria-hidden="true">📄</div>
						<div class="plathix-drop-title"><?php esc_html_e( 'Drop your ZIP preset here', 'plathix' ); ?></div>
						<div class="plathix-drop-sub"><?php esc_html_e( 'or click to browse · .zip files only', 'plathix' ); ?></div>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
							  enctype="multipart/form-data" id="plathix-upload-form">
							<input type="hidden" name="action" value="<?php echo esc_attr( self::UPLOAD_ACTION ); ?>">
							<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>">
							<input type="file" id="plathix-file-input" name="plathix_preset_zip"
								   accept=".zip" style="display:none;">
						</form>
						<div class="plathix-drop-hint">
							<?php esc_html_e( 'Preset files define folder names and hierarchy.', 'plathix' ); ?><br>
							<?php esc_html_e( 'They apply to your Media Library only.', 'plathix' ); ?>
						</div>
					</div>
				</div>
				<div id="plathix-upload-parsing" style="display:none;">
					<div class="plathix-upload-state">
						<div class="plathix-upload-state__icon" aria-hidden="true">⏳</div>
						<div class="plathix-upload-state__text"><?php esc_html_e( 'Reading preset file…', 'plathix' ); ?></div>
					</div>
				</div>
				<div id="plathix-upload-ready" style="display:none;">
					<div class="plathix-notice plathix-notice--ok">
						<?php esc_html_e( 'Don\'t forget to apply the preset.', 'plathix' ); ?>
					</div>
					<div class="plathix-upload-ready-card">
						<div class="plathix-upload-ready-card__icon" aria-hidden="true">📦</div>
						<div class="plathix-upload-ready-card__body">
							<div class="plathix-upload-ready-card__title" id="plathix-upload-ready-name"></div>
							<div class="plathix-upload-ready-card__meta" id="plathix-upload-ready-meta"></div>
						</div>
					</div>
					<div class="plathix-upload-ready-actions">
						<button type="button" class="plathix-btn plathix-btn--primary" id="plathix-upload-apply">
							<?php esc_html_e( 'Upload', 'plathix' ); ?>
						</button>
						<button type="button" class="plathix-btn plathix-btn--ghost" id="plathix-upload-different">
							<?php esc_html_e( 'Upload different', 'plathix' ); ?>
						</button>
					</div>
				</div>
				<div id="plathix-upload-error" class="plathix-presets-upload__error" style="display:none;">
					<div class="plathix-notice plathix-notice--err" id="plathix-upload-error-text"></div>
					<button class="plathix-btn plathix-presets-upload__retry" id="plathix-upload-retry"><?php esc_html_e( 'Try again', 'plathix' ); ?></button>
				</div>
			</div>
		</div>
		<?php
	}

	// ── Start from scratch button ──────────────────────────────────────────────

	private function render_scratch_button(): void {
		$nonce             = wp_create_nonce( self::SCRATCH_ACTION );
		$taxonomy          = \Plathix\Core\Taxonomy::taxonomy_for_post_type( 'attachment' );
		$all_terms         = ( new \Plathix\Core\FolderRepository() )->get_all( $taxonomy );
		$system_slugs      = \Plathix\Core\FolderRepository::system_slugs();
		$user_folder_count = count(
			array_filter( $all_terms, static fn(\WP_Term $t) => ! in_array( $t->slug, $system_slugs, true ) )
		);
		?>
		<a href="<?php echo esc_url( admin_url( 'admin-post.php?action=' . self::SCRATCH_ACTION . '&_wpnonce=' . $nonce ) ); ?>"
		   class="plathix-btn plathix-btn--danger"
		   <?php if ( $user_folder_count > 0 ) : ?>
		   onclick="return confirm('<?php echo esc_js( sprintf(
				/* translators: %d: number of Plathix folders that will be deleted */
				_n(
					'This will delete %d Plathix folder. Media files will not be deleted. Continue?',
					'This will delete %d Plathix folders. Media files will not be deleted. Continue?',
					$user_folder_count,
					'plathix'
				),
				$user_folder_count
		   ) ); ?>')">
		   <?php else : ?>
		   >
		   <?php endif; ?>
			<?php esc_html_e( 'Start from scratch', 'plathix' ); ?>
		</a>
		<?php
	}

	// Admin-post обработчики (handle_apply/delete/upload/scratch) + notice-плюмбинг
	// (redirect_with_notice/set_scratch_notice/maybe_show_scratch_notice) вынесены в
	// Modules\Preset\PresetPostActions ([internal] / [internal]):
	// обработка POST-действий над пресетами — ответственность фичи, не admin-слоя.
	// PresetsPage остаётся page-callback: рендер + tag-filter + platform + action-константы (фасад).

	// ── Helpers ────────────────────────────────────────────────────────────────

	/**
	 * @param array<int, array<string, mixed>> $presets
	 * @return array<string, string>
	 */
	private function collect_tags(array $presets): array {
		$all = [];
		foreach ( $presets as $preset ) {
			foreach ( (array) ( $preset['tags'] ?? [] ) as $tag ) {
				$tag_slug = $this->normalize_tag( (string) $tag );
				if ( $tag_slug !== '' ) {
					$all[ $tag_slug ] = $this->display_tag( (string) $tag );
				}
			}
		}
		ksort( $all );
		return $all;
	}

	private function normalize_tag(string $tag): string {
		$tag = trim( strtolower( $tag ) );
		$tag = ltrim( $tag, "# \t\n\r\0\x0B" );
		return sanitize_key( $tag );
	}

	private function display_tag(string $tag): string {
		$tag = trim( $tag );
		$tag = ltrim( $tag, "# \t\n\r\0\x0B" );
		return $tag;
	}

	// redirect_with_notice() вынесен в Modules\Preset\PresetPostActions (write-side PRG).
	// get_notice() (read-side PRG) остаётся здесь — читается в render().

	/**
	 * @return array{type: string, css_class: string, message: string}|null
	 */
	private function get_notice(): ?array {
		$message = sanitize_text_field( (string) wp_unslash( $_GET['plathix_notice'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin-notice text from post-redirect-get URL for page render; sanitized (sanitize_text_field), no form processing, no DB write
		$type    = sanitize_key( (string) ( $_GET['plathix_ntype'] ?? 'info' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice type from post-redirect-get URL, whitelisted below; no form processing, no DB write

		if ( $message === '' ) {
			return null;
		}

		$allowed_types = [ 'success', 'error', 'warning', 'info' ];
		if ( ! in_array( $type, $allowed_types, true ) ) {
			$type = 'info';
		}

		return [
			'type'      => $type,
			'css_class' => self::NOTICE_TYPE_TO_CSS_CLASS[ $type ],
			'message'   => rawurldecode( $message ),
		];
	}
}
