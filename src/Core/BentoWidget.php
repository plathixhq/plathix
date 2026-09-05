<?php

declare(strict_types=1);

namespace Plathix\Core;

/**
 * Общий рендер-хелпер для заголовка bento-виджета дашборда (Free и PRO).
 *
 * До этого класса все 9 dashboard/bento-виджетов (Free: Folders, Uploads, MimeTypes,
 * Preset, Orphaned; PRO: QuickLinks, ShortcodesWidget, ActivityCompactWidget,
 * ContentTypesWidget) инлайнили идентичную по структуре разметку заголовка
 * (`<h2 class="plathix-bento__label">`) каждый в своём файле самостоятельно — из-за
 * отсутствия единого источника правды один виджет (ContentTypesWidget) при переносе
 * из Free в PRO скопировал не тот шаблон ([internal]).
 */
final class BentoWidget
{
	/**
	 * Рендерит `.plathix-bento__label` заголовок виджета. `$label` должен быть уже
	 * переведён (`__()`), метод сам экранирует вывод — вызывающий код не должен
	 * оборачивать `$label` в esc_html повторно.
	 */
	public static function label(string $label, string $tag = 'h2'): void {
		echo '<' . esc_html( $tag ) . ' class="plathix-bento__label">' . esc_html( $label ) . '</' . esc_html( $tag ) . '>';
	}
}
