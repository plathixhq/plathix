<?php

/**
 * Plugin Name:       Plathix - Media Library Folders
 * Plugin URI:        https://plathix.com/
 * Description:       Everything in its place. Organize your WordPress Media Library with folders, ready sets, replace media, SVG handling, and Trash.
 * Version:           1.0.0
 * Requires at least: 7.0
 * Requires PHP:      8.1
 * Author:            Plathix
 * Author URI:        https://plathix.com/
 * License:           GPLv3 or later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       plathix
 * Domain Path:       /languages
 */

if ( ! defined('ABSPATH') ) {
	exit;
}

define('PLATHIX_MIN_PHP', '8.1');

if ( version_compare(PHP_VERSION, PLATHIX_MIN_PHP, '<') ) {
	function plathix_php_incompatible_message() {
		return sprintf(
			'Plathix needs PHP %s or newer. This site runs PHP %s.',
			PLATHIX_MIN_PHP,
			PHP_VERSION
		);
	}

	function plathix_php_incompatible_notice() {
		if ( ! function_exists('current_user_can') || ! current_user_can('activate_plugins') ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html(plathix_php_incompatible_message())
		);
	}

	function plathix_php_incompatible_activation_guard() {
		wp_die(esc_html(plathix_php_incompatible_message()));
	}
	register_activation_hook(__FILE__, 'plathix_php_incompatible_activation_guard');

	add_action('admin_notices', 'plathix_php_incompatible_notice');

	function plathix_php_incompatible_self_deactivate() {
		if ( ! function_exists('is_plugin_active') ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( is_plugin_active(plugin_basename(__FILE__)) ) {
			deactivate_plugins(plugin_basename(__FILE__));
		}
	}
	add_action('admin_init', 'plathix_php_incompatible_self_deactivate');

	return;
}

define('PLATHIX_FILE', __FILE__);

require __DIR__ . '/includes/bootstrap.php';
