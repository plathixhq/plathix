<?php

declare(strict_types=1);

namespace Plathix\Modules\DataWipe;

use Plathix\Contracts\ModuleInterface;

/**
 * Модуль полной очистки данных плагина (Danger Zone) — [internal] / migration-loop DataWipe.
 *
 * Автономный feature-модуль: ядро от него не зависит (Test 1 отключаемости пройден). Владеет
 * UI-табом Danger Zone на странице Settings. Регистрирует свой таб через штатный extension point
 * страницы (`plathix/admin/settings_tabs`), а не хардкодом в SettingsView.
 *
 * Компонент собран целиком в этом модуле: UI-таб {@see DangerZoneTab}, AJAX-хендлер
 * {@see DataWipeAjax} (роут `plathix_delete_all_data`), движок {@see DataWiper}. Ядро о нём
 * не знает.
 *
 * Прецедент двухфазного модуля с settings-табом: {@see \Plathix\Modules\Svg\Module}.
 */
final class Module implements ModuleInterface
{
	private readonly DangerZoneTab $tab;
	private readonly DataWipeAjax $ajax;

	public function __construct(?DangerZoneTab $tab = null, ?DataWipeAjax $ajax = null) {
		$this->tab  = $tab ?? new DangerZoneTab();
		$this->ajax = $ajax ?? new DataWipeAjax();
	}

	/**
	 * Фаза 1: только подписка на фазу 2. Runtime-фильтры WP здесь не вешаются
	 * (двухфазный bootstrap, module-standard свойство 3).
	 */
	public function register(): void {
		add_action( 'plathix/modules/boot', [ $this, 'boot' ] );
	}

	/**
	 * Фаза 2: подписка на реестр табов Settings. Приоритет PHP_INT_MAX + добавление в ХВОСТ →
	 * Danger Zone гарантированно ПОСЛЕДНИЙ таб, add-on с обычным приоритетом его не вытеснит.
	 * Это Free-инвариант «полная очистка всегда доступна и визуально отделена», воспроизведённый
	 * штатным механизмом фильтра (паттерн позднего приоритета — как Svg block_svg_mimes).
	 */
	public function boot(): void {
		add_filter( 'plathix/admin/settings_tabs', [ $this, 'add_tab' ], PHP_INT_MAX );

		// AJAX-роут полной очистки — модуль владеет им сам ([internal] / T2): платформенный
		// AjaxRouter его больше не хардкодит. Action-строка — единый источник DangerZoneTab::WIPE_ACTION.
		add_action( 'wp_ajax_' . DangerZoneTab::WIPE_ACTION, [ $this->ajax, 'handle' ] );
	}

	/**
	 * Дописывает дескриптор таба Danger Zone в хвост реестра.
	 *
	 * @param array<int, array{slug:string,label:string,render:callable}> $tabs
	 * @return array<int, array{slug:string,label:string,render:callable}>
	 */
	public function add_tab(array $tabs): array {
		$tabs[] = [
			'slug'   => DangerZoneTab::TAB,
			'label'  => __( 'Danger Zone', 'plathix' ),
			'render' => [ $this->tab, 'render' ],
		];

		return $tabs;
	}
}
