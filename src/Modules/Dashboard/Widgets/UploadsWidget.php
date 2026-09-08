<?php

declare(strict_types=1);

namespace Plathix\Modules\Dashboard\Widgets;

use Plathix\Core\BentoWidget;

class UploadsWidget
{
	/** @param array<string, mixed> $data */
	public function render(array $data): void {
		$activity = (array) ( $data['uploadActivity'] ?? [] );
		$last_7   = (int) ( $activity['last_7'] ?? 0 );
		$last_30  = (int) ( $activity['last_30'] ?? 0 );
		$by_day   = (array) ( $activity['by_day'] ?? [] );

		$peak_7  = $this->peak( $by_day, 7 );
		$peak_30 = $this->peak( $by_day, 30 );

		$spark_7  = $this->sparkline( $by_day, 7 );
		$spark_30 = $this->sparkline( $by_day, 30 );
		?>
		<div class="plathix-bento-card plathix-uploads-widget" data-current="30">
			<div class="plathix-uploads-header">
				<?php BentoWidget::label( __( 'Uploads', 'plathix' ) ); ?>
				<div class="plathix-uploads-tabs">
					<button class="plathix-uploads-tab" data-period="7"><?php esc_html_e( '7d', 'plathix' ); ?></button>
					<button class="plathix-uploads-tab plathix-uploads-tab--active" data-period="30"><?php esc_html_e( '30d', 'plathix' ); ?></button>
				</div>
			</div>

			<div class="plathix-uploads-count" data-count-7="<?php echo esc_attr( (string) $last_7 ); ?>" data-count-30="<?php echo esc_attr( (string) $last_30 ); ?>">
				<?php echo esc_html( number_format_i18n( $last_30 ) ); ?>
			</div>
			<div class="plathix-uploads-sub"
				data-sub-7="<?php /* translators: %d: peak uploads per day */ echo esc_attr( sprintf( __( 'files · last 7d · peak %d/day', 'plathix' ), $peak_7 ) ); ?>"
				data-sub-30="<?php /* translators: %d: peak uploads per day */ echo esc_attr( sprintf( __( 'files · last 30d · peak %d/day', 'plathix' ), $peak_30 ) ); ?>">
				<?php /* translators: %d: peak uploads per day */ echo esc_html( sprintf( __( 'files · last 30d · peak %d/day', 'plathix' ), $peak_30 ) ); ?>
			</div>

			<div class="plathix-uploads-spark">
				<div class="plathix-spark-wrap" data-spark="7" data-points="<?php echo esc_attr( wp_json_encode( $this->sparkPoints( $by_day, 7 ) ) ?: '' ); ?>" style="display:none">
					<?php echo $spark_7; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG assembled in sparkline() from literal markup; the only interpolated values are point coordinates already passed through esc_attr() ?>
				</div>
				<div class="plathix-spark-wrap" data-spark="30" data-points="<?php echo esc_attr( wp_json_encode( $this->sparkPoints( $by_day, 30 ) ) ?: '' ); ?>">
					<?php echo $spark_30; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG assembled in sparkline() from literal markup; the only interpolated values are point coordinates already passed through esc_attr() ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * @param array<int, array<string, mixed>> $by_day
	 * @return list<array{date: string, count: int}>
	 */

	private function sparkPoints(array $by_day, int $days): array {

		$grid = [];
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$grid[ gmdate( 'Y-m-d', (int) strtotime( current_time( 'mysql' ) . " -{$i} days" ) ) ] = 0;
		}
		foreach ( $by_day as $row ) {
			if ( isset( $grid[ (string) $row['date'] ] ) ) {
				$grid[ (string) $row['date'] ] = (int) $row['count'];
			}
		}
		$result = [];
		foreach ( $grid as $date => $count ) {
			$result[] = [ 'date' => $date, 'count' => $count ];
		}
		return $result;
	}

	/**
	 * @param array<int, array<string, mixed>> $by_day
	 */

	private function peak(array $by_day, int $days): int {

		$cutoff = gmdate( 'Y-m-d', (int) strtotime( current_time( 'mysql' ) . " -{$days} days" ) );
		$max    = 0;
		foreach ( $by_day as $row ) {
			if ( (string) $row['date'] >= $cutoff ) {
				$max = max( $max, (int) $row['count'] );
			}
		}
		return $max;
	}

	/**
	 * @param array<int, array<string, mixed>> $by_day
	 */

	private function sparkline(array $by_day, int $days): string {

		$cutoff = gmdate( 'Y-m-d', (int) strtotime( current_time( 'mysql' ) . " -{$days} days" ) );

		$grid = [];
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$grid[ gmdate( 'Y-m-d', (int) strtotime( current_time( 'mysql' ) . " -{$i} days" ) ) ] = 0;
		}
		foreach ( $by_day as $row ) {
			if ( isset( $grid[ (string) $row['date'] ] ) ) {
				$grid[ (string) $row['date'] ] = (int) $row['count'];
			}
		}

		$values = array_values( $grid );
		$max    = max( $values ) ?: 1;

		$w = 400;
		$h = 60;
		$n = count( $values );
		$step = $w / max( $n - 1, 1 );

		$points = [];
		foreach ( $values as $i => $v ) {
			$x        = round( $i * $step, 2 );
			$y        = round( $h - ( $v / $max ) * ( $h - 4 ) - 2, 2 );
			$points[] = "{$x},{$y}";
		}

		$area = $points;
		$last = end( $points ) ?: '0,0';
		[ $lx ] = explode( ',', $last );
		$area[] = "{$lx},{$h}";
		$area[] = "0,{$h}";
		$area_str  = implode( ' ', $area );
		$line_str  = implode( ' ', $points );

		return '<svg viewBox="0 0 400 60" preserveAspectRatio="none" aria-hidden="true">'
			. '<polygon points="' . esc_attr( $area_str ) . '" class="plathix-spark-area"/>'
			. '<polyline points="' . esc_attr( $line_str ) . '" class="plathix-spark-line" vector-effect="non-scaling-stroke"/>'
			. '</svg>';
	}
}
