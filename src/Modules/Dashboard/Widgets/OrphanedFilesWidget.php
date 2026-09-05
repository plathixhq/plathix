<?php

declare(strict_types=1);

namespace Plathix\Modules\Dashboard\Widgets;

use Plathix\Core\BentoWidget;

/**
 * Виджет "Orphaned Files" — donut + счётчик + кнопка Fix.
 */
class OrphanedFilesWidget
{
	/** @param array<string, mixed> $data */
	public function render(array $data): void {
		$orphaned               = (int) ( $data['orphaned_files'] ?? 0 );
		$total_files            = (int) ( $data['total_files'] ?? 0 );
		$assigned               = max( 0, $total_files - $orphaned );
		$uncategorized_id = (int) ( $data['uncategorized_folder_id'] ?? 0 );
		$fix_url          = admin_url( 'upload.php?plathix_folder=' . $uncategorized_id );
		?>
		<div class="plathix-bento-card plathix-orphaned-widget">
			<?php BentoWidget::label( __( 'Unsorted', 'plathix' ) ); ?>

			<div class="plathix-orphaned-body">
				<div class="plathix-orphaned-count"><?php echo esc_html( number_format_i18n( $orphaned ) ); ?></div>
				<div class="plathix-orphaned-of">
					<?php
					/* translators: %s: total file count */
					echo esc_html( sprintf( __( 'of %s', 'plathix' ), number_format_i18n( $total_files ) ) );
					?>
				</div>
			</div>

			<a href="<?php echo esc_url( $fix_url ); ?>" class="plathix-orphaned-fix plathix-btn plathix-btn--ghost plathix-btn--sm">
				<?php esc_html_e( 'Fix', 'plathix' ); ?>
			</a>
		</div>
		<?php
	}
}
