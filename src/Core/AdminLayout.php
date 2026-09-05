<?php

declare(strict_types=1);

namespace Plathix\Core;

use Plathix\Admin\AdminLayoutNav;

class AdminLayout
{
	public static function open(string $current_page): void {
		$plathix_current_page = $current_page; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- prefixed local handed to the required template partial; the prefix prevents collisions with variables in the including scope
		// Секции сайдбара собираются из реестра plathix/admin/menu_pages ([internal]),
		// а не хардкодятся в шаблоне. Чтение ленивое — здесь, в render-контексте страницы.
		$plathix_nav_sections = AdminLayoutNav::sections(); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- prefixed local handed to the required template partial; the prefix prevents collisions with variables in the including scope
		require PLATHIX_PATH . 'views/admin-layout.php';
	}

	public static function close(): void {
		require PLATHIX_PATH . 'views/admin-layout-end.php';
	}

	/**
	 * Оборачивает тело admin-страницы в платформенный layout (хедер + сайдбар + футер).
	 *
	 * Страница отдаёт ТОЛЬКО своё тело через $body, а обёртку (в т.ч. футер из close())
	 * гарантирует платформа — close() исчезает из тел страниц, забыть его нельзя.
	 *
	 * Вывод $body буферизуется. Обёртка печатается ТОЛЬКО при непустом теле: если $body сделал
	 * cap-гейт (wp_die / return) и ничего не напечатал — обёртка не выводится (эквивалентно
	 * текущему «нет прав → голая страница без каркаса»). cap-гейт должен быть первой инструкцией
	 * $body, до любого echo (тогда при wp_die буфер пуст; дополнительно WP core
	 * _default_wp_die_handler сам ob_end_clean буферы до печати — полурендер невозможен).
	 *
	 * @param string   $slug PAGE_SLUG страницы — для подсветки активного пункта сайдбара.
	 * @param callable $body Печатает тело страницы (div.plathix-page) через echo; cap-гейт первым.
	 */
	public static function render_page(string $slug, callable $body): void {
		ob_start();
		$body();
		$html = (string) ob_get_clean();

		if ( '' === trim( $html ) ) {
			return; // cap-гейт отработал — тело пустое — обёртку не печатаем.
		}

		self::open( $slug );
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $body already escaped its own output at echo time; the ob buffer is a byte passthrough of markup this plugin produced, re-escaping would double-escape the page.
		self::close();
	}
}
