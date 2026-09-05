<?php

declare(strict_types=1);

namespace Plathix\Modules\Dashboard\Widgets;

use Plathix\PublicApi\SystemInfoApi;

class StatusBarWidget
{
	/**
	 * @param array<string, mixed> $data
	 */
	public function render(array $data): void {
		$count_types   = (int) $data['enabled_count'];
		$count_folders = (int) $data['total_folders'];
		$count_files   = (int) $data['total_files'];
		$issues        = (array) $data['health_issues'];
		$sysinfo_url   = ( new SystemInfoApi() )->pageUrl();
		?>
		<div class="plathix-status-bar">
			<span class="plathix-status-pulse"></span>
			<span class="plathix-status-text">
				<strong><?php esc_html_e( 'Plathix is active', 'plathix' ); ?></strong>
				<span class="plathix-status-meta">
					&nbsp;·&nbsp;
					<?php
					$types_word   = _n( 'content type', 'content types', $count_types, 'plathix' );
					$folders_word = _n( 'folder', 'folders', $count_folders, 'plathix' );
					$files_word   = _n( 'file', 'files', $count_files, 'plathix' );
					echo esc_html(
						sprintf(
							/* translators: 1: types count, 2: types word (already pluralized), 3: folders count,
							   4: folders word (already pluralized), 5: files count, 6: files word (already pluralized).
							   The three "%d %s" pairs and the " · " separator between them may be reordered/changed per-language. */
							__( '%1$d %2$s · %3$d %4$s · %5$d %6$s', 'plathix' ),
							$count_types,
							$types_word,
							$count_folders,
							$folders_word,
							$count_files,
							$files_word
						)
					);
					?>
				</span>
			</span>
			<div class="plathix-status-badges">
				<span class="plathix-badge plathix-badge--neutral">WP <?php echo esc_html( get_bloginfo( 'version' ) ); ?></span>
				<span class="plathix-badge plathix-badge--neutral">PHP <?php echo esc_html( PHP_VERSION ); ?></span>
				<?php if ( empty( $issues ) ) : ?>
					<a href="<?php echo esc_url( $sysinfo_url ); ?>" class="plathix-badge plathix-badge--ok plathix-badge--link">
						<?php esc_html_e( 'All good', 'plathix' ); ?>
					</a>
				<?php else : ?>
					<a href="<?php echo esc_url( $sysinfo_url ); ?>" class="plathix-badge plathix-badge--warn plathix-badge--link">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %d: issue count */
								_n( '%d issue', '%d issues', count( $issues ), 'plathix' ),
								count( $issues )
							)
						);
						?>
					</a>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
