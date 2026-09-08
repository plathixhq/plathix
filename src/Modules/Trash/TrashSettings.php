<?php

declare(strict_types=1);

namespace Plathix\Modules\Trash;

final class TrashSettings
{
	private const OPTION      = 'plathix_trash_retention_days';
	private const DEFAULT_DAYS = 30;

	public const OPTION_DELETE_FILES = 'plathix_trash_delete_files_with_folder';

	public function register(): void
	{
		add_action( 'plathix/settings/register', [ $this, 'registerOptions' ] );
		add_filter( 'plathix/admin/settings_tabs', [ $this, 'addTab' ] );
	}

	/**
	 * @param string $option_group
	 */

	public function registerOptions(string $option_group): void
	{
		do_action( 'plathix/settings/save', self::OPTION, function (mixed $raw = null): bool {

			$raw = wp_unslash( $raw );
			if ( $raw === null || $raw === '' ) {
				return true;
			}

			return \Plathix\Infrastructure\OptionWrite::ifChanged( self::OPTION, $this->sanitizeDays( $raw ) );
		} );

		do_action( 'plathix/settings/save', self::OPTION_DELETE_FILES, function (mixed $raw = null): bool {
			return \Plathix\Infrastructure\OptionWrite::ifChanged( self::OPTION_DELETE_FILES, $this->sanitizeBool( wp_unslash( $raw ?? '' ) ) );
		} );

		do_action( 'plathix/settings/register_tab', 'trash', [
			self::OPTION,
			self::OPTION_DELETE_FILES,
		] );
	}

	public function sanitizeBool(mixed $value): string
	{
		return ( $value === '1' || $value === 1 || $value === true || $value === 'on' ) ? '1' : '';
	}

	public function sanitizeDays(mixed $value): int
	{
		return max( 1, min( 180, (int) $value ) );
	}

	/**
	 * @param array<int, array{slug:string,label:string,render:callable}> $tabs
	 * @return array<int, array{slug:string,label:string,render:callable}>
	 */
	public function addTab(array $tabs): array
	{
		$tabs[] = [
			'slug'   => 'trash',
			'label'  => __( 'Trash', 'plathix' ),
			'render' => [ $this, 'renderTab' ],
		];

		return $tabs;
	}

	public function renderTab(): void
	{
		$days = max( 1, min( 180, (int) get_option( self::OPTION, self::DEFAULT_DAYS ) ) );
		?>
		<div class="plathix-field">
			<div class="plathix-field__label"><?php esc_html_e( 'Trash retention', 'plathix' ); ?></div>
			<div class="plathix-field__desc">
				<?php esc_html_e( 'How many days files stay in the Trash folder before they are permanently deleted (1–180). Overrides the WordPress default without editing wp-config.', 'plathix' ); ?>
			</div>
			<input type="number" name="<?php echo esc_attr( self::OPTION ); ?>" value="<?php echo esc_attr( (string) $days ); ?>" min="1" max="180" step="1" class="small-text">
			<span><?php esc_html_e( 'days', 'plathix' ); ?></span>
		</div>
		<?php
		$delete_files = get_option( self::OPTION_DELETE_FILES, '' ) === '1';
		?>
		<div class="plathix-field">
			<div class="plathix-field__label"><?php esc_html_e( 'Delete files with folder', 'plathix' ); ?></div>
			<div class="plathix-field__desc">
				<?php esc_html_e( 'When a folder is moved to Trash, also move its attached files to the WordPress Trash. When off, files are unassigned from the folder and appear in Uncategorized — restoring the folder does not bring them back.', 'plathix' ); ?>
			</div>
			<label>
				<input type="hidden" name="<?php echo esc_attr( self::OPTION_DELETE_FILES ); ?>" value="">
				<input type="checkbox" name="<?php echo esc_attr( self::OPTION_DELETE_FILES ); ?>" value="1" <?php checked( $delete_files ); ?>>
				<?php esc_html_e( 'Move attached files to Trash together with the folder', 'plathix' ); ?>
			</label>
		</div>
		<?php
	}
}
