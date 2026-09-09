<?php

declare(strict_types=1);

namespace Plathix\Modules\Dashboard\Widgets;

use Plathix\Core\BentoWidget;
use Plathix\Core\PresetPageContract;

class FoldersWidget
{
	/**
	 * @param array<string, mixed> $data
	 */
	public function render(array $data): void {
		$total        = (int) $data['total_folders'];
		$orphaned     = (int) $data['orphaned_files'];
		$distribution = (array) $data['distribution'];
		$presets_url  = admin_url( 'admin.php?page=' . PresetPageContract::PAGE_SLUG );
		$media_url    = admin_url( 'upload.php' );

		$bar_colors = [ 'var(--plathix-accent)', '#0891B2', '#7C3AED', '#D97706' ];
		$max        = $distribution ? max( array_column( $distribution, 'folders' ) ) : 1;
		?>
		<div class="plathix-bento-card plathix-bento-card--folders">
			<?php BentoWidget::label( __( 'Folders', 'plathix' ) ); ?>

			<?php if ( $total === 0 ) : ?>
				<div class="plathix-bento__empty-icon" aria-hidden="true">
					<svg width="40" height="36" viewBox="0 0 16 14" fill="currentColor" class="plathix-bento__empty-icon__svg"><path d="M1.5 1A1.5 1.5 0 000 2.5v9A1.5 1.5 0 001.5 13h13a1.5 1.5 0 001.5-1.5v-7A1.5 1.5 0 0014.5 3H7.5L6 1H1.5z"/></svg>
				</div>
				<div class="plathix-bento__empty-title"><?php esc_html_e( 'No folders yet', 'plathix' ); ?></div>
				<div class="plathix-bento-empty-desc">
					<?php esc_html_e( 'Apply a preset to instantly create a folder structure, or start from scratch.', 'plathix' ); ?>
				</div>
				<div class="plathix-bento-actions">
					<a href="<?php echo esc_url( $presets_url ); ?>" class="plathix-btn plathix-btn--primary plathix-btn--sm plathix-bento-btn-full">
						<?php esc_html_e( 'Apply preset', 'plathix' ); ?>
					</a>
					<a href="<?php echo esc_url( $media_url ); ?>" class="plathix-btn plathix-btn--ghost plathix-btn--sm plathix-bento-btn-full">
						<?php esc_html_e( 'Create folder', 'plathix' ); ?>
					</a>
				</div>

			<?php else :
				$diskUsage    = (int) ( $data['diskUsage'] ?? -1 );
				$maxDepth     = (int) ( $data['depth_stats']['maxDepth'] ?? 0 );
				$favorites     = (int) ( $data['favorites_stats']['total_unique_folders'] ?? 0 );
				$disk_human    = $diskUsage >= 0 ? size_format( $diskUsage, 1 ) : '—';

				$upload_dir  = wp_upload_dir();
				$base_dir    = $upload_dir['basedir'] ?? '';
				$total_space = ( $base_dir && is_dir( $base_dir ) ) ? (int) disk_total_space( $base_dir ) : 0;
				$disk_pct    = ( $diskUsage > 0 && $total_space > 0 ) ? min( 100, (int) round( $diskUsage / $total_space * 100 ) ) : 0;

				$disk_pct_label = ( $diskUsage > 0 && $disk_pct === 0 ) ? '<1%' : $disk_pct . '%';
				$total_human = $total_space > 0 ? size_format( $total_space, 0 ) : '';

				$frac = ( $diskUsage > 0 && $total_space > 0 ) ? min( $diskUsage / $total_space, 1.0 ) : 0.0;
				$r = 32;
				$cx = 40;
				$cy = 40;
				$stroke = 10;
				$circumference = 2 * M_PI * $r;
				$dash = round( $frac * $circumference, 3 );
				$gap  = round( $circumference - $dash, 3 );
				$donut  = '<svg viewBox="0 0 80 80" aria-hidden="true" class="plathix-storage-donut">';
				$donut .= '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . $r . '" fill="none" stroke="#e5e7eb" stroke-width="' . $stroke . '"/>';
				if ( $frac > 0 ) {
					$donut .= '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . $r . '" fill="none" stroke="#0d9488" stroke-width="' . $stroke . '" stroke-dasharray="' . $dash . ' ' . $gap . '" stroke-linecap="round" transform="rotate(-90 ' . $cx . ' ' . $cy . ')"/>';
				}
				$donut .= '</svg>';
				?>
			<div class="plathix-bento-folders-head">
				<div class="plathix-bento-folders-headleft">
					<div class="plathix-bento__num"><?php echo esc_html( number_format_i18n( $total ) ); ?></div>
					<div class="plathix-bento__sub">
						<?php
						$count_types = (int) $data['enabled_count'];
						$types_word  = _n( 'content type', 'content types', $count_types, 'plathix' );
						echo esc_html(
							sprintf(
								/* translators: 1: content types count, 2: content types word (already pluralized),
								   3: unsorted count. The "%d %s" segment and " · %d unsorted" may be reordered per-language. */
								__( 'across %1$d %2$s · %3$d unsorted', 'plathix' ),
								$count_types,
								$types_word,
								$orphaned
							)
						);
						?>
					</div>
				</div>

				<div class="plathix-bento-folders-right">
					<div class="plathix-bento-folders-disk">
						<div class="plathix-storage-donut-wrap">
							<?php echo $donut; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG donut assembled from literal markup and numeric values computed in this class; no user input reaches it ?>
							<div class="plathix-storage-donut-label">
								<span class="plathix-storage-gb"><?php echo esc_html( $disk_human ); ?></span>
								<?php if ( $total_human ) : ?>
									<span class="plathix-storage-gb-of"><?php echo esc_html( __( 'of', 'plathix' ) . ' ' . $total_human ); ?></span>
								<?php endif; ?>
							</div>
						</div>
						<div class="plathix-bento-folders-disk-info">
							<div class="plathix-bento-folders-disk-label"><?php esc_html_e( 'DISK USED', 'plathix' ); ?></div>
							<div class="plathix-bento-folders-disk-pct"><?php echo esc_html( $disk_pct_label ); ?></div>
							<?php if ( $disk_human && $total_human ) : ?>
								<div class="plathix-bento-folders-disk-sub"><?php echo esc_html( $disk_human . ' / ' . $total_human ); ?></div>
							<?php endif; ?>
						</div>
					</div>
					<div class="plathix-bento-folders-meta">
						<div class="plathix-bento-folders-meta-row">
							<span class="plathix-bento-folders-meta-label"><?php esc_html_e( 'Max depth', 'plathix' ); ?></span>
							<span class="plathix-bento-folders-meta-val plathix-bento-folders-meta-val--purple"><?php echo esc_html( number_format_i18n( $maxDepth ) . ' ' . __( 'levels', 'plathix' ) ); ?></span>
						</div>
						<div class="plathix-bento-folders-meta-row">
							<span class="plathix-bento-folders-meta-label"><?php esc_html_e( 'Fav. folders', 'plathix' ); ?></span>
							<span class="plathix-bento-folders-meta-val plathix-bento-folders-meta-val--gold"><?php echo esc_html( number_format_i18n( $favorites ) ); ?> <span aria-hidden="true">★</span></span>
						</div>
					</div>
				</div>
			</div>

				<?php

				if ( $distribution ) :
					?>
				<div class="plathix-bento-dist">
					<?php foreach ( $distribution as $i => $item ) :
						$pct   = $max > 0 ? (int) round( ( $item['folders'] / $max ) * 100 ) : 0;
						$color = $bar_colors[ $i % count( $bar_colors ) ];
						?>
						<div class="plathix-bento-dist-row">
							<div class="plathix-bento-dist-dot" style="background:<?php echo esc_attr( $color ); ?>"></div>
							<span class="plathix-bento-dist-name"><?php echo esc_html( $item['label'] ); ?></span>
							<div class="plathix-bento-dist-track">
								<div class="plathix-bento-dist-fill" style="width:<?php echo esc_attr( (string) $pct ); ?>%;background:<?php echo esc_attr( $color ); ?>"></div>
							</div>
							<span class="plathix-bento-dist-count"><?php echo esc_html( (string) $item['folders'] ); ?></span>
						</div>
					<?php endforeach; ?>
				</div>
				<?php endif; ?>
			<div class="plathix-bento__footer">
				<a href="<?php echo esc_url( $media_url ); ?>" class="plathix-bento__link">
					<?php esc_html_e( 'Open Media Library →', 'plathix' ); ?>
				</a>
			</div>
			<?php endif; ?>
		</div>
		<?php
	}
}
