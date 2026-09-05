<?php

declare(strict_types=1);

namespace Plathix\Admin;

/**
 * Сборщик секций левого сайдбара платформы Plathix из реестра страниц
 * `plathix/admin/menu_pages` ([internal]).
 *
 * Раньше пункты сайдбара были захардкожены в views/admin-layout.php двумя массивами.
 * Теперь единственный источник правды — тот же дескриптор страницы, что уже питает
 * AdminMenuManager (enqueue/flyout). Каждая страница объявляет свою принадлежность
 * сайдбару полями дескриптора:
 *   section => 'main' | 'footer'   — группа сайдбара (нет поля → пункт не в сайдбаре)
 *   order   => int                 — порядок внутри секции (по возрастанию)
 *   label   => string              — подпись пункта
 *   icon    => string              — инлайновый SVG-маркап
 *   is_pro  => bool (опц.)         — стиль pro-nav
 *   badge   => string (опц.)       — бейдж пункта (напр. 'PRO')
 *
 * Читается ЛЕНИВО из render-контекста страницы (через AdminLayout::open()), то есть
 * много позже plugins_loaded — к этому моменту фильтр menu_pages уже полон. Инвариант
 * момента регистрации ([internal]) не нарушается: helper не зовётся в
 * register()/boot().
 */
final class AdminLayoutNav
{
	/**
	 * Собрать пункты сайдбара, сгруппированные по секциям и отсортированные по order.
	 *
	 * @return array{main: array<int, array<string, mixed>>, footer: array<int, array<string, mixed>>}
	 */
	public static function sections(): array
	{
		/** @var array<int, array<string, mixed>> $pages */
		$pages = (array) apply_filters( 'plathix/admin/menu_pages', [] );

		$grouped = [ 'main' => [], 'footer' => [] ];

		foreach ( $pages as $page ) {
			if ( ! is_array( $page ) ) {
				continue;
			}
			$section = (string) ( $page['section'] ?? '' );
			if ( ! isset( $grouped[ $section ] ) ) {
				continue; // без section (или иное значение) — пункт в сайдбаре не показывается.
			}
			$grouped[ $section ][] = $page;
		}

		foreach ( $grouped as &$items ) {
			usort(
				$items,
				static fn (array $a, array $b): int => ( (int) ( $a['order'] ?? 0 ) ) <=> ( (int) ( $b['order'] ?? 0 ) )
			);
		}
		unset( $items );

		return $grouped;
	}
}
