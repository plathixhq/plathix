<?php

declare(strict_types=1);

namespace Plathix\Infrastructure;

/**
 * Единый резолвер временной директории Plathix.
 *
 * Раньше логика выбора tmp-папки жила в JobDispatcher::get_temp_dir(), а модуль
 * Replace независимо хардкодил свою папку `plathix_tmp`. Это приводило к
 * рассинхрону: System Info рапортовал про `plathix-temp`, а replace писал в
 * `plathix_tmp` и падал на правах. Этот сервис — единственный источник истины.
 *
 * [internal] (WP.org review round 1): первый кандидат раньше был
 * `WP_CONTENT_DIR . '/../plathix-temp'` — на уровень выше `wp-content`, то есть за
 * пределами установки WordPress на стандартной раскладке. WP.org запрещает запись туда
 * («Plugins must never write ... to paths outside the WordPress installation»); кандидат
 * убран, резолв теперь остаётся внутри установки на каждом шаге.
 */
final class TempDirectory
{
	/**
	 * Абсолютный trailingslashed путь к временной директории Plathix.
	 *
	 * Порядок резолва:
	 * 1. фильтр `plathix/infrastructure/temp_dir` — если задан непустой путь, он используется;
	 * 2. первый из кандидатов, который существует или может быть создан;
	 * 3. фолбэк в `uploads/plathix-temp`.
	 */
	public function path(): string
	{
		$preferred = [
			\rtrim( \sys_get_temp_dir(), '/\\' ) . '/plathix-temp',
		];
		$upload     = \wp_upload_dir();
		$fallback   = \trailingslashit( (string) ( $upload['basedir'] ?? '' ) ) . 'plathix-temp';
		$configured = \apply_filters( 'plathix/infrastructure/temp_dir', '' );

		if ( \is_string( $configured ) && $configured !== '' ) {
			return \trailingslashit( $configured );
		}

		foreach ( $preferred as $candidate ) {
			if ( @\is_dir( $candidate ) || @\wp_mkdir_p( $candidate ) ) {
				return \trailingslashit( $candidate );
			}
		}

		return \trailingslashit( $fallback );
	}

	/**
	 * [internal] ([internal]): рекурсивно удаляет каталог со всем содержимым.
	 *
	 * До этого метода тот же обход `RecursiveIteratorIterator(CHILD_FIRST)` был написан
	 * трижды дословно — в `SettingsSaveHandler::remove_dir()`,
	 * `PresetUploadPipeline::remove_temp_dir()` и `PresetExportPipeline::cleanup()`, каждый
	 * со своей копией одинакового обоснования `phpcs:ignore`. Владельцем временных
	 * каталогов является этот класс, поэтому операции над ними принадлежат ему же, а не
	 * трём потребителям.
	 *
	 * `WP_Filesystem` здесь неприменим: он требует `request_filesystem_credentials()`,
	 * который на фоновых и admin-post путях уводит пользователя в форму ввода FTP. Для
	 * локального каталога, созданного самим плагином, это не абстракция, а поломка.
	 * Файлы удаляются через `wp_delete_file()` — нативную обёртку WP, проходящую
	 * одноимённый фильтр.
	 *
	 * @param string $dir Абсолютный путь. Пустая строка и несуществующий путь — no-op.
	 */
	public static function remove_tree(string $dir): void
	{
		if ( $dir === '' || ! \is_dir( $dir ) ) {
			return;
		}

		$entries = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $entries as $entry ) {
			if ( $entry->isDir() ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- WP has no directory-removal API; WP_Filesystem would demand FTP credentials for a directory this plugin created itself
				\rmdir( $entry->getRealPath() );
			} else {
				\wp_delete_file( $entry->getRealPath() );
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- removes the now-empty directory after its realpath-resolved contents were deleted above
		\rmdir( $dir );
	}
}
