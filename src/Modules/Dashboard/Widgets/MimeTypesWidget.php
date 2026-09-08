<?php

declare(strict_types=1);

namespace Plathix\Modules\Dashboard\Widgets;

use Plathix\Core\BentoWidget;

class MimeTypesWidget
{

	private const COLORS = [
		'image/jpeg'      => '#0d9488',
		'image/png'       => '#14b8a6',
		'image/webp'      => '#2dd4bf',
		'image/gif'       => '#7c3aed',
		'image/svg+xml'   => '#a855f7',
		'video/mp4'       => '#8b5cf6',
		'video/quicktime' => '#6d28d9',
		'video/webm'      => '#4c1d95',
		'audio/mpeg'      => '#f59e0b',
		'audio/wav'       => '#d97706',
		'application/pdf' => '#ef4444',
		'other'           => '#d1d5db',
	];

	/** @param array<string, mixed> $data */
	public function render(array $data): void {
		$mimeStats = (array) ( $data['mimeStats'] ?? [] );
		$total      = array_sum( array_column( $mimeStats, 'count' ) );
		?>
		<div class="plathix-bento-card plathix-mime-widget">
			<?php BentoWidget::label( __( 'MIME Types', 'plathix' ) ); ?>

			<?php if ( empty( $mimeStats ) ) : ?>
				<p class="plathix-bento__sub plathix-mime-widget__empty"><?php esc_html_e( 'No media files yet.', 'plathix' ); ?></p>
			<?php else : ?>
				<div class="plathix-mime-body">
					<div class="plathix-mime-donut-wrap">
						<?php echo $this->donut( $mimeStats, $total ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG donut assembled from literal markup and numeric values computed in this class; colours come from a class constant ?>
						<div class="plathix-mime-total">
							<span class="plathix-mime-total-num" title="<?php echo esc_attr( number_format_i18n( $total ) ); ?>"><?php echo esc_html( $this->compactNumber( $total ) ); ?></span>
							<span class="plathix-mime-total-label"><?php esc_html_e( 'FILES', 'plathix' ); ?></span>
						</div>
					</div>
					<div class="plathix-mime-list">
						<?php foreach ( $mimeStats as $item ) :
							$color = self::COLORS[ $item['mime'] ] ?? '#9ca3af';
							$pct   = (int) ( $item['pct'] ?? 0 );
							?>
							<div class="plathix-mime-row">
								<span class="plathix-mime-dot" style="background:<?php echo esc_attr( $color ); ?>"></span>
								<span class="plathix-mime-name"><?php echo esc_html( $item['label'] ); ?></span>
								<span class="plathix-mime-pct"><?php echo esc_html( $pct . '%' ); ?></span>
							</div>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	private function compactNumber(int $n): string {
		if ( $n < 1000 ) {
			return number_format_i18n( $n );
		}
		if ( $n < 1000000 ) {
			$v = $n / 1000;

			$decimals = $v < 10 ? 1 : 0;
			return number_format_i18n( $v, $decimals ) . 'k';
		}
		$v = $n / 1000000;
		$decimals = $v < 10 ? 1 : 0;
		return number_format_i18n( $v, $decimals ) . 'M';
	}

	/** @param array<int, array<string, mixed>> $items */
	private function donut(array $items, int $total): string {
		if ( $total === 0 ) {
			return '';
		}

		$r          = 30;
		$cx         = 40;
		$cy         = 40;
		$stroke     = 10;
		$circumference = 2 * M_PI * $r;

		$svg    = '<svg viewBox="0 0 80 80" aria-hidden="true" class="plathix-mime-donut">';

		$svg   .= '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . $r . '" fill="none" stroke="#e5e7eb" stroke-width="' . $stroke . '"/>';

		$offset = 0;

		$rotate = -90;

		foreach ( $items as $item ) {
			$color = self::COLORS[ $item['mime'] ] ?? '#9ca3af';
			$frac  = $item['count'] / $total;
			$dash  = round( $frac * $circumference, 3 );
			$gap   = round( $circumference - $dash, 3 );

			$svg .= '<circle'
				. ' cx="' . $cx . '"'
				. ' cy="' . $cy . '"'
				. ' r="' . $r . '"'
				. ' fill="none"'
				. ' stroke="' . esc_attr( $color ) . '"'
				. ' stroke-width="' . $stroke . '"'
				. ' stroke-dasharray="' . $dash . ' ' . $gap . '"'
				. ' stroke-dashoffset="' . round( $circumference * ( 1 - $offset ) - $circumference, 3 ) . '"'
				. ' transform="rotate(' . $rotate . ' ' . $cx . ' ' . $cy . ')"'
				. '/>';

			$offset += $frac;
		}

		$svg .= '</svg>';
		return $svg;
	}
}
