<?php

declare(strict_types=1);

namespace Plathix\Modules\Import;

use Plathix\Infrastructure\Features;

/**
 * Карточка импорта на странице Tools.
 *
 * Подписывается на слот `plathix/tools/cards` (приоритет 10 — после ApiKey prio 5).
 * Владеет разметкой карточки: до FA-101 она жила в ToolsPage::render_migration_card().
 *
 * Рендерит карточку только при включённом флаге `import` и наличии хотя бы одного
 * доступного адаптера, сохраняя инвариант видимости 1:1 с прежним поведением.
 */
final class ImportToolsCard
{
	public function __construct(
		private readonly ImportManager $importManager,
	) {
	}

	/** Регистрирует карточку в слоте страницы Tools. */
	public function register(): void
	{
		add_action( 'plathix/tools/cards', [ $this, 'render' ], 10 );
	}

	/** Рендерит карточку импорта (только при включённом флаге и наличии адаптеров). */
	public function render(): void
	{
		if ( ! Features::is_enabled( 'import' ) ) {
			return;
		}

		$adapters = $this->importManager->available();
		if ( empty( $adapters ) ) {
			return;
		}

		$imported = $this->importManager->imported();
		$pending_checkpoints = [];
		foreach ( $adapters as $key => $available ) {
			$pending_checkpoints[ $key ] = $this->importManager->has_pending_checkpoint( $key );
		}
		$this->render_card( $adapters, $imported, $pending_checkpoints );
	}

	/**
	 * @param array<string, bool> $adapters Карта adapter_key => is_available
	 * @param array<string, bool> $imported Карта adapter_key => уже импортирован ([internal])
	 * @param array<string, bool> $pending_checkpoints Карта adapter_key => есть незавершённый checkpoint ([internal])
	 */
	private function render_card(array $adapters, array $imported, array $pending_checkpoints = []): void
	{
		$labels = [
			'filebird'       => 'FileBird',
			'wpmediafolder'  => 'WP Media Folder',
			'realmedialib'   => 'Real Media Library',
			'happyfiles'     => 'HappyFiles',
			'wickedfolders'  => 'Wicked Folders',
		];

		$icon_folder = '<svg width="32" height="32" viewBox="0 0 32 32" fill="none" aria-hidden="true"><path d="M3 8a2 2 0 0 1 2-2h7l2 2h13a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8z" fill="#FBBF24"/><path d="M3 12h26v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V12z" fill="#F59E0B"/></svg>';
		$icon_window = '<svg width="32" height="32" viewBox="0 0 32 32" fill="none" aria-hidden="true"><rect x="3" y="5" width="26" height="22" rx="2" fill="#94A3B8"/><rect x="3" y="5" width="26" height="7" rx="2" fill="#64748B"/><circle cx="8" cy="8.5" r="1.5" fill="#fff"/><circle cx="13" cy="8.5" r="1.5" fill="#fff"/></svg>';
		?>
		<div class="plathix-card">
			<div class="plathix-card__head">
				<span class="plathix-card__title"><?php esc_html_e( 'Import from Other Plugins', 'plathix' ); ?></span>
			</div>
			<div class="plathix-card__body">
				<p class="plathix-field__desc plathix-import__desc">
					<?php esc_html_e( 'Migrate your folder structure from another plugin. Plathix will attempt to recreate the hierarchy and reassign all media attachments. This is non-destructive — original plugin data is preserved.', 'plathix' ); ?>
				</p>
				<?php /* [internal]: import/index.js:25 переключает видимость через
				   classList.toggle('is-hidden', ...) — .is-hidden
				   (resources/css/admin-ui.css) заменяет прежний inline
				   style="display:none;", который JS-класс не мог бы перебить (тот же
				   баг класса, что уже нашёлся в PRO ShortcodesListPage.php). */ ?>
				<div id="plathix-import-status" class="plathix-notice plathix-import__status is-hidden"></div>
				<div class="plathix-import-grid">
					<?php foreach ( $adapters as $key => $available ) :
						$label       = $labels[ $key ] ?? $key;
						$icon        = $key === 'media-folder' ? $icon_window : $icon_folder;
						$is_imported = ! empty( $imported[ $key ] );
						$has_pending_checkpoint = ! empty( $pending_checkpoints[ $key ] );
						?>
						<div class="plathix-import-adapter">
							<div class="plathix-import-adapter__head">
								<?php echo $icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hardcoded SVG ?>
								<div>
									<div class="plathix-import-adapter__label"><?php echo esc_html( $label ); ?></div>
									<?php if ( $is_imported ) : ?>
										<span class="plathix-badge plathix-badge--ok plathix-import-adapter__badge">✓ <?php esc_html_e( 'Imported', 'plathix' ); ?></span>
									<?php elseif ( $available ) : ?>
										<span class="plathix-badge plathix-badge--ok plathix-import-adapter__badge">✓ <?php esc_html_e( 'Detected', 'plathix' ); ?></span>
									<?php else : ?>
										<span class="plathix-import-adapter__undetected"><?php esc_html_e( 'Not detected', 'plathix' ); ?></span>
									<?php endif; ?>
								</div>
							</div>
							<?php if ( $has_pending_checkpoint ) : ?>
								<div class="plathix-notice notice-warning inline plathix-import-checkpoint-banner" data-adapter="<?php echo esc_attr( $key ); ?>">
									<p class="plathix-import-checkpoint-banner__text"><?php esc_html_e( 'An unfinished import was detected. You can continue where it left off or start over.', 'plathix' ); ?></p>
									<div class="plathix-import-checkpoint-banner__actions">
										<button type="button" class="plathix-btn plathix-btn--primary plathix-import-button plathix-import__action-btn" data-adapter="<?php echo esc_attr( $key ); ?>">
											<?php esc_html_e( 'Continue', 'plathix' ); ?>
										</button>
										<button type="button" class="plathix-btn plathix-import-restart-button plathix-import__action-btn" data-adapter="<?php echo esc_attr( $key ); ?>">
											<?php esc_html_e( 'Start over', 'plathix' ); ?>
										</button>
									</div>
								</div>
							<?php else : ?>
								<button
									type="button"
									class="plathix-btn <?php echo ( $available && ! $is_imported ) ? 'plathix-btn--primary' : ''; ?> plathix-import-button plathix-import__action-btn"
									data-adapter="<?php echo esc_attr( $key ); ?>"
									<?php disabled( ! $available || $is_imported ); ?>
								>
									<?php
									/* translators: %s: source name (plugin or import adapter, e.g. FileBird). */
									echo esc_html( sprintf( __( 'Import from %s', 'plathix' ), $label ) );
									?>
								</button>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
		<?php
	}
}
