<?php

declare(strict_types=1);

namespace Plathix\Modules\Pro;

use Plathix\Edition;
use Plathix\Infrastructure\Keys;

final class ProLicenseActions
{

	public function register(): void {
		add_action( 'admin_post_plathix_activate_license', [ $this, 'handleActivate' ] );
		add_action( 'admin_post_plathix_deactivate_license', [ $this, 'handleDeactivate' ] );
	}

	public function handleActivate(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'plathix' ) );
		}

		check_admin_referer( 'plathix_activate_license' );

		$redirect = admin_url( 'admin.php?page=' . ProPage::PAGE_SLUG );
		$key      = sanitize_text_field( (string) wp_unslash( $_POST['plathix_license_key'] ?? '' ) );

		if ( ! LicensePolicy::isValidKeyFormat( $key ) ) {
			wp_safe_redirect( add_query_arg( 'plathix_license', 'error', $redirect ) );
			exit;
		}

		update_option( Edition::KEY_OPTION, $key, false );
		delete_option( Edition::STATUS_OPTION );
		delete_transient( Keys::licenseError() );

		/**
		 * @param string $key
		 */

		do_action( 'plathix/license/activate', $key );

		$status = (string) get_option( Edition::STATUS_OPTION, '' );
		wp_safe_redirect( add_query_arg( 'plathix_license', self::activationNotice( $status ), $redirect ) );
		exit;
	}

	/**
	 * @param string $status
	 */

	public static function activationNotice(string $status): string {
		if ( 'active' === $status ) {
			return 'activated';
		}

		return 'error';
	}

	public function handleDeactivate(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'plathix' ) );
		}

		check_admin_referer( 'plathix_deactivate_license' );

		$key = (string) get_option( Edition::KEY_OPTION, '' );

		/**
		 * @param string $key
		 */

		do_action( 'plathix/license/deactivate', $key );

		delete_option( Edition::KEY_OPTION );
		delete_option( Edition::STATUS_OPTION );
		delete_option( Edition::EXPIRES_OPTION );
		delete_transient( Keys::licenseError() );

		$redirect = admin_url( 'admin.php?page=' . ProPage::PAGE_SLUG );
		wp_safe_redirect( add_query_arg( 'plathix_license', 'deactivated', $redirect ) );
		exit;
	}
}
