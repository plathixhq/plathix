<?php

declare(strict_types=1);

namespace Plathix\Modules\Svg;

/**
 * Настройки SVG-секции страницы Settings — собственность модуля Svg
 * ([internal], реализует инвариант автономности модулей).
 *
 * Раньше эти 3 опции + sanitize + рендер таба жили в Plathix\Admin\SettingsPage/SettingsView.
 * Теперь модуль сам:
 *  - по do_action('plathix/settings/register', $group) регистрирует свои register_setting
 *    в ОБЩУЮ OPTION_GROUP страницы Settings (admin_init);
 *  - по apply_filters('plathix/admin/settings_tabs', $tabs) добавляет дескриптор SVG-таба
 *    [slug, label, render]; render даёт ТОЛЬКО контент полей, обёртку формы рендерит хост.
 *
 * register_setting вызывается БЕЗУСЛОВНО (не под feature-флагом svg): «настройка SVG»
 * доступна всегда, чтобы её можно было включить; «работа SVG» (upload-фильтры SvgSupport)
 * уже гейтится флагом внутри самого SvgSupport.
 */
final class SvgSettings
{
	/** SVG разрешён и очищается Plathix на загрузке (дефолт — security-фича активна). */
	public const POLICY_SANITIZE = 'sanitize';
	/** SVG запрещён на сайте: Plathix вычищает svg из upload_mimes, перебивая сторонние плагины. */
	public const POLICY_BLOCK = 'block';
	/** Plathix не управляет svg-mime: не добавляет и не убирает (svg отдан другим плагинам). */
	public const POLICY_IGNORE = 'ignore';

	private const OPTION_POLICY = 'plathix_svg_policy';

	/**
	 * Текущая SVG-политика — единый нормализованный источник ([internal]).
	 * Читается boot-гейтом и потребителями (SystemInfo/Dashboard); дефолт sanitize при отсутствии
	 * или невалидном значении опции.
	 */
	public static function current_policy(): string
	{
		return self::sanitize_policy( get_option( self::OPTION_POLICY, self::POLICY_SANITIZE ) );
	}

	/**
	 * Санитизация значения политики: только allowlist из трёх состояний; любое иное (мусор,
	 * отсутствие, старое boolean) сводится к самому безопасному дефолту sanitize (fail-safe).
	 */
	public static function sanitize_policy(mixed $value): string
	{
		$value = is_string( $value ) ? $value : '';
		return in_array( $value, [ self::POLICY_SANITIZE, self::POLICY_BLOCK, self::POLICY_IGNORE ], true )
			? $value
			: self::POLICY_SANITIZE;
	}

	/** Подписка на оба extension point страницы Settings (зовётся из Module::boot, plugins_loaded). */
	public function register(): void
	{
		add_action( 'plathix/settings/register', [ $this, 'register_options' ] );
		add_filter( 'plathix/admin/settings_tabs', [ $this, 'add_tab' ] );
	}

	/**
	 * [internal]: 3 SVG-опции переведены с общей OPTION_GROUP (options.php-транспорт)
	 * на изолированный per-таб admin-post (plathix/settings/save + plathix/settings/register_tab,
	 * [internal]/#518) — устраняет саму архитектурную возможность бага "сохранение другого
	 * таба затирает эту опцию", не только заплатку hidden-маркером ([internal]). Метод
	 * остаётся подписан на тот же do_action('plathix/settings/register', ...) — имя хука не
	 * меняется, меняется только его содержимое (per-опция save-регистрация вместо register_setting()).
	 *
	 * @param string $option_group Общая группа настроек страницы Settings (устарела для этих
	 *   3 опций, оставлена в сигнатуре для симметрии с остальными подписчиками того же хука).
	 */
	public function register_options(string $option_group): void
	{
		do_action( 'plathix/settings/save', 'plathix_svg_policy', function (mixed $raw = null): bool {
			$old_value = self::current_policy();
			$new_value = self::sanitize_policy( wp_unslash( $raw ?? '' ) );
			update_option( 'plathix_svg_policy', $new_value );
			if ( $old_value !== $new_value ) {
				do_action( 'plathix/audit/record', 'svg_policy_updated', [
					'objectType' => 'option',
					'summary'    => 'SVG upload policy changed',
					'context'    => [
						'old_value' => $old_value,
						'new_value' => $new_value,
					],
				] );
			}
			return true;
		} );

		do_action( 'plathix/settings/save', 'plathix_svg_safe_mode', function (mixed $raw = null): bool {
			// Дефолт совпадает с render_safe_mode() ([internal]): is_multisite(), не статичный false.
			$old_value = (bool) get_option( 'plathix_svg_safe_mode', is_multisite() );
			$new_value = (bool) wp_unslash( $raw ?? false );
			update_option( 'plathix_svg_safe_mode', $new_value );
			if ( $old_value !== $new_value ) {
				do_action( 'plathix/audit/record', 'svg_safe_mode_updated', [
					'objectType' => 'option',
					'summary'    => 'SVG safe mode changed',
					'context'    => [
						'old_value' => $old_value,
						'new_value' => $new_value,
					],
				] );
			}
			return true;
		} );

		do_action( 'plathix/settings/save', 'plathix_svg_support', function (mixed $posted = null): bool {
			$old_value = (array) get_option( 'plathix_svg_support', [ 'administrator', 'editor' ] );
			$raw       = wp_unslash( $posted ?? [] );
			$new_value = $this->sanitize_support_roles( is_array( $raw ) ? $raw : [] );
			update_option( 'plathix_svg_support', $new_value );
			// Сравнение без учёта порядка: sanitize_support_roles() строит результат через
			// array_intersect по порядку wp_roles(), не по порядку пользовательского ввода.
			if ( array_diff( $old_value, $new_value ) !== [] || array_diff( $new_value, $old_value ) !== [] ) {
				do_action( 'plathix/audit/record', 'svg_support_updated', [
					'objectType' => 'option',
					'summary'    => 'SVG allowed upload roles changed',
					'context'    => [
						'old_value' => $old_value,
						'new_value' => $new_value,
					],
				] );
			}
			return true;
		} );

		do_action( 'plathix/settings/register_tab', 'svg', [
			'plathix_svg_policy',
			'plathix_svg_safe_mode',
			'plathix_svg_support',
		] );
	}

	/**
	 * Добавляет дескриптор SVG-таба в реестр табов страницы Settings.
	 *
	 * @param array<int, array{slug:string,label:string,render:callable}> $tabs
	 * @return array<int, array{slug:string,label:string,render:callable}>
	 */
	public function add_tab(array $tabs): array
	{
		$tabs[] = [
			'slug'   => 'svg',
			'label'  => __( 'SVG', 'plathix' ),
			'render' => [ $this, 'render_tab' ],
		];

		return $tabs;
	}

	/**
	 * Санитизация ролей, которым разрешена загрузка SVG.
	 * Перенесено из Plathix\Admin\SettingsSanitizer::sanitize_svg_support_roles (изолировано).
	 *
	 * @return array<int, string>
	 */
	public function sanitize_support_roles(mixed $value): array
	{
		/** @var object{roles:array<string,mixed>} $wp_roles_obj -- wp_roles() returns WP_Roles; phpstan infers generic object */
		$wp_roles_obj = wp_roles();
		$roles    = array_map( 'strval', array_keys( $wp_roles_obj->roles ) );
		$selected = array_values( array_intersect( $roles, array_map( 'sanitize_key', (array) $value ) ) );

		// Пустой список — валидное состояние «SVG никому» ([internal], Вариант 1): пользователь снял
		// все роли, его выбор сохраняем как есть, НЕ подставляем дефолт. Пустая строка из hidden-маркера
		// (render_roles) уже отфильтрована array_intersect с валидными ролями. Первичный дефолт при
		// полном отсутствии опции обеспечивает register_setting 'default', не этот метод.
		return $selected;
	}

	/**
	 * Контент SVG-таба (без формы-обёртки — её рендерит хост render_tab_form).
	 * Структура policy-select + #plathix-svg-dependent{notice + roles + safe_mode}:
	 * id plathix-svg-policy / plathix-svg-dependent критичны для JS-toggle в settings.js
	 * (зависимая секция показывается только при политике sanitize).
	 */
	public function render_tab(): void
	{
		?>
		<div class="plathix-field">
			<div class="plathix-field__label"><?php esc_html_e( 'SVG Uploads', 'plathix' ); ?></div>
			<div class="plathix-field__desc"><?php esc_html_e( 'Choose how Plathix handles .svg uploads across the whole site.', 'plathix' ); ?></div>
			<?php $this->render_policy(); ?>
		</div>

		<?php
		// Согласовано с JS initSvgConditional() (settings.js): блок релевантен только при
		// policy=sanitize. Рендерим верное состояние сразу на сервере, чтобы избежать
		// flash-of-unstyled-content до срабатывания клиентского toggle ([internal]).
		$dependent_style = self::current_policy() === self::POLICY_SANITIZE ? '' : ' style="display:none;"';
		?>
		<div id="plathix-svg-dependent"<?php echo $dependent_style; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- value is one of two literal strings assigned above ('' or a fixed style attribute), no dynamic data ?>>

			<?php if ( is_multisite() ) : ?>
				<div class="plathix-notice plathix-notice--warn plathix-svg__multisite-notice">
					<strong><?php esc_html_e( 'Multisite notice:', 'plathix' ); ?></strong>
					<?php esc_html_e( 'SVG upload is enabled network-wide. Each sub-site\'s allowed role list applies independently. Consider restricting to Administrator only on untrusted sites.', 'plathix' ); ?>
				</div>
			<?php endif; ?>

			<div class="plathix-field__separator"></div>

			<div class="plathix-field">
				<div class="plathix-field__label"><?php esc_html_e( 'SVG Allowed Roles', 'plathix' ); ?></div>
				<div class="plathix-field__desc"><?php esc_html_e( 'Select which roles are permitted to upload SVG files.', 'plathix' ); ?></div>
				<div class="plathix-checkbox-field__group">
					<?php $this->render_roles(); ?>
				</div>
			</div>

			<div class="plathix-field__separator"></div>

			<div class="plathix-field">
				<div class="plathix-field__label"><?php esc_html_e( 'SVG Safe Mode', 'plathix' ); ?></div>
				<div class="plathix-field__desc"><?php esc_html_e( 'Strips more aggressively — disallows &lt;use&gt; elements and external references. Recommended for sites with untrusted editors.', 'plathix' ); ?></div>
				<?php $this->render_safe_mode(); ?>
			</div>

		</div>
		<?php
	}

	/**
	 * Select трёхзначной SVG-политики ([internal]). id plathix-svg-policy — JS в settings.js
	 * показывает зависимую секцию (роли/safe mode) только при значении sanitize.
	 */
	private function render_policy(): void
	{
		$policy = self::current_policy();
		?>
		<div class="plathix-svg__policy-row">
			<select id="plathix-svg-policy" name="<?php echo esc_attr( self::OPTION_POLICY ); ?>" class="plathix-select plathix-svg__policy-select">
				<option value="<?php echo esc_attr( self::POLICY_SANITIZE ); ?>" <?php selected( $policy, self::POLICY_SANITIZE ); ?>><?php esc_html_e( 'Allow SVG uploads with sanitisation (recommended)', 'plathix' ); ?></option>
				<option value="<?php echo esc_attr( self::POLICY_BLOCK ); ?>" <?php selected( $policy, self::POLICY_BLOCK ); ?>><?php esc_html_e( 'Block SVG uploads site-wide (including SVG allowed by other plugins)', 'plathix' ); ?></option>
				<option value="<?php echo esc_attr( self::POLICY_IGNORE ); ?>" <?php selected( $policy, self::POLICY_IGNORE ); ?>><?php esc_html_e( 'Do not manage SVG (leave it to other plugins; Plathix will not sanitise)', 'plathix' ); ?></option>
			</select>
		</div>
		<?php
	}

	/** Чекбоксы ролей, которым разрешена загрузка SVG. */
	private function render_roles(): void
	{
		$selected = (array) get_option( 'plathix_svg_support', [ 'administrator', 'editor' ] );
		/** @var object{roles:array<string,mixed>} $wp_roles_obj -- wp_roles() returns WP_Roles; phpstan infers generic object */
		$wp_roles_obj = wp_roles();
		// Hidden-маркер: гарантирует, что ключ plathix_svg_support[] всегда присутствует в $_POST,
		// даже когда сняты ВСЕ чекбоксы ([internal]). Без него нативный WP Settings API (options.php)
		// не вызвал бы sanitize_callback для отсутствующего ключа → пустой список «никому» не
		// сохранялся бы. Пустая строка отфильтровывается в sanitize_support_roles (не валидная роль).
		?>
		<input type="hidden" name="plathix_svg_support[]" value="">
		<?php
		foreach ( $wp_roles_obj->roles as $slug => $role_data ) {
			?>
			<label class="plathix-checkbox-field__row plathix-checkbox-field__row--clickable">
				<input type="checkbox" name="plathix_svg_support[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $selected, true ) ); ?>>
				<span class="plathix-checkbox-field__label"><?php echo esc_html( translate_user_role( $role_data['name'] ) ); ?></span>
			</label>
			<?php
		}
	}

	/** Чекбокс строгого SVG safe mode. */
	private function render_safe_mode(): void
	{
		$enabled = (bool) get_option( 'plathix_svg_safe_mode', is_multisite() );
		// Hidden-маркер: гарантирует, что ключ plathix_svg_safe_mode всегда присутствует в
		// $_POST, даже когда чекбокс снят ([internal], тот же приём, что render_roles()
		// использует для plathix_svg_support[] — [internal]). Без него сохранение ЛЮБОГО
		// другого таба Settings (общая options.php whitelist-группа) затирало бы это поле в
		// false, потому что WP core не смог бы отличить "чекбокс снят" от "опции нет в POST".
		?>
		<input type="hidden" name="plathix_svg_safe_mode" value="">
		<label class="plathix-checkbox-field__row plathix-checkbox-field__row--standalone">
			<input type="checkbox" name="plathix_svg_safe_mode" value="1" <?php checked( $enabled ); ?>>
			<div class="plathix-checkbox-field__info">
				<span class="plathix-checkbox-field__label"><?php esc_html_e( 'Enable strict safe mode', 'plathix' ); ?></span>
				<span class="plathix-checkbox-field__desc"><?php esc_html_e( 'More restrictive sanitisation. May affect complex SVG animations.', 'plathix' ); ?></span>
			</div>
		</label>
		<?php
	}
}
