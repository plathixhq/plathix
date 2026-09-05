<?php

declare(strict_types=1);

/**
 * Plathix Admin Layout Partial
 *
 * @var string                                                                       $plathix_current_page  slug of the active nav item
 * @var array{main: array<int, array<string, mixed>>, footer: array<int, array<string, mixed>>} $plathix_nav_sections  секции сайдбара из реестра
 *
 * PluginCheck PrefixAllGlobals WARNING на $item/$slug/$is_active/$classes ниже — false positive
 * ([internal], [internal]): этот файл подключается только через require ВНУТРИ метода
 * Plathix\Core\AdminLayout::open(), значит переменные наследуют method-scope, а не глобальный
 * WP namespace. Коллизия имён с другим плагином физически невозможна.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$plathix_current_page = $plathix_current_page ?? '';
// Пункты сайдбара приходят из реестра plathix/admin/menu_pages (собраны AdminLayoutNav::sections()
// в AdminLayout::open()). Хардкод-массивы удалены ([internal]): единственный
// источник правды — дескриптор страницы. Пункт Audit объявляет только PRO-модуль, во Free его нет.
$plathix_nav_sections = $plathix_nav_sections ?? [ 'main' => [], 'footer' => [] ];
$plathix_main_items   = $plathix_nav_sections['main'] ?? [];
$plathix_footer_items = $plathix_nav_sections['footer'] ?? [];
?>
<div class="plathix-layout">
	<?php
	// Pre-paint установка rail-состояния ([internal]). Синхронный инлайн ДО рендера
	// .plathix-sidebar (он ниже в разметке): если в localStorage сохранён свёрнутый rail — ставим
	// .is-rail на .plathix-layout СРАЗУ, чтобы сайдбар отрендерился 56px с первого кадра. Без этого
	// класс ставил футер-admin-ui.js поздно → сайдбар ~200мс держал 240px и скакал в 56.
	// Статичный литерал (без PHP-интерполяции), ключ хардкод — XSS-поверхности нет.
	// WP.org review round 1 ([internal]): wp_add_inline_script()+wp_print_scripts() вместо
	// голого echo '<script>' — печатает синхронно, в этой же точке потока, timing не меняется
	// (см. Plathix\Admin\Assets::print_inline_script_now()). document.currentScript продолжает
	// работать: WP печатает обычный синхронный inline <script> тег, без defer/async/module.
	\Plathix\Admin\Assets::print_inline_script_now(
		'plathix-rail-inline',
		"try{var pl=document.currentScript.closest('.plathix-layout');if(pl&&localStorage.getItem('plathix_admin_rail')==='1'){pl.classList.add('is-rail');}}catch(e){}"
	);
	?>
	<?php
	// Кнопка-тумблер rail ([internal]). ВАРИАНТ C: прямой ребёнок .plathix-layout, НЕ внутри
	// <nav class="plathix-sidebar"> — у сайдбара overflow-y:auto создаёт скролл-контекст, который
	// клипует торчащую наружу кнопку по X (обрезается в rail). Позиционируется по left = ширине
	// сайдбара с transition, поэтому плавно едет на границу при сворачивании. Состояние (класс
	// .is-rail на .plathix-layout) ставит admin-ui.js из localStorage.
	?>
	<button type="button" class="plathix-rail__toggle" aria-expanded="true"
		aria-label="<?php esc_attr_e( 'Collapse navigation', 'plathix' ); ?>"
		title="<?php esc_attr_e( 'Collapse navigation', 'plathix' ); ?>">
		<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
	</button>
	<nav class="plathix-sidebar" aria-label="<?php esc_attr_e( 'Plathix navigation', 'plathix' ); ?>">

		<div class="plathix-logo__area">
			<div class="plathix-logo__mark"><svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 26.458333 26.458333" version="1.1" id="svg1" aria-hidden="true"><defs id="defs1"/><g id="layer1" transform="translate(-6980.7221,-1449.3837)"><g id="g211"><rect style="color:black;display:inline;overflow:visible;visibility:visible;fill:#0d9488;fill-opacity:1;fill-rule:evenodd;stroke:none;stroke-width:0.264583px;stroke-linecap:butt;stroke-linejoin:miter;stroke-opacity:1;marker:none;enable-background:accumulate" id="rect136" width="26.458334" height="26.458334" x="6980.7222" y="1449.3838" rx="0" ry="0"/><path id="path210" style="baseline-shift:baseline;display:inline;overflow:visible;vector-effect:none;fill:white;fill-opacity:1;fill-rule:evenodd;stroke-width:0.999998;stroke-linecap:round;stroke-linejoin:round;marker:none;enable-background:accumulate;stop-color:black" d="m 6988.47,1453.8807 c -1.0276,0 -1.8547,0.8273 -1.8547,1.8547 v 13.4038 c 0,0.4809 0.231,1.1624 0.8671,1.3168 l 6.3883,1.9456 c 0.985,0.3039 1.9115,-0.1456 1.9115,-1.4242 v -5.4927 l 2.451,-3.1915 c 0.092,-0.119 0.1442,-0.2671 0.1442,-0.4191 v -4.6737 c 0,-0.4769 -0.2203,-0.9176 -0.5287,-1.2025 -0.3087,-0.2847 -0.6865,-0.4421 -1.0723,-0.5152 l -1.1239,-0.2134 h 3.8711 c 0.2226,0 0.3803,0.1565 0.3803,0.3777 v 13.9346 c 0,0.2221 -0.1568,0.3798 -0.3803,0.3798 h -1.9736 c -0.383,2e-4 -0.6919,0.3088 -0.6919,0.692 0,0.3833 0.3088,0.6919 0.6919,0.6919 h 1.8821 c 1.0274,0 1.8547,-0.8273 1.8547,-1.8547 v -13.7552 c 0,-1.0275 -0.8273,-1.8547 -1.8547,-1.8547 z m -0.042,1.539 c 0.014,-2e-4 0.028,6e-4 0.042,0 l 7.8713,1.388 c 0.3692,0.064 0.6548,0.2981 0.6548,0.6847 v 4.147 l -2.4516,3.189 c -0.092,0.1217 -0.1421,0.2691 -0.1421,0.4217 v 5.625 c 0.014,0.2955 -0.1388,0.3738 -0.4129,0.3074 l -5.4627,-1.6639 c -0.2269,-0.062 -0.5312,-0.2856 -0.5312,-0.4775 v -13.1346 c 0,-0.1945 0.2174,-0.4873 0.432,-0.4899 z"/></g></g></svg></div>
			<span class="plathix-logo__text">Plathix</span>
		</div>

		<div class="plathix-nav__separator"></div>

		<?php
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template partial, require()'d only inside AdminLayout::open() method scope, not a real global ([internal], [internal])
		foreach ( $plathix_main_items as $item ) :
			$slug      = (string) ( $item['slug'] ?? '' );
			$is_active = $plathix_current_page === $slug;
			$classes   = 'plathix-nav__item';
			if ( ! empty( $item['is_pro'] ) ) {
				$classes .= ' pro-nav';
			}
			if ( $is_active ) {
				$classes .= ' active';
			}
			?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $slug ) ); ?>"
			   class="<?php echo esc_attr( $classes ); ?>"
			   data-plathix-label="<?php echo esc_attr( (string) ( $item['label'] ?? '' ) ); ?>"
			   <?php echo $is_active ? 'aria-current="page"' : ''; ?>>
				<span class="plathix-nav__icon"><?php echo \Plathix\Helpers\Sanitize::icon_markup( (string) ( $item['icon'] ?? '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Sanitize::icon_markup() wraps wp_kses() with an explicit SVG-tag allowlist (src/Helpers/Sanitize.php); phpcs cannot see through the method call, but the value is escaped, not passed through raw ?></span>
				<span class="plathix-nav__label-txt"><?php echo esc_html( (string) ( $item['label'] ?? '' ) ); ?></span>
				<?php if ( ! empty( $item['badge'] ) ) : ?>
					<span class="plathix-nav__badge"><?php echo esc_html( (string) $item['badge'] ); ?></span>
				<?php endif; ?>
			</a>
			<?php
		endforeach;
		// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
		?>

		<?php if ( ! empty( $plathix_footer_items ) ) : ?>
		<div class="plathix-sidebar__footer">
			<div class="plathix-nav__separator"></div>
			<div class="plathix-nav__label"><?php esc_html_e( 'System', 'plathix' ); ?></div>
			<?php
			// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template partial, require()'d only inside AdminLayout::open() method scope, not a real global ([internal], [internal])
			foreach ( $plathix_footer_items as $item ) :
				$slug      = (string) ( $item['slug'] ?? '' );
				$is_active = $plathix_current_page === $slug;
				$classes   = 'plathix-nav__item';
				if ( $is_active ) {
					$classes .= ' active';
				}
				?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $slug ) ); ?>"
				   class="<?php echo esc_attr( $classes ); ?>"
				   data-plathix-label="<?php echo esc_attr( (string) ( $item['label'] ?? '' ) ); ?>"
				   <?php echo $is_active ? 'aria-current="page"' : ''; ?>>
					<span class="plathix-nav__icon"><?php echo \Plathix\Helpers\Sanitize::icon_markup( (string) ( $item['icon'] ?? '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Sanitize::icon_markup() wraps wp_kses() with an explicit SVG-tag allowlist (src/Helpers/Sanitize.php); phpcs cannot see through the method call, but the value is escaped, not passed through raw ?></span>
					<span class="plathix-nav__label-txt"><?php echo esc_html( (string) ( $item['label'] ?? '' ) ); ?></span>
				</a>
				<?php
			endforeach;
			// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
			?>
		</div>
		<?php endif; ?>

	</nav>
	<div class="plathix-main">
