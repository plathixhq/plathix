<?php


declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$plathix_autoload = __DIR__ . '/vendor/autoload.php';
if ( file_exists( $plathix_autoload ) ) {
	require_once $plathix_autoload;
}

/**
 * @return list<int>
 */
function plathix_uninstall_sites(): array {
	if ( is_multisite() ) {
		return array_map( 'intval', get_sites( [ 'fields' => 'ids', 'number' => 0 ] ) );
	}

	return [ get_current_blog_id() ];
}

function plathix_uninstall_cleanup_site(int $blog_id): void {
	if ( is_multisite() ) {
		switch_to_blog( $blog_id );
	}

	if ( class_exists( \Plathix\Modules\DataWipe\DataWiper::class ) ) {
		( new \Plathix\Modules\DataWipe\DataWiper() )->wipe( $blog_id );
	} else {
		error_log( 'Plathix uninstall: vendor/autoload.php is unavailable; data for blog ' . $blog_id . ' was not cleaned automatically.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

	}

	if ( is_multisite() ) {
		restore_current_blog();
	}
}

foreach ( plathix_uninstall_sites() as $blog_id ) {
	plathix_uninstall_cleanup_site( $blog_id );
}

if ( is_multisite() ) {
	delete_site_option( 'plathix_network_svg_policy' );
}

do_action( 'plathix/delete_plugin_data' );
