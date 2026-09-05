<?php

declare(strict_types=1);

namespace Plathix\Modules\ListScreen;

/**
 * [internal]/#249: `WP_List_Table::search_box()` (WP core, не переопределён Plathix) не
 * несёт `orderby`/`order`/`s`/`post_mime_type`/`author`/`post_status` в своих hidden-полях
 * — сабмит формы поиска в list-mode сбрасывает выбранную пользователем сортировку/фильтры
 * на дефолтные. `restrict_manage_posts` — нативный WP action, срабатывающий внутри той же
 * `<form>`, что и `search_box()`, — сюда добавляются недостающие hidden-поля, без
 * собственного JS-перехвата сабмита.
 *
 * Санитизация вынесена в `ListScreenQueryContext` ([internal]) — единый источник для этого
 * класса, `FolderColumn::filter_url()` и `ListScreenFragmentsController::build_get_args()`,
 * вместо трёх независимых allowlist'ов, которые расходились между собой.
 *
 * `m` (месяц/дата) намеренно НЕ несётся здесь: и `WP_Media_List_Table`, и
 * `WP_Posts_List_Table` уже рендерят нативный `<select name="m">` (`months_dropdown()`)
 * внутри той же `extra_tablenav()` — вывод ещё и hidden `<input name="m">` создал бы
 * дублирующее поле с тем же именем в одной форме.
 */
class SearchSortFields
{
	public function register(): void {
		add_action('restrict_manage_posts', [ $this, 'render' ], 10, 2);
	}

	public function render(string $post_type, string $which): void {
		// WP core: $which — 'top'/'bottom' для WP_Posts_List_Table, 'bar' для
		// WP_Media_List_Table (единственный вызов extra_tablenav() на media-экране,
		// class-wp-media-list-table.php). Рендерим один раз на 'top' (CPT) ИЛИ 'bar'
		// (media) — 'bottom' пропускаем, иначе поля задублировались бы на CPT-экранах.
		// CTAN-201: Free-поля только на медиатеке; экраны записей обслуживает PRO.
		if ( ! in_array($which, [ 'top', 'bar' ], true) || 'attachment' !== $post_type ) {
			return;
		}

		$context = ListScreenQueryContext::fromRequest();
		unset($context['m']);

		foreach ( $context as $name => $value ) {
			printf('<input type="hidden" name="%s" value="%s" />', esc_attr($name), esc_attr($value));
		}
	}
}
