<?php

declare(strict_types=1);

namespace Plathix\Modules\FreeFirstRun;

use Plathix\Core\PresetPageContract;
use Plathix\Core\FolderRepository;
use Plathix\Core\Taxonomy;
use Plathix\PublicApi\PresetOnboardingApi;
use Plathix\PublicApi\PresetsApi;

final class FreeWizard
{

	public const HOOK_PRIORITY = 20;

	public static function renderHook(): void
	{
		if ( (bool) apply_filters( 'plathix/edition/pro_active', false ) ) {
			return;
		}

		if ( ! PresetOnboardingApi::shouldShowWizard() ) {
			return;
		}

		( new self() )->render();
	}

	public function render(): void
	{
		$presets = ( new PresetsApi() )->validPresets();

		$taxonomy          = Taxonomy::taxonomyForPostType( 'attachment' );
		$all_terms         = ( new FolderRepository() )->getAll( $taxonomy );
		$systemSlugs      = FolderRepository::systemSlugs();
		$user_folder_count = count(
			array_filter( $all_terms, static fn(\WP_Term $t) => ! in_array( $t->slug, $systemSlugs, true ) )
		);

		$skip_nonce    = wp_create_nonce( WizardController::SKIP_ACTION );
		$skip_url      = admin_url( 'admin-post.php?action=' . WizardController::SKIP_ACTION . '&_wpnonce=' . $skip_nonce );
		$scratch_nonce = wp_create_nonce( PresetPageContract::SCRATCH_ACTION );
		$scratch_url   = admin_url( 'admin-post.php?action=' . PresetPageContract::SCRATCH_ACTION . '&_wpnonce=' . $scratch_nonce );
		$featured      = array_slice( $presets, 0, 6 );
		?>
		<div class="plathix-wizard__overlay" id="plathix-wizard-overlay" data-wizard-skip="<?php echo esc_url( $skip_url ); ?>">
			<div class="plathix-wizard__card">
				<div class="plathix-wizard__head">
					<div>
						<div class="plathix-wizard__brand">
							<div class="plathix-wizard__brand-logo">P</div>
							<span class="plathix-wizard__brand-name">Plathix</span>
							<span class="plathix-badge plathix-badge--dark"><?php esc_html_e( 'First setup', 'plathix' ); ?></span>
						</div>
						<div class="plathix-wizard__title"><?php esc_html_e( 'Set up your media library', 'plathix' ); ?></div>
						<div class="plathix-wizard__sub"><?php esc_html_e( 'Pick a starting folder structure or start from scratch. You can always change this later.', 'plathix' ); ?></div>
					</div>
					<?php
?>
					<a href="<?php echo esc_url( $skip_url ); ?>" class="plathix-wizard__close" aria-label="<?php esc_attr_e( 'Skip setup', 'plathix' ); ?>" title="<?php esc_attr_e( 'Skip setup', 'plathix' ); ?>">&times;</a>
				</div>
				<div class="plathix-wizard__body">
					<?php if ( ! empty( $featured ) ) : ?>
					<div class="plathix-wizard__presets">
						<?php foreach ( $featured as $preset ) :
							$id         = (int) ( $preset['id'] ?? 0 );
							$title      = (string) ( $preset['title'] ?? '' );
							$desc       = (string) ( $preset['description'] ?? '' );
							$folder_cnt = (int) ( $preset['folder_count'] ?? 0 );
							$apply_url  = wp_nonce_url(
								admin_url( 'admin-post.php?action=' . PresetPageContract::APPLY_ACTION . '&preset_id=' . $id ),
								PresetPageContract::APPLY_ACTION . '_' . $id
							);
							?>
						<div class="plathix-wizard__preset-card">
							<div class="plathix-wizard__preset-card-title"><?php echo esc_html( $title ); ?></div>
							<?php if ( $desc !== '' ) : ?>
								<div class="plathix-wizard__preset-card-desc"><?php echo esc_html( $desc ); ?></div>
							<?php endif; ?>
							<?php if ( $folder_cnt > 0 ) : ?>
								<div class="plathix-wizard__preset-card-meta">
									<?php
									/* translators: Placeholder values are inserted at runtime. */

									echo esc_html( sprintf( _n( '%d folder', '%d folders', $folder_cnt, 'plathix' ), $folder_cnt ) );
									?>
								</div>
							<?php endif; ?>
							<a href="<?php echo esc_url( $apply_url ); ?>"
							   class="plathix-btn plathix-btn--primary plathix-btn--sm"
							   onclick="return confirm('<?php echo esc_js( __( 'Apply this preset? It adds a ready-made folder structure to your media library. Your existing folders and media are not deleted; folders with matching names are created with an "— imported" suffix.', 'plathix' ) ); ?>')">
								<?php esc_html_e( 'Apply', 'plathix' ); ?>
							</a>
						</div>
						<?php endforeach; ?>
					</div>
					<?php endif; ?>
					<div class="plathix-wizard__footer">
						<a href="<?php echo esc_url( $skip_url ); ?>" class="plathix-btn plathix-btn--ghost">
							<?php esc_html_e( 'Skip setup', 'plathix' ); ?>
						</a>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . PresetPageContract::PAGE_SLUG ) ); ?>" class="plathix-btn">
							<?php esc_html_e( 'Browse all presets', 'plathix' ); ?>
						</a>
						<a href="<?php echo esc_url( $scratch_url ); ?>"
						   class="plathix-btn"
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
							<?php esc_html_e( 'Start from scratch →', 'plathix' ); ?>
						</a>
					</div>
				</div>
			</div>
		</div>
		<?php
	}
}
