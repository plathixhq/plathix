<?php

declare(strict_types=1);

namespace Plathix\Core;

use Plathix\Loader;

final class MediaTrashPolicy
{
	/**
	 * Media trash is available when attachments exist as a post type.
	 * The plugin now uses WP trash status as the source of truth and does not
	 * silently fall back to permanent deletion.
	 */
	public static function trash_enabled(): bool {
		return post_type_exists('attachment');
	}

	public function __construct(
		private readonly Loader $loader
	) {
		// [internal]: PHP-константа MEDIA_TRASH недостижима для плагина — WP core сам
		// определяет её в wp_default_constants() (default-constants.php) до plugins_loaded,
		// подтверждено `wp eval` на живом стенде (значение остаётся false даже когда плагин
		// пытается define() её сам). media_view_settings — официальный публичный фильтр
		// (wp-includes/media.php), исполняется ДО того как media-views.js захватывает
		// mediaTrash в module-level closure, поэтому это единственный WP-native путь без
		// патча wp-config.php пользователя или переопределения Backbone-обработчика ядра.
		$this->loader->add_filter( 'media_view_settings', $this, 'enable_native_media_trash', 10, 2 );

		// [internal] (расширение scope): режим "Список" читает bulk actions из отдельного
		// места (WP_Media_List_Table::get_bulk_actions(), не JS media_view_settings) —
		// той же самой проблемы (MEDIA_TRASH недостижима для плагина) требует отдельный
		// фильтр. bulk_actions-upload — официальный WP-хук (class-wp-list-table.php),
		// вызывается ПОСЛЕ get_bulk_actions() и может полностью заменить массив.
		$this->loader->add_filter( 'bulk_actions-upload', $this, 'fix_trash_bulk_actions', 10, 1 );
	}

	/**
	 * @param array<string, mixed> $settings
	 * @return array<string, mixed>
	 */
	public function enable_native_media_trash(array $settings, ?\WP_Post $post = null): array {
		$settings['mediaTrash'] = 1;
		return $settings;
	}

	/**
	 * WP_Media_List_Table сама определяет is_trash тем же способом (см. её __construct):
	 * $_REQUEST['attachment-filter'] === 'trash'. Тот же паттерн детекта уже использует
	 * FolderQuery::filter_list_view ([internal]) — консистентно с уже принятым решением.
	 *
	 * @param array<string, string> $actions
	 * @return array<string, string>
	 */
	public function fix_trash_bulk_actions(array $actions): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only nav filter for building bulk-action labels; sanitized (sanitize_key), not written
		$attachment_filter = sanitize_key( (string) wp_unslash( $_REQUEST['attachment-filter'] ?? '' ) );
		if ( $attachment_filter !== 'trash' ) {
			return $actions;
		}

		return array(
			'untrash' => __( 'Restore', 'plathix' ),
			'delete'  => __( 'Delete permanently', 'plathix' ),
		);
	}
}
