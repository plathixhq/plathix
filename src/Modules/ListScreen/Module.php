<?php

declare(strict_types=1);

namespace Plathix\Modules\ListScreen;

use Plathix\Contracts\ModuleInterface;
use Plathix\Core\FolderRepository;

/**
 * ListScreen module — интеграция папок Plathix в нативные таблицы-списки WordPress
 * (`upload.php` вид списком; CTAN-201 — CPT-экраны обслуживает PRO). Стороны фичи:
 * - FolderColumn — колонка «Папка» в таблице (manage_*_columns) + ссылка-фильтр;
 * - ListScreenFragmentsController — AJAX `plathix_list_screen`, live-перерисовка фрагментов
 *   таблицы при выборе папки в сайдбаре, без перезагрузки страницы;
 * - SearchSortFields — hidden orderby/order поля в форме поиска ([internal]), чтобы сабмит
 *   поиска не сбрасывал выбранную сортировку колонки.
 * - maybe_redirect_legacy_trash_status — one-shot admin_init редирект legacy
 *   `?status=trash` ([internal], [internal]): корзину включает
 *   только `attachment-filter=trash` (WP-ядровой ключ, WP_Media_List_Table::$is_trash),
 *   `status` был нашим отменённым JS-детект-ключом ([internal]).
 *
 * Регистрируется из plathix.php под `plathix/modules/register`; ранее оба класса
 * бутстрапились монолитом Modules\Admin\Module.
 *
 * Двухфазный bootstrap (module-standard.md свойство 3): register() в фазе 1 ТОЛЬКО
 * подписывается на `plathix/modules/boot`; runtime-хуки WP (column-фильтры + wp_ajax)
 * навешиваются в boot() под is_admin() — фаза 1 запрещает вешать runtime-хуки WP (:109).
 * boot() — composition root (P4): FolderRepository создаётся и передаётся в конструктор
 * Controller, а не внутри его методов.
 *
 * FolderColumn перенесён в этот namespace пакетом [internal]
 * (2026-07-22) — namespace-долг из [internal] закрыт.
 */
class Module implements ModuleInterface
{
	/** Фаза 1: только подписка на фазу 2. */
	public function register(): void
	{
		add_action( 'plathix/modules/boot', [ $this, 'boot' ] );
	}

	/** Фаза 2: навешивание runtime admin-хуков (колонка + AJAX live-фильтр + legacy redirect). */
	public function boot(): void
	{
		if ( is_admin() ) {
			( new FolderColumn() )->register();
			( new ListScreenFragmentsController( new FolderRepository() ) )->register();
			( new SearchSortFields() )->register();
			add_action( 'admin_init', [ $this, 'maybe_redirect_legacy_trash_status' ] );
		}
	}

	/**
	 * Редиректит legacy `upload.php?status=trash` (без `attachment-filter`) на
	 * канонический `attachment-filter=trash` — единственный ключ, по которому WP-ядро
	 * реально включает корзину (`WP_Media_List_Table::$is_trash` читает
	 * `$_REQUEST['attachment-filter']`, class-wp-media-list-table.php:124). `status`
	 * был нашим внутренним JS-детект-ключом, убранным из генераторов пакетом
	 * [internal] — этот хук только читает legacy-алиас на входе, не
	 * восстанавливает его запись ([internal]).
	 *
	 * Тонкий wrapper над resolve_legacy_trash_redirect_target(): вся guard-логика
	 * вынесена в чистую функцию без side-effects, чтобы её можно было доказать unit-
	 * тестом без перехвата реального `exit` — в PHP `exit` физически нельзя перехватить
	 * unit-тестом; проект уже принял этот же split (behavioural logic / непроверяемый
	 * exit-wrapper, доказанный stand-smoke) в [internal] для аналогичной
	 * проблемы (`DownloadController::handle()`).
	 */
	public function maybe_redirect_legacy_trash_status(): void
	{
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only nav params, no state mutation, only redirect target detection
		$target_url = self::resolve_legacy_trash_redirect_target(
			(string) ( $GLOBALS['pagenow'] ?? '' ),
			wp_doing_ajax() || wp_doing_cron(),
			sanitize_key( (string) wp_unslash( $_GET['status'] ?? '' ) ),
			! empty( $_GET['attachment-filter'] ),
			(string) wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- passed through add_query_arg/remove_query_arg (WP core) before use, same pattern as core's own default REQUEST_URI usage
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( null === $target_url ) {
			return;
		}

		wp_safe_redirect( $target_url );
		exit;
	}

	/**
	 * Чистая guard-логика для maybe_redirect_legacy_trash_status(): возвращает
	 * целевой redirect URL или null, если редирект не нужен. Принимает `$request_uri`
	 * явным параметром вместо неявного чтения `$_SERVER['REQUEST_URI']` внутри —
	 * иначе функция была бы "чистой" только по сигнатуре, реально завися от глобального
	 * состояния. Не вызывает `wp_safe_redirect()`/`exit` — только решает, нужен ли
	 * редирект, поэтому unit-тестируема прямым вызовом без side-effects (WP Senior Dev
	 * skeptic pass при паковке).
	 *
	 * Ограничена `upload.php` — CPT-экраны обслуживает PRO-модуль (CTAN-201); прежний второй экран
	 * не имеет отношения к media-корзине (explicit non-goal, WP Senior Dev skeptic pass).
	 */
	public static function resolve_legacy_trash_redirect_target(
		string $pagenow,
		bool $is_ajax_or_cron,
		string $status_param,
		bool $has_attachment_filter,
		string $request_uri
	): ?string {
		if ( 'upload.php' !== $pagenow ) {
			return null;
		}

		if ( $is_ajax_or_cron ) {
			return null;
		}

		if ( 'trash' !== $status_param ) {
			return null;
		}

		if ( $has_attachment_filter ) {
			return null;
		}

		return add_query_arg( 'attachment-filter', 'trash', remove_query_arg( 'status', $request_uri ) );
	}
}
