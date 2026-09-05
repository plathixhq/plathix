<?php

declare(strict_types=1);

namespace Plathix\Modules\Svg;

use Plathix\Contracts\ModuleInterface;

/**
 * Тонкая модульная обёртка SVG-фичи.
 *
 * Feature-движок `SvgSupport` физически в `src/Modules/Svg/`, namespace
 * `Plathix\Modules\Svg\` ([internal], 2026-07-02 — tolerated:namespace снят):
 * модуль выносим в PRO. Санитайзер `Plathix\Svg\Sanitizer\Sanitizer` НАМЕРЕННО остаётся
 * платформой во Free (его напрямую зовёт Free-модуль Replace при замене SVG — вынос
 * SVG-модуля не должен ломать Free-санацию).
 *
 * Прецедент: Modules\Cli\Module (тот же чистый relocate).
 */
final class Module implements ModuleInterface
{
	/**
	 * Фаза 1: только подписка на фазу 2. Runtime-фильтры WP здесь не вешаются.
	 * Подписка на `plathix/modules/boot` — внутренняя регистрация на фазу bootstrap.
	 */
	public function register(): void
	{
		add_action( 'plathix/modules/boot', [ $this, 'boot' ] );
	}

	/**
	 * Фаза 2: ветвление по трёхзначной SVG-политике `plathix_svg_policy` ([internal]):
	 *  - `sanitize` — инстанцирует SvgSupport и навешивает его 4 фильтра (upload_mimes /
	 *    wp_check_filetype_and_ext / wp_handle_upload_prefilter / wp_get_attachment_image_src)
	 *    через register() (путь без Loader); svg разрешён и очищается на загрузке.
	 *  - `block` — вешает поздний (PHP_INT_MAX) upload_mimes-фильтр, вычищающий svg/svgz,
	 *    перебивая сторонние плагины (Element Pack и т.п.), добавившие svg сами.
	 *  - `ignore` — Plathix не трогает upload_mimes вовсе (svg отдан другим плагинам).
	 * boot стреляет до того, как WP дёргает эти фильтры (момент загрузки файла).
	 */
	public function boot(): void
	{
		// Настройки SVG регистрируются БЕЗУСЛОВНО ([internal]): таб и опции
		// должны существовать всегда, чтобы политику можно было менять. Подписка на extension
		// points страницы Settings (plathix/settings/register на admin_init + settings_tabs filter).
		( new SvgSettings() )->register();

		switch ( SvgSettings::current_policy() ) {
			case SvgSettings::POLICY_SANITIZE:
				( new SvgSupport() )->register();
				break;
			case SvgSettings::POLICY_BLOCK:
				add_filter( 'upload_mimes', [ $this, 'block_svg_mimes' ], PHP_INT_MAX );
				break;
			case SvgSettings::POLICY_IGNORE:
				// Намеренно ничего: Plathix не управляет svg-mime.
				break;
		}
	}

	/**
	 * Убирает svg/svgz из разрешённых upload MIME (политика `block`, [internal]). Вешается на
	 * upload_mimes с приоритетом PHP_INT_MAX, чтобы отработать после сторонних плагинов,
	 * добавивших svg. НЕ хардкодит источник — вычищает КЛЮЧ независимо от того, кто его добавил.
	 *
	 * @param array<string,string> $mimes
	 * @return array<string,string>
	 */
	public function block_svg_mimes(array $mimes): array
	{
		unset( $mimes['svg'], $mimes['svgz'] );

		return $mimes;
	}
}
