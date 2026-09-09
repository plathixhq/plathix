<?php

declare(strict_types=1);

namespace Plathix\Modules\FreeFirstRun;

use Plathix\Contracts\ModuleInterface;
use Plathix\Infrastructure\Keys;
use Plathix\User\AccessResolver;

final class Module implements ModuleInterface
{
	private readonly WizardController $controller;

	public function __construct(?WizardController $controller = null) {
		$this->controller = $controller ?? new WizardController();
	}

	public function register(): void {
		add_action( 'plathix/modules/boot', [ $this, 'boot' ] );
	}

	public function boot(): void {
		if ( ! is_admin() ) {
			return;
		}

		$this->controller->register();

		add_action( 'plathix/preset/reset_wizard_button', [ $this->controller, 'renderResetWizardButton' ] );

		( new WizardAssets() )->register();

		add_action( 'plathix/onboarding/render_modal', [ FreeWizard::class, 'renderHook' ], FreeWizard::HOOK_PRIORITY );

		add_action( 'admin_init', [ $this, 'maybeRedirectAfterActivation' ] );
	}

	public function maybeRedirectAfterActivation(): void {
		$transient_key = Keys::transient( 'activation_redirect' );

		if ( ! get_transient( $transient_key ) ) {
			return;
		}

		if ( wp_doing_ajax() || wp_doing_cron() || is_network_admin() || ! AccessResolver::currentUserIsFullAdmin() ) {
			return;
		}

		delete_transient( $transient_key );

		wp_safe_redirect( admin_url( 'admin.php?page=' . (string) apply_filters( 'plathix/admin/rootSlug', 'plathix' ) ) );
		exit;
	}
}
