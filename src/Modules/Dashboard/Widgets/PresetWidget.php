<?php

declare(strict_types=1);

namespace Plathix\Modules\Dashboard\Widgets;

use Plathix\Core\BentoWidget;
use Plathix\Core\PresetPageContract;
use Plathix\PublicApi\ToolsApi;

class PresetWidget
{
	/**
	 * @param array<string, mixed> $data
	 */
	public function render(array $data): void {
		$preset      = $data['applied_preset'];
		$presets_url = admin_url( 'admin.php?page=' . PresetPageContract::PAGE_SLUG );
		$tools_url   = ( new ToolsApi() )->pageUrl();

		$popular = [
			[ 'name' => __( 'Blog & Content', 'plathix' ),  'folders' => 18 ],
			[ 'name' => __( 'E-Commerce', 'plathix' ),      'folders' => 24 ],
			[ 'name' => __( 'Creative Agency', 'plathix' ), 'folders' => 16 ],
		];
		?>
		<div class="plathix-bento-card plathix-bento-card--preset">
			<?php BentoWidget::label( __( 'Preset', 'plathix' ) ); ?>

			<?php if ( $preset !== null ) : ?>
				<div class="plathix-bento-preset-applied">
					<div class="plathix-bento-preset-applied__info">
						<div class="plathix-bento-preset-applied__name"><?php echo esc_html( $preset['title'] ); ?></div>
						<div class="plathix-bento-preset-applied__meta">
							<?php
							$applied_ts = ! empty( $preset['applied_at'] ) ? strtotime( $preset['applied_at'] ) : false;
							if ( $applied_ts && $applied_ts > 0 && $applied_ts < time() + 60 ) {
								echo esc_html(
									sprintf(
										/* translators: 1: folder count, 2: human time diff */
										__( '%1$d folders · applied %2$s ago', 'plathix' ),
										$preset['folder_count'],
										human_time_diff( $applied_ts, time() )
									)
								);
							} else {
								echo esc_html(
									sprintf(
										/* translators: %d: folder count */
										__( '%d folders · applied', 'plathix' ),
										$preset['folder_count']
									)
								);
							}
							?>
						</div>
					</div>
					<span class="plathix-badge plathix-badge--ok"><?php esc_html_e( 'Applied', 'plathix' ); ?></span>
				</div>
				<div class="plathix-bento-preset-note">
					<?php esc_html_e( '8 presets available — Blog, E-Commerce, Agency and more. Import a custom structure via ZIP.', 'plathix' ); ?>
				</div>
				<div class="plathix-bento-actions">
					<a href="<?php echo esc_url( add_query_arg( 'tab', 'import', $tools_url ) ); ?>"
					   class="plathix-btn plathix-btn--sm plathix-bento-btn-full">
						<svg width="13" height="13" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M8 2v9M4 8l4 4 4-4"/><path d="M2 14h12"/></svg>
						<?php esc_html_e( 'Import ZIP', 'plathix' ); ?>
					</a>
					<a href="<?php echo esc_url( $presets_url ); ?>"
					   class="plathix-btn plathix-btn--ghost plathix-btn--sm plathix-bento-btn-full">
						<?php esc_html_e( 'Browse presets →', 'plathix' ); ?>
					</a>
				</div>
				<div class="plathix-bento__footer">
					<a href="<?php echo esc_url( add_query_arg( 'tab', 'export', $tools_url ) ); ?>" class="plathix-bento__link">
						<?php esc_html_e( 'Export current structure →', 'plathix' ); ?>
					</a>
				</div>

			<?php else : ?>
				<div class="plathix-bento-preset-desc">
					<?php esc_html_e( 'Start with a ready-made structure for your type of site.', 'plathix' ); ?>
				</div>
				<div class="plathix-bento-preset-list">
					<?php foreach ( $popular as $p ) : ?>
						<a href="<?php echo esc_url( $presets_url ); ?>" class="plathix-bento-preset-row">
							<span class="plathix-bento-preset-row__name"><?php echo esc_html( $p['name'] ); ?></span>
							<span class="plathix-bento-preset-row__count">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %d: folder count */
										__( '%d folders', 'plathix' ),
										$p['folders']
									)
								);
								?>
							</span>
						</a>
					<?php endforeach; ?>
				</div>
				<div class="plathix-bento__footer">
					<a href="<?php echo esc_url( $presets_url ); ?>" class="plathix-bento__link">
						<?php esc_html_e( 'Browse all presets →', 'plathix' ); ?>
					</a>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}
}
