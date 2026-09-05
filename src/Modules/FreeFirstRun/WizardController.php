<?php

declare(strict_types=1);

namespace Plathix\Modules\FreeFirstRun;

use Plathix\Core\PresetPageContract;
use Plathix\PublicApi\PresetOnboardingApi;

/**
 * Обработчики wizard-действий Free-визарда ([internal]).
 *
 * Ранее жили в PresetsPage как legacy ([internal] FW-101), затем осколком в Preset.
 * Skip/Reset — wizard-домен, а не preset-операции: не трогают папки или пресет-данные,
 * только state {@see PresetOnboarding} (state остаётся в Preset — PRO-инвариант B1).
 *
 * Регистрируется из {@see Module::boot()} ([internal], [internal]).
 */
final class WizardController
{
	/** admin_post_* action: пометить визард skipped, ничего не удалять. */
	public const SKIP_ACTION = 'plathix_preset_skip';

	/** admin_post_* action: сбросить состояние визарда (для тестировщиков/администраторов). */
	public const RESET_WIZARD_ACTION = 'plathix_reset_wizard';

	public function register(): void {
		add_action( 'admin_post_' . self::SKIP_ACTION, [ $this, 'handle_skip' ] );
		add_action( 'admin_post_' . self::RESET_WIZARD_ACTION, [ $this, 'handle_reset_wizard' ] );
		add_action( 'admin_notices', [ $this, 'maybe_show_reset_wizard_notice' ] );
	}

	/**
	 * «Пропустить настройку» Free-визарда ([internal] FW-103): помечает онбординг skipped
	 * и уводит на Dashboard. НИЧЕГО не удаляет (в отличие от handle_scratch) — «пропустить» ≠
	 * «стереть структуру». Визард после этого больше не всплывает.
	 */
	public function handle_skip(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'plathix' ), 403 );
		}

		check_admin_referer( self::SKIP_ACTION );

		PresetOnboardingApi::markSkipped();

		wp_safe_redirect( admin_url( 'admin.php?page=' . (string) apply_filters( 'plathix/admin/root_slug', 'plathix' ) ) );
		exit;
	}

	/**
	 * Сбрасывает состояние Free-визарда ([internal]): удаляет опцию
	 * plathix_preset_onboarding, визард снова всплывает на Dashboard при следующем заходе.
	 * Только для manage_options — инструмент тестировщиков/администраторов.
	 */
	public function handle_reset_wizard(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'plathix' ), 403 );
		}

		check_admin_referer( self::RESET_WIZARD_ACTION );

		PresetOnboardingApi::reset();

		wp_safe_redirect( admin_url( 'admin.php?page=' . PresetPageContract::PAGE_SLUG . '&wizard_reset=1' ) );
		exit;
	}

	/** Показывает one-shot notice после handle_reset_wizard() редиректа. */
	public function maybe_show_reset_wizard_notice(): void {
		if ( ! isset( $_GET['wizard_reset'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display flag, no action taken
			return;
		}
		echo '<div class="notice notice-success is-dismissible"><p>'
			. esc_html__( 'Wizard reset. It will appear on the Dashboard on next visit.', 'plathix' )
			. '</p></div>';
	}

	/**
	 * Рендерит кнопку «Reset wizard» для администраторов ([internal]).
	 *
	 * Вызывается через слот `plathix/preset/reset_wizard_button` ([internal]) — подписка в
	 * {@see Module::boot()}. PresetsPage больше не создаёт этот класс напрямую по FQCN
	 * (прежнее cross-module test-исключение снято: прецедент, на который оно ссылалось,
	 * `AttachmentReplaceUi::render_replace_trigger()`, сам был устранён `[internal]`
	 * 2026-07-21 — до того, как это исключение было заведено).
	 */
	public function render_reset_wizard_button(): void {
		$nonce = wp_create_nonce( self::RESET_WIZARD_ACTION );
		$url   = admin_url( 'admin-post.php?action=' . self::RESET_WIZARD_ACTION . '&_wpnonce=' . $nonce );
		?>
		<a href="<?php echo esc_url( $url ); ?>" class="plathix-btn">
			<?php esc_html_e( 'Reset wizard', 'plathix' ); ?>
		</a>
		<?php
	}
}
