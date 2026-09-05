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

// [internal]: этот файл (и только этот блок до require) обязан парситься на очень старом
// PHP (7.0+) — WordPress core сам гасит кнопки Install/Activate по заголовку "Requires PHP"
// выше ТОЛЬКО до активации; если сервер понизил PHP ПОСЛЕ активации плагина, WordPress этот
// сценарий не ловит, а recovery mode ядра не спасает от parse error (он возникает раньше
// выполнения любого кода, включая код самой защиты). Поэтому здесь нет declare(strict_types=1),
// typed closure params, static function, fn() и другого синтаксиса PHP 7.1+/7.4+/8.x — только
// обычные функции без типизации параметров. Весь современный код (str_starts_with(), fn()
// и т.д.) живёт в includes/bootstrap.php, который подключается ТОЛЬКО если версия PHP
// пройдёт проверку ниже — на несовместимом сервере этот файл никогда не открывается
// интерпретатором (require компилируется лениво, по мере выполнения, не заранее).
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

	// Слой 2 ([internal]): понятное сообщение при попытке АКТИВАЦИИ на несовместимом PHP —
	// защита на случай, если WP core header-check (Requires PHP) почему-то не заблокировал
	// кнопку Activate (force-activate через WP-CLI/мультисайт edge case). wp_die() здесь
	// намеренно вместо тихого return — активация должна явно провалиться с сообщением, не
	// оставить плагин "activated" в БД но фактически неработающим.
	function plathix_php_incompatible_activation_guard() {
		wp_die(esc_html(plathix_php_incompatible_message()));
	}
	register_activation_hook(__FILE__, 'plathix_php_incompatible_activation_guard');

	// Слой 3 ([internal], главный): если плагин УЖЕ был активен, а PHP понизили позже —
	// показать notice и самостоятельно деактивировать, вместо белого экрана/тишины.
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
