<?php

declare(strict_types=1);

namespace Plathix\Modules\Svg;

final class SvgSettings
{

	public const POLICY_SANITIZE = 'sanitize';

	public const POLICY_BLOCK = 'block';

	public const POLICY_IGNORE = 'ignore';

	private const OPTION_POLICY = 'plathix_svg_policy';
	private const OPTION_SAFE_MODE = 'plathix_svg_safe_mode';

	public static function currentPolicy(): string
	{
		return self::sanitizePolicy( get_option( self::OPTION_POLICY, self::POLICY_SANITIZE ) );
	}

	public static function isSafeMode(): bool
	{
		return (bool) get_option( self::OPTION_SAFE_MODE, is_multisite() );
	}

	public static function sanitizePolicy(mixed $value): string
	{
		$value = is_string( $value ) ? $value : '';
		return in_array( $value, [ self::POLICY_SANITIZE, self::POLICY_BLOCK, self::POLICY_IGNORE ], true )
			? $value
			: self::POLICY_SANITIZE;
	}

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
		do_action( 'plathix/settings/save', 'plathix_svg_policy', function (mixed $raw = null): bool {
			$old_value = self::currentPolicy();
			$new_value = self::sanitizePolicy( wp_unslash( $raw ?? '' ) );

			$succeeded = \Plathix\Infrastructure\OptionWrite::ifChanged( 'plathix_svg_policy', $new_value );
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
			return $succeeded;
		} );

		do_action( 'plathix/settings/save', 'plathix_svg_safe_mode', function (mixed $raw = null): bool {
			$old_value = self::isSafeMode();
			$new_value = (bool) wp_unslash( $raw ?? false );
			$succeeded = \Plathix\Infrastructure\OptionWrite::ifChanged( 'plathix_svg_safe_mode', $new_value );
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
			return $succeeded;
		} );

		do_action( 'plathix/settings/save', 'plathix_svg_support', function (mixed $posted = null): bool {
			$old_value = (array) get_option( 'plathix_svg_support', [ 'administrator', 'editor' ] );
			$raw       = wp_unslash( $posted ?? [] );
			$new_value = $this->sanitizeSupportRoles( is_array( $raw ) ? $raw : [] );

			$succeeded = \Plathix\Infrastructure\OptionWrite::ifChanged( 'plathix_svg_support', $new_value );

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
			return $succeeded;
		} );

		do_action( 'plathix/settings/register_tab', 'svg', [
			'plathix_svg_policy',
			'plathix_svg_safe_mode',
			'plathix_svg_support',
		] );
	}

	/**
	 * @param array<int, array{slug:string,label:string,render:callable}> $tabs
	 * @return array<int, array{slug:string,label:string,render:callable}>
	 */

	public function addTab(array $tabs): array
	{
		$tabs[] = [
			'slug'   => 'svg',
			'label'  => __( 'SVG', 'plathix' ),
			'render' => [ $this, 'renderTab' ],
		];

		return $tabs;
	}

	/**
	 * @return array<int, string>
	 */

	public function sanitizeSupportRoles(mixed $value): array
	{
		/** @var object{roles:array<string,mixed>} $wp_roles_obj -- wp_roles() returns WP_Roles; phpstan infers generic object */
		$wp_roles_obj = wp_roles();
		$roles    = array_map( 'strval', array_keys( $wp_roles_obj->roles ) );
		$selected = array_values( array_intersect( $roles, array_map( 'sanitize_key', (array) $value ) ) );

		return $selected;
	}

	public function renderTab(): void
	{
		?>
		<div class="plathix-field">
			<div class="plathix-field__label"><?php esc_html_e( 'SVG Uploads', 'plathix' ); ?></div>
			<div class="plathix-field__desc"><?php esc_html_e( 'Choose how Plathix handles .svg uploads across the whole site.', 'plathix' ); ?></div>
			<?php $this->renderPolicy(); ?>
		</div>

		<?php

		$dependent_style = self::currentPolicy() === self::POLICY_SANITIZE ? '' : ' style="display:none;"';
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
					<?php $this->renderRoles(); ?>
				</div>
			</div>

			<div class="plathix-field__separator"></div>

			<div class="plathix-field">
				<div class="plathix-field__label"><?php esc_html_e( 'SVG Safe Mode', 'plathix' ); ?></div>
				<div class="plathix-field__desc"><?php esc_html_e( 'Strips more aggressively — rejects uploads where &lt;use&gt; or &lt;image&gt; elements reference external resources. Recommended for sites with untrusted editors.', 'plathix' ); ?></div>
				<?php $this->renderSafeMode(); ?>
			</div>

		</div>
		<?php
	}

	private function renderPolicy(): void
	{
		$policy = self::currentPolicy();
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

	private function renderRoles(): void
	{
		$selected = (array) get_option( 'plathix_svg_support', [ 'administrator', 'editor' ] );
		/** @var object{roles:array<string,mixed>} $wp_roles_obj -- wp_roles() returns WP_Roles; phpstan infers generic object */
		$wp_roles_obj = wp_roles();

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

	private function renderSafeMode(): void
	{
		$enabled = self::isSafeMode();

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
