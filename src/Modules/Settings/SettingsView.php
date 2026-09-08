<?php

declare(strict_types=1);

namespace Plathix\Modules\Settings;

use Plathix\Core\AdminLayout;
use Plathix\Core\FolderCountService;
use Plathix\Core\FolderRepository;
use Plathix\Core\HiddenFolders;
use Plathix\Core\TrashFolder;
use Plathix\Infrastructure\Cache;
use Plathix\PublicApi\DataWipeApi;
use Plathix\User\AccessResolver;

class SettingsView
{
	private CronHealthService $cron_health_service;

	public function __construct(?CronHealthService $cron_health_service = null) {
		$this->cron_health_service = $cron_health_service ?? new CronHealthService();
	}

	/**
	 * @return array<int, array{slug:string,label:string,render:callable}>
	 */

	private function tabs(): array {
		$host = [
			[ 'slug' => 'general',  'label' => __( 'General', 'plathix' ),  'render' => [ $this, 'renderTabGeneral' ] ],

		];

		/**
		 * @param array<int, array{slug:string,label:string,render:callable}> $tabs
		 */

		$tabs = apply_filters( 'plathix/admin/settings_tabs', $host );
		$tabs = is_array( $tabs ) ? array_values( $tabs ) : $host;

		return $tabs;
	}

	/**
	 * @return array<int, array{id:string,priority:int,render:callable}>
	 */

	private function generalSections(): array {
		$host = [

		];

		/**
		 * @param array<int, array{id:string,priority:int,render:callable}> $sections
		 */

		$sections = apply_filters( 'plathix/settings/generalSections', $host );
		$sections = is_array( $sections ) ? $sections : $host;

		return self::dedupSections( $sections );
	}

	/**
	 * @param array<int, mixed> $sections
	 * @return array<int, array{id:string,priority:int,render:callable}>
	 */

	public static function dedupSections(array $sections): array {
		$winners = [];
		foreach ( $sections as $section ) {
			if ( ! is_array( $section ) || ! isset( $section['id'], $section['render'] ) ) {
				continue;
			}
			$id       = (string) $section['id'];
			$priority = (int) ( $section['priority'] ?? 0 );
			if ( ! isset( $winners[ $id ] ) || $priority >= (int) $winners[ $id ]['priority'] ) {
				$winners[ $id ] = [ 'id' => $id, 'priority' => $priority, 'render' => $section['render'] ];
			}
		}

		$final = array_values( $winners );
		usort( $final, static fn (array $a, array $b): int => $a['priority'] <=> $b['priority'] );

		return $final;
	}

	/**
	 * @return array<int, string>
	 */

	public function tabSlugs(): array {
		return array_map( static fn (array $t): string => (string) $t['slug'], $this->tabs() );
	}

	private function resolveActiveTab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only UI state
		$tab    = sanitize_key( (string) ( $_GET['tab'] ?? 'general' ) );
		$slugs  = $this->tabSlugs();
		return in_array( $tab, $slugs, true ) ? $tab : ( $slugs[0] ?? 'general' );
	}

	private function settingsTabUrl(string $tab): string {
		return add_query_arg( 'tab', $tab, admin_url( 'admin.php?page=' . SettingsPage::PAGE_SLUG ) );
	}

	public function render(): void {
		AdminLayout::renderPage( SettingsPage::PAGE_SLUG, function (): void {
			if ( ! AccessResolver::currentUserIsFullAdmin() ) {
				return;
			}

			$active_tab = $this->resolveActiveTab();

			if ( $this->cron_health_service->isStalled() ) {
				echo '<div class="notice notice-error"><p>' .
					esc_html__( 'WP-Cron is unavailable. ZIP generation and import jobs will not run until WP-Cron or a real system cron is configured.', 'plathix' ) .
					'</p></div>';
			}
			?>
			<div class="plathix-page">

			<div class="plathix-page__head">
				<div>
					<h1 class="plathix-page__title"><?php esc_html_e( 'Settings', 'plathix' ); ?></h1>
					<div class="plathix-page__desc"><?php esc_html_e( 'Configure Plathix plugin behaviour, access and integrations.', 'plathix' ); ?></div>
				</div>
			</div>

			<?php $tabs = $this->tabs(); ?>
			<div class="plathix-card">
				<div class="plathix-tabs-bar" data-plathix-tabs="settings" role="tablist" aria-label="<?php esc_attr_e( 'Settings sections', 'plathix' ); ?>">
					<?php foreach ( $tabs as $tab ) :
						$slug = (string) $tab['slug']; ?>
						<a href="<?php echo esc_url( $this->settingsTabUrl( $slug ) ); ?>"
						   data-plathix-tab="<?php echo esc_attr( $slug ); ?>"
						   role="tab"
						   aria-selected="<?php echo $active_tab === $slug ? 'true' : 'false'; ?>"
						   class="plathix-tab<?php echo $active_tab === $slug ? ' is-active' : ''; ?>"
						   <?php echo $active_tab === $slug ? 'aria-current="page"' : ''; ?>>
							<?php echo esc_html( (string) $tab['label'] ); ?>
						</a>
					<?php endforeach; ?>
				</div>

				<div class="plathix-card__body plathix-settings__body">
					<?php foreach ( $tabs as $tab ) :
						$slug = (string) $tab['slug']; ?>
						<section
							data-plathix-tab-panel="<?php echo esc_attr( $slug ); ?>"
							role="tabpanel"
							aria-hidden="<?php echo $active_tab === $slug ? 'false' : 'true'; ?>"
							<?php echo $active_tab === $slug ? '' : 'hidden'; ?>
						>
							<?php

							if ( ( new DataWipeApi() )->tabSlug() === $slug ) {
								( $tab['render'] )();
							} else {
								$this->renderTabForm( $slug, $tab['render'] );
							}
							?>
						</section>
					<?php endforeach; ?>
				</div>
			</div>

			</div>
			<?php
		} );
	}

	/**
	 * @param callable $render
	 */

	private function renderTabForm(string $slug, callable $render): void {
		$form_url = esc_url( admin_url( 'admin-post.php' ) );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only UI-visibility toggle, no data modified; real save is separately nonce-protected by admin-post.php before this param exists
		$just_saved     = isset( $_GET['settings-updated'] ) && $_GET['settings-updated'] === 'true';
		$notice_style   = $just_saved ? '' : ' style="display:none;"';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- same read-only UI-visibility rationale as $just_saved above
		$partial_failed = isset( $_GET['plathix_settings_partial_fail'] );
		$error_style    = $partial_failed ? '' : ' style="display:none;"';
		?>
		<form method="post" action="<?php echo $form_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $form_url is already esc_url()'d where it is assigned; escaping twice would corrupt the &amp; entities ?>">
			<input type="hidden" name="action" value="plathix_save_<?php echo esc_attr( $slug ); ?>">
			<?php wp_nonce_field( 'plathix_save_' . $slug ); ?>
			<input type="hidden" name="_plathix_redirect_tab" value="<?php echo esc_attr( $slug ); ?>">

			<div class="plathix-settings__fields">
				<?php $render(); ?>
			</div>

			<div class="plathix-save__bar plathix-settings__save-bar">
				<button type="submit" name="submit" class="plathix-btn plathix-btn--primary"><?php esc_html_e( 'Save Settings', 'plathix' ); ?></button>
				<span id="plathix-saved-notice-<?php echo esc_attr( $slug ); ?>" class="plathix-badge plathix-badge--ok"<?php echo $notice_style; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- value is one of two literal strings assigned above ('' or a fixed style attribute), no dynamic data ?>>✓ <?php esc_html_e( 'Saved!', 'plathix' ); ?></span>
				<span id="plathix-save-failed-notice-<?php echo esc_attr( $slug ); ?>" class="plathix-badge plathix-badge--error"<?php echo $error_style; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- value is one of two literal strings assigned above ('' or a fixed style attribute), no dynamic data ?>>✗ <?php esc_html_e( 'Save failed', 'plathix' ); ?></span>
			</div>
		</form>
		<?php
	}

	// -------------------------------------------------------------------------
	// Tab: General
	// -------------------------------------------------------------------------

	private function renderTabGeneral(): void {

		foreach ( $this->generalSections() as $section ) {
			( $section['render'] )();
		}
		?>
		<div class="plathix-field__separator"></div>

		<div class="plathix-field">
			<div class="plathix-field__label"><?php esc_html_e( 'Media Grid', 'plathix' ); ?></div>
			<div class="plathix-field__desc"><?php esc_html_e( 'Enables infinite scroll in the Media Library grid and media picker modals.', 'plathix' ); ?></div>
			<?php $this->renderInfiniteScroll(); ?>
		</div>

		<div class="plathix-field__separator"></div>

		<div class="plathix-field">
			<div class="plathix-field__label"><?php esc_html_e( 'Default upload folder', 'plathix' ); ?></div>
			<div class="plathix-field__desc"><?php esc_html_e( 'New uploads go to this folder when no folder is chosen explicitly.', 'plathix' ); ?></div>
			<?php $this->renderDefaultUploadFolder(); ?>
		</div>

		<div class="plathix-field__separator"></div>

		<div class="plathix-checkbox-field__section-title"><?php esc_html_e( 'Behavior', 'plathix' ); ?></div>

		<div class="plathix-field">
			<div class="plathix-field__label"><?php esc_html_e( 'Confirm bulk actions', 'plathix' ); ?></div>
			<div class="plathix-field__desc"><?php esc_html_e( 'Ask for confirmation before moving, deleting or restructuring 10 or more items at once.', 'plathix' ); ?></div>
			<?php $this->renderBulkSafeMode(); ?>
		</div>

		<?php
	}

	// -------------------------------------------------------------------------
	// Field renderers
	// -------------------------------------------------------------------------

	private function renderDefaultUploadFolder(): void {
		$selected = (int) get_option( 'plathix_default_folder_id', 0 );

		$folders = ( new FolderCountService( new FolderRepository(), Cache::make() ) )
			->getAllCached( PLATHIX_TAXONOMY );

		$hidden     = HiddenFolders::ids( PLATHIX_TAXONOMY );
		$trash_id   = TrashFolder::id( PLATHIX_TAXONOMY );
		$repository = new FolderRepository();

		$children = [];
		foreach ( $folders as $folder ) {
			$id = (int) $folder->id;
			if ( $id <= 0 || $id === $trash_id || in_array( $id, $hidden, true ) ) {
				continue;
			}
			if ( $repository->isUncategorizedFolder( $id, PLATHIX_TAXONOMY ) ) {
				continue;
			}

			$children[ (int) $folder->parentId ][] = $folder;
		}

		$choices = [];
		$walk    = static function (int $parent_id, int $depth) use (&$walk, &$choices, $children): void {

			if ( $depth > 20 || ! isset( $children[ $parent_id ] ) ) {
				return;
			}

			foreach ( $children[ $parent_id ] as $folder ) {
				$id = (int) $folder->id;

				$choices[ $id ] = str_repeat( "\u{00A0}\u{00A0}", $depth ) . trim( $folder->name );
				$walk( $id, $depth + 1 );
			}
		};
		$walk( 0, 0 );

		$is_broken = $selected > 0 && ! isset( $choices[ $selected ] );
		?>
		<?php if ( $is_broken ) : ?>
			<?php

			?>
			<input type="hidden" name="plathix_default_folder_id" value="<?php echo esc_attr( (string) $selected ); ?>">
		<?php endif; ?>
		<?php
?>
		<?php
?>
		<select name="plathix_default_folder_id" class="plathix-select plathix-settings__folder-select">
			<?php if ( $is_broken ) : ?>
				<option value="<?php echo esc_attr( (string) $selected ); ?>" selected disabled><?php esc_html_e( '(folder unavailable)', 'plathix' ); ?></option>
			<?php endif; ?>
			<option value="0" <?php selected( 0, $selected ); ?>><?php esc_html_e( 'No folder', 'plathix' ); ?></option>
			<?php foreach ( $choices as $id => $label ) : ?>
				<option value="<?php echo esc_attr( (string) $id ); ?>" <?php selected( $id, $selected ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php if ( $is_broken ) : ?>
			<div class="plathix-field__desc plathix-settings__folder-broken-notice">
				<?php esc_html_e( 'The selected folder is no longer available — it was deleted or moved to trash. New uploads go to the media library root until you pick another folder. Restoring the folder brings this setting back.', 'plathix' ); ?>
			</div>
		<?php endif; ?>
		<?php
	}

	private function renderInfiniteScroll(): void {
		$enabled = (bool) get_option( 'plathix_infinite_scroll', false );
		?>
		<label class="plathix-checkbox-field__row plathix-checkbox-field__row--standalone">
			<input type="checkbox" name="plathix_infinite_scroll" value="1" <?php checked( $enabled ); ?>>
			<div class="plathix-checkbox-field__info">
				<span class="plathix-checkbox-field__label"><?php esc_html_e( 'Enable infinite scroll in Media Grid', 'plathix' ); ?></span>
				<span class="plathix-checkbox-field__desc"><?php esc_html_e( 'Replaces default pagination with seamless infinite loading.', 'plathix' ); ?></span>
			</div>
		</label>
		<?php
	}

	private function renderBulkSafeMode(): void {
		$enabled = (bool) get_option( 'plathix_bulk_safe_mode', true );
		?>
		<label class="plathix-checkbox-field__row plathix-checkbox-field__row--standalone">
			<input type="checkbox" name="plathix_bulk_safe_mode" value="1" <?php checked( $enabled ); ?>>
			<div class="plathix-checkbox-field__info">
				<span class="plathix-checkbox-field__label"><?php esc_html_e( 'Confirm bulk actions on 10 or more items', 'plathix' ); ?></span>
			</div>
		</label>
		<?php
	}
}
