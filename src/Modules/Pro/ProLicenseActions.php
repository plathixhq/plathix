<?php

declare(strict_types=1);

namespace Plathix\Modules\Pro;

use Plathix\Edition;
use Plathix\Infrastructure\Keys;

/**
 * Admin-post обработка лицензии (активация / деактивация) — вынесена из ProPage
 * ([internal] #103). Отдельная ось: серверная обработка POST-форм лицензии,
 * тогда как ProPage остаётся page-view (рендер).
 *
 * Cross-package контракт (Free триггерит — PRO пишет статус): do_action
 * `plathix/license/activate|deactivate` слушает PlathixPro\Modules\License\Module.
 * Free сам статус 'active' НЕ пишет — только PRO после ответа сервера (LVR-103).
 *
 * Образец выноса — Modules\Preset\PresetPostActions (#97): handlers домена в класс
 * модуля-домена, регистрация admin_post через Module::boot().
 */
final class ProLicenseActions
{
	/** Фаза 2 (runtime под is_admin): 2 admin-post обработчика лицензии. */
	public function register(): void {
		add_action( 'admin_post_plathix_activate_license', [ $this, 'handle_activate' ] );
		add_action( 'admin_post_plathix_deactivate_license', [ $this, 'handle_deactivate' ] );
	}

	/** Обработка активации: валидация формата → запись ключа → триггер PRO-подписчику → редирект. */
	public function handle_activate(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'plathix' ) );
		}

		check_admin_referer( 'plathix_activate_license' );

		$redirect = admin_url( 'admin.php?page=' . ProPage::PAGE_SLUG );
		$key      = sanitize_text_field( (string) wp_unslash( $_POST['plathix_license_key'] ?? '' ) );

		if ( ! LicensePolicy::is_valid_key_format( $key ) ) {
			wp_safe_redirect( add_query_arg( 'plathix_license', 'error', $redirect ) );
			exit;
		}

		// Ключ сохраняем во Free (форма — одна страница для Free и PRO, решение группы).
		// Саму верификацию делает PRO-подписчик: страница/форма/is_pro() здесь, сеть к
		// license-серверу — в PRO (do_action ниже). Free сам статус 'active' НЕ пишет —
		// его пишет только PRO после реального ответа сервера (LVR-103).
		// autoload=false: license_key — секрет-подобный операционный креденшл, не публичная
		// конфигурация. Не должен попадать в always-loaded alloptions (грузиться на каждом
		// запросе) — только в license-контуре через явный get_option ([internal] #1).
		update_option( Edition::KEY_OPTION, $key, false );
		delete_option( Edition::STATUS_OPTION );
		delete_transient( Keys::license_error() );

		/**
		 * Триггер активации лицензии. Без PRO — no-op (нет подписчиков): статус останется
		 * пустым, и мы отличим «PRO нет» от «PRO есть, ключ невалиден» по итоговому статусу
		 * и коду ошибки, который PRO кладёт в транзиент plathix_license_last_error.
		 *
		 * @param string $key Провалидированный по формату лицензионный ключ.
		 */
		do_action( 'plathix/license/activate', $key );

		$status = (string) get_option( Edition::STATUS_OPTION, '' );
		wp_safe_redirect( add_query_arg( 'plathix_license', self::activation_notice( $status ), $redirect ) );
		exit;
	}

	/**
	 * Маппинг статуса после активации в notice-код редиректа. Часть actions-протокола
	 * (не license-state): единственный не-тестовый потребитель — handle_activate.
	 *
	 * @param string $status Итоговый plathix_license_status после do_action.
	 */
	public static function activation_notice(string $status): string {
		if ( 'active' === $status ) {
			return 'activated';
		}

		// PRO есть и ответил «невалиден» / сеть легла → last_error выставлен подписчиком.
		// PRO нет → транзиента нет (do_action был no-op) → тоже трактуем как ошибку активации;
		// текст на странице уже общий («не найден или не удалось проверить»).
		return 'error';
	}

	/** Обработка деактивации: триггер PRO-подписчику → безусловное локальное гашение → редирект. */
	public function handle_deactivate(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'plathix' ) );
		}

		check_admin_referer( 'plathix_deactivate_license' );

		$key = (string) get_option( Edition::KEY_OPTION, '' );

		/**
		 * Триггер деактивации: PRO снимает активацию на license-сервере (по сохранённому
		 * instance). Без PRO — no-op. Ключ передаём, чтобы подписчик знал, что деактивировать.
		 *
		 * @param string $key Текущий лицензионный ключ (может быть пустым).
		 */
		do_action( 'plathix/license/deactivate', $key );

		// Локальное гашение делаем безусловно во Free: даже если сеть к серверу легла,
		// PRO-фичи на этом сайте должны выключиться немедленно (статус — источник gating).
		delete_option( Edition::KEY_OPTION );
		delete_option( Edition::STATUS_OPTION );
		delete_option( Edition::EXPIRES_OPTION );
		delete_transient( Keys::license_error() );

		$redirect = admin_url( 'admin.php?page=' . ProPage::PAGE_SLUG );
		wp_safe_redirect( add_query_arg( 'plathix_license', 'deactivated', $redirect ) );
		exit;
	}
}
