<?php

declare(strict_types=1);

namespace Plathix\Modules\DataWipe;

use Plathix\Http\AjaxGuard;
use Plathix\User\AccessLevel;

/**
 * AJAX-хендлер полной очистки данных плагина ([internal] / migration-loop DataWipe T2).
 *
 * Вынесено из {@see \Plathix\Http\AjaxRouter}: destructive-роут отключаемой фичи не должен
 * висеть в платформенном роутере (иначе при отключённом модуле эндпоинт слушает без UI —
 * призрак). Модуль вешает свой `wp_ajax_` через {@see Module::boot}.
 *
 * PRO чистит свои данные на хук `plathix/data_wipe/cleanup` (эмит здесь). Движок {@see DataWiper}
 * пока в `src/Cleanup/` (target T3), зовётся через seam {@see self::wipe_free_data()}.
 *
 * НЕ `final`: protected seam `wipe_free_data` перекрывается subclass-шпионом в тесте (как
 * {@see \Plathix\Http\AjaxRouter}), чтобы контракт хендлера проверялся без дубля $wpdb-стаба.
 */
class DataWipeAjax
{
	/**
	 * Обрабатывает AJAX-запрос полной очистки: авторизация → снос Free-данных → эмит хука для
	 * PRO → success. При отказе авторизации завершает запрос 403 (wp_send_json_error → die).
	 */
	public function handle(): void {
		// Авторизация (nonce + Full + manage_options) — внутри assert_authorized → AjaxGuard::require.
		// Nonce больше НЕ дублируется здесь: он первой строкой в require (порядок nonce→авторизация цел).
		$this->assert_authorized();

		$blog_id = get_current_blog_id();

		// Free-данные: единый движок очистки (тот же, что процедурно повторяет uninstall.php).
		// Вынесено в seam wipe_free_data() — тестируемо без дублирования $wpdb-стаба DataWiper.
		$this->wipe_free_data( $blog_id );

		// PRO чистит свои данные (audit-таблица, license/cron) на этот хук — классы PRO в
		// runtime загружены, поэтому подписчик услышит (в отличие от uninstall.php, где хук
		// выстрелил бы в пустоту). $blog_id для корректного multisite-контекста.
		do_action( 'plathix/data_wipe/cleanup', $blog_id );

		wp_send_json_success( [ 'wiped' => true ] );
	}

	/**
	 * Прицельная авторизация именно для data-wipe — тонкая обёртка над общим
	 * Plathix\Http\AjaxGuard::require ([internal] #135; ранее — своя копия).
	 *
	 * AccessLevel::Full ОБЯЗАТЕЛЕН — manage_options есть у admin, но уровень гейтит по
	 * plathix_role_access (админа могли понизить); терять Full = регресс. Post-type-гейт из
	 * require намеренно НЕ включён: `$post_type = null` → enabled-гейт и cap-резолв пропущены
	 * (данные плагина не привязаны к post_type). Cap передаётся явным 'manage_options'.
	 *
	 * Seam (protected): subclass-шпион в тесте контролирует исход авторизации без графа
	 * AccessResolver; сама Full-логика покрыта AjaxGuardTest/AjaxRouterGuardTest.
	 */
	protected function assert_authorized(): void {
		AjaxGuard::require( AccessLevel::Full, 'manage_options', null );
	}

	/**
	 * Снос Free-данных через единый движок. Seam: отделяет оркестрацию AJAX от движка очистки,
	 * чтобы тест хендлера проверял контракт (вызов очистки + эмит хука), не дублируя $wpdb-стаб
	 * DataWiperTest (сам снос доказан там).
	 */
	protected function wipe_free_data(int $blog_id): void {
		( new DataWiper() )->wipe( $blog_id );
	}
}
