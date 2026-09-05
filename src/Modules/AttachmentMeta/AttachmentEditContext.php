<?php

declare(strict_types=1);

namespace Plathix\Modules\AttachmentMeta;

/**
 * Различение контекста рендера полей вложения: страница файла vs медиа-модалка.
 *
 * `attachment_fields_to_edit` стреляет в обоих контекстах. На странице
 * отдельного файла (`post.php`) поля «Папка»/«Заменить файл» должны идти из
 * мета-бокса справа, поэтому compat-поля там не добавляются — иначе будет дубль.
 * В медиа-модалке мета-боксы не показываются, поэтому compat-поля остаются
 * единственным путём рендера и добавляются как обычно.
 */
final class AttachmentEditContext
{
	/**
	 * Идёт ли рендер на странице редактирования вложения (`post.php`), а не в модалке.
	 *
	 * Почему так: модалка рендерит compat-поля исключительно через AJAX
	 * (`wp_doing_ajax()` === true). Полная страница `post.php` грузится обычным
	 * запросом, поэтому `$pagenow === 'post.php'` без AJAX однозначно означает
	 * страницу файла, а не модалку.
	 */
	public static function is_attachment_edit_page(): bool {
		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return false;
		}

		global $pagenow;

		return is_admin() && $pagenow === 'post.php';
	}
}
