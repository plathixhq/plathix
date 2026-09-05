<?php

declare(strict_types=1);

namespace Plathix\Core;

use Plathix\User\Preferences;

/**
 * Единственный источник правды для «какая папка сейчас логически открыта» с учётом
 * текущего HTTP-запроса ([internal], [internal]).
 *
 * Domain-факт, не presentation-специфика: правило остаётся истинным независимо от того,
 * кто его читает (sidebar, list-view) — раньше жило по копии в каждом потребителе
 * (`SidebarRuntimeConfigBuilder::resolve_open_folder_id()`,
 * `FolderQuery::resolve_list_view_folder_id()`), что создавало риск рассинхрона при правке
 * одной копии без другой.
 *
 * Приоритет: явный URL `plathix_folder` param → native WP trash screen (attachment) →
 * сохранённое пользовательское предпочтение.
 */
final class RequestFolderResolver
{
	public static function resolve(string $post_type, string $taxonomy): int {
		$url_folder = absint( wp_unslash( $_GET['plathix_folder'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only nav param picking the open folder for the current request; sanitized (absint), no form processing, no DB write
		if ( $url_folder > 0 ) {
			return $url_folder;
		}

		// If the user lands on the native media trash screen, sync the selection with the
		// real system Trash term so the current selection stays consistent.
		if ( $post_type === 'attachment' && isset( $_GET['status'] ) && $_GET['status'] === 'trash' ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only nav param detecting the native media trash screen to sync selection; compared as literal, no form processing, no DB write
			return TrashFolder::id( $taxonomy );
		}

		return Preferences::get_open_folder_id( get_current_user_id(), $post_type );
	}
}
