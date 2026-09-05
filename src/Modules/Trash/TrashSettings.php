<?php

declare(strict_types=1);

namespace Plathix\Modules\Trash;

/**
 * Настройки корзины на странице Settings — собственность модуля Trash ([internal] B,
 * прецедент Modules\Svg\SvgSettings).
 *
 * Регистрирует опцию `plathix_trash_retention_days` (сколько дней хранить файлы в корзине до
 * окончательного удаления, 1..180; движок читает её в TrashCleanupJobRunner) и добавляет таб
 * «Trash» на страницу Settings через extension points хоста (plathix/settings/register +
 * plathix/admin/settings_tabs). render даёт только контент полей — форму рендерит хост.
 */
final class TrashSettings
{
	private const OPTION      = 'plathix_trash_retention_days';
	private const DEFAULT_DAYS = 30;

	/**
	 * Опция «уводить вложенные файлы в WP-корзину при удалении папки» ([internal]).
	 * default false = файлы отвязываются от папки (появляются в Uncategorized) и НЕ
	 * возвращаются при restore папки; true = файлы едут в корзину вместе с папкой и
	 * восстанавливаются вместе с ней. Формулировка синхронизирована с UI-текстом ниже
	 * ([internal], хвост закрыт [internal] п.4).
	 */
	public const OPTION_DELETE_FILES = 'plathix_trash_delete_files_with_folder';

	/** Подписка на оба extension point страницы Settings (зовётся из Module::boot). */
	public function register(): void
	{
		add_action( 'plathix/settings/register', [ $this, 'register_options' ] );
		add_filter( 'plathix/admin/settings_tabs', [ $this, 'add_tab' ] );
	}

	/**
	 * [internal]: 2 опции переведены с общей OPTION_GROUP на изолированный per-таб
	 * admin-post ([internal]/#518) — та же миграция, что [internal] для SvgSettings.
	 *
	 * @param string $option_group Общая группа настроек страницы Settings (устарела для
	 *   этих 2 опций, оставлена в сигнатуре для симметрии с остальными подписчиками).
	 */
	public function register_options(string $option_group): void
	{
		do_action( 'plathix/settings/save', self::OPTION, function (mixed $raw = null): bool {
			// [internal]: пустое/отсутствующее значение (number-инпут без hidden-маркера,
			// физически очищенный юзером) означает «не менять», не «сбросить в минимум».
			// 0 как явный ввод остаётся валидным числом и по-прежнему зажимается в 1
			// sanitize_days() — это разные случаи: '' !== 0.
			$raw = wp_unslash( $raw );
			if ( $raw === null || $raw === '' ) {
				return true;
			}
			update_option( self::OPTION, $this->sanitize_days( $raw ) );
			return true;
		} );

		// Храним как строку '1'|'' (не boolean): WP-санитайзер булевых опций приводил '1'→false
		// и update_option не писал значение (поймано на стенде). Строковый флаг надёжен для чекбокса.
		do_action( 'plathix/settings/save', self::OPTION_DELETE_FILES, function (mixed $raw = null): bool {
			update_option( self::OPTION_DELETE_FILES, $this->sanitize_bool( wp_unslash( $raw ?? '' ) ) );
			return true;
		} );

		do_action( 'plathix/settings/register_tab', 'trash', [
			self::OPTION,
			self::OPTION_DELETE_FILES,
		] );
	}

	/** Чекбокс → '1' | '' (нормализованное булево-хранение опции строкой). */
	public function sanitize_bool(mixed $value): string
	{
		return ( $value === '1' || $value === 1 || $value === true || $value === 'on' ) ? '1' : '';
	}

	/**
	 * Зажимает срок хранения в 1..180 дней (тот же диапазон, что TrashCleanupJobRunner).
	 */
	public function sanitize_days(mixed $value): int
	{
		return max( 1, min( 180, (int) $value ) );
	}

	/**
	 * @param array<int, array{slug:string,label:string,render:callable}> $tabs
	 * @return array<int, array{slug:string,label:string,render:callable}>
	 */
	public function add_tab(array $tabs): array
	{
		$tabs[] = [
			'slug'   => 'trash',
			'label'  => __( 'Trash', 'plathix' ),
			'render' => [ $this, 'render_tab' ],
		];

		return $tabs;
	}

	/** Контент таба (без формы-обёртки — её рендерит хост render_tab_form). */
	public function render_tab(): void
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
