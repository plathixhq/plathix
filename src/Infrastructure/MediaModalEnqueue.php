<?php

declare(strict_types=1);

namespace Plathix\Infrastructure;

/**
 * [internal]: единая точка регистрации enqueue-callback'а на `admin_enqueue_scripts` И
 * `wp_enqueue_media` — тот же workaround ([internal] → #275 → #276 → #277 → #278) для того,
 * что `admin_enqueue_scripts` не срабатывает в Elementor frontend editor, был скопирован
 * вручную в 11 местах без общего примитива, одна из копий уже расходилась в guard/priority.
 *
 * `wp_enqueue_media` — нативный WP-хук, срабатывающий в ЛЮБОМ media-picker-контексте
 * (upload.php grid, post.php, все page builders), в отличие от `admin_enqueue_scripts`.
 *
 * `$guard_admin_hook` применяется ТОЛЬКО к `admin_enqueue_scripts` ([internal]/#278:
 * Elementor frontend editor не всегда `is_admin() === true`, поэтому `wp_enqueue_media`
 * никогда не оборачивается в этот guard — иначе фикс #277/#278 откатывается назад).
 *
 * Priority — обязательный явный параметр на каждый вызов, не константа helper'а: у
 * SearchFilters `wp_enqueue_media`-приоритет (20) намеренно выполняется ПОСЛЕ
 * `Assets::enqueue_sidebar_for_media_modal()` (приоритет 10, тот же хук) из-за
 * idempotent-guard `wp_script_is('plathix-sidebar', 'enqueued')` — единая константа
 * молча сломала бы этот межмодульный порядок без PHP-ошибки.
 */
final class MediaModalEnqueue
{
	/**
	 * @param callable $callback
	 * @param int|null $admin_priority приоритет для `admin_enqueue_scripts`; `null` — не
	 *   регистрировать колбэк на этом хуке вовсе.
	 * @param int $media_priority приоритет для `wp_enqueue_media`.
	 * @param bool $guard_admin_hook если true, регистрация на `admin_enqueue_scripts`
	 *   выполняется только внутри `is_admin()` ([internal]/#278). Никогда не влияет на
	 *   `wp_enqueue_media`.
	 */
	public static function register(
		callable $callback,
		?int $admin_priority = 10,
		int $media_priority = 10,
		bool $guard_admin_hook = false
	): void {
		if ( $admin_priority !== null ) {
			if ( ! $guard_admin_hook || is_admin() ) {
				add_action( 'admin_enqueue_scripts', $callback, $admin_priority );
			}
		}

		add_action( 'wp_enqueue_media', $callback, $media_priority );
	}
}
