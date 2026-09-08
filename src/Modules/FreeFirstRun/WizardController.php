<?php

declare(strict_types=1);

namespace Plathix\Modules\FreeFirstRun;

use Plathix\Core\PresetPageContract;
use Plathix\PublicApi\PresetOnboardingApi;

final class WizardController
{

	public const SKIP_ACTION = 'plathix_preset_skip';

	public const RESET_WIZARD_ACTION = 'plathix_reset_wizard';

	public function register(): void {
		add_action( 'admin_post_' . self::SKIP_ACTION, [ $this, 'handleSkip' ] );
		add_action( 'admin_post_' . self::RESET_WIZARD_ACTION, [ $this, 'handleResetWizard' ] );
		add_action( 'admin_notices', [ $this, 'maybeShowResetWizardNotice' ] );
	}

	public function handleSkip(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'plathix' ), 403 );
		}

		check_admin_referer( self::SKIP_ACTION );

		PresetOnboardingApi::markSkipped();

		wp_safe_redirect( admin_url( 'admin.php?page=' . (string) apply_filters( 'plathix/admin/rootSlug', 'plathix' ) ) );
		exit;
	}

	public function handleResetWizard(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'plathix' ), 403 );
		}

		check_admin_referer( self::RESET_WIZARD_ACTION );

		PresetOnboardingApi::reset();

		wp_safe_redirect( admin_url( 'admin.php?page=' . PresetPageContract::PAGE_SLUG . '&wizard_reset=1' ) );
		exit;
	}

	public function maybeShowResetWizardNotice(): void {
		if ( ! isset( $_GET['wizard_reset'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display flag, no action taken
			return;
		}
		echo '<div class="notice notice-success is-dismissible"><p>'
			. esc_html__( 'Wizard reset. It will appear on the Dashboard on next visit.', 'plathix' )
			. '</p></div>';
	}

	public function renderResetWizardButton(): void {
		$nonce = wp_create_nonce( self::RESET_WIZARD_ACTION );
		$url   = admin_url( 'admin-post.php?action=' . self::RESET_WIZARD_ACTION . '&_wpnonce=' . $nonce );
		?>
		<a href="<?php echo esc_url( $url ); ?>" class="plathix-btn">
			<?php esc_html_e( 'Reset wizard', 'plathix' ); ?>
		</a>
		<?php
	}
}
