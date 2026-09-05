<?php

declare(strict_types=1);

namespace Plathix\Modules\FreeFirstRun;

use Plathix\Contracts\ModuleInterface;
use Plathix\Infrastructure\Keys;

/**
 * Модуль Free first-run визарда (picker-overlay первого запуска) — [internal] /
 * [internal].
 *
 * Автономный feature-модуль: ядро от него не зависит (выключи → плагин работает, first-run
 * не показывается). Ранее визард висел осколком в {@see \Plathix\Modules\Preset\Module::boot()}
 * и регистрировался безусловно на каждый admin-хит. Вынесен в свой модуль по образцу большого
 * PRO-визарда ({@see \Plathix\Modules\Onboarding} в PRO).
 *
 * Состоит из: overlay-picker {@see FreeWizard} (подписан на Dashboard-хук
 * `plathix/onboarding/render_modal`) и обработчиков {@see WizardController} (skip/reset).
 * Состояние онбординга — {@see \Plathix\Modules\Preset\PresetOnboarding} (остаётся в Preset:
 * PRO держит на него `use` как инвариант B1, перенос сломал бы PRO).
 *
 * Прецедент двухфазного модуля: {@see \Plathix\Modules\Preset\Module}.
 */
final class Module implements ModuleInterface
{
	private readonly WizardController $controller;

	public function __construct(?WizardController $controller = null) {
		$this->controller = $controller ?? new WizardController();
	}

	/**
	 * Фаза 1: только подписка на фазу 2 (двухфазный bootstrap, module-standard свойство 3).
	 */
	public function register(): void {
		add_action( 'plathix/modules/boot', [ $this, 'boot' ] );
	}

	/**
	 * Фаза 2: runtime-хуки под admin-контекстом.
	 *
	 * Разведение контуров ([internal]):
	 * - reset-инструмент (reset-хендлер + notice + кнопка на странице Пресетов) — ALWAYS-ON:
	 *   администратор должен мочь сбросить визард, даже когда онбординг уже пройден.
	 * - showing-wiring (skip-хендлер + подписка рендера на render_modal) — регистрируется
	 *   тоже здесь; сам показ гейтится на уровне {@see FreeWizard::render_hook} (is_pro +
	 *   should_show_wizard), а не на уровне регистрации хука (регистрация admin_post дёшева).
	 */
	public function boot(): void {
		if ( ! is_admin() ) {
			return;
		}

		$this->controller->register();

		// [internal]: PresetsPage больше не создаёт WizardController напрямую по FQCN —
		// рендер кнопки идёт через слот, тот же паттерн, что plathix/tools/cards (ApiKey/Import).
		add_action( 'plathix/preset/reset_wizard_button', [ $this->controller, 'render_reset_wizard_button' ] );

		// CSS визарда co-located в модуле ([internal], #113): FreeFirstRun грузит
		// свой free-wizard.css только на Dashboard, а не через общий admin-ui.css на всех страницах.
		( new WizardAssets() )->register();

		// Free first-run overlay на Dashboard-хук. Статический callback = стабильный адрес.
		// Вытеснение при активном PRO — на стороне Free (FreeWizard::render_hook сам выходит
		// по Edition::is_pro()); PRO не ссылается на этот класс ([internal] форма Б).
		add_action( 'plathix/onboarding/render_modal', [ FreeWizard::class, 'render_hook' ], FreeWizard::HOOK_PRIORITY );

		// First-run: редирект на главную плагина при первом входе после активации.
		add_action( 'admin_init', [ $this, 'maybe_redirect_after_activation' ] );
	}

	/**
	 * One-shot редирект на главную плагина после активации ([internal], [internal]).
	 *
	 * Флаг ставит {@see \Plathix\Activator::run()} только в single-site ветке (не при
	 * network-activation). Здесь — одноразово: transient удаляется до редиректа, поэтому даже
	 * при повторном admin_init в том же запросе второго редиректа не будет. Ведёт на главную
	 * плагина (фильтр `plathix/admin/root_slug`), где всплывёт first-run модалка.
	 *
	 * Guard'ы: только полноценный admin-запрос (не AJAX/cron), только для manage_options,
	 * не в network-admin (там своя активация без per-site редиректа).
	 */
	public function maybe_redirect_after_activation(): void {
		$transient_key = Keys::transient( 'activation_redirect' );

		if ( ! get_transient( $transient_key ) ) {
			return;
		}

		if ( wp_doing_ajax() || wp_doing_cron() || is_network_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Удаляем ДО редиректа — гарантия одноразовости (редирект случится ровно один раз).
		delete_transient( $transient_key );

		wp_safe_redirect( admin_url( 'admin.php?page=' . (string) apply_filters( 'plathix/admin/root_slug', 'plathix' ) ) );
		exit;
	}
}
