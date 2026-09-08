<?php

declare(strict_types=1);

namespace Plathix\Modules\DataWipe;

use Plathix\Contracts\ModuleInterface;

final class Module implements ModuleInterface
{
	private readonly DangerZoneTab $tab;
	private readonly DataWipeAjax $ajax;

	public function __construct(?DangerZoneTab $tab = null, ?DataWipeAjax $ajax = null) {
		$this->tab  = $tab ?? new DangerZoneTab();
		$this->ajax = $ajax ?? new DataWipeAjax();
	}

	public function register(): void {
		add_action( 'plathix/modules/boot', [ $this, 'boot' ] );
	}

	public function boot(): void {
		add_filter( 'plathix/admin/settings_tabs', [ $this, 'addTab' ], PHP_INT_MAX );

		add_action( 'wp_ajax_' . DangerZoneTab::WIPE_ACTION, [ $this->ajax, 'handle' ] );
	}

	/**
	 * @param array<int, array{slug:string,label:string,render:callable}> $tabs
	 * @return array<int, array{slug:string,label:string,render:callable}>
	 */

	public function addTab(array $tabs): array {
		$tabs[] = [
			'slug'   => DangerZoneTab::TAB,
			'label'  => __( 'Danger Zone', 'plathix' ),
			'render' => [ $this->tab, 'render' ],
		];

		return $tabs;
	}
}
