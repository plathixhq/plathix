<?php

declare(strict_types=1);

namespace Plathix\Modules\Settings;

use Plathix\Core\AdminLayout;
use Plathix\Core\FolderCountService;
use Plathix\Core\FolderRepository;
use Plathix\Core\HiddenFolders;
use Plathix\Core\TrashFolder;
use Plathix\Infrastructure\Cache;
use Plathix\PublicApi\DataWipeApi;
use Plathix\User\AccessResolver;

/**
 * Представление страницы Settings.
 *
 * Рендерит host-таб General; Access (PRO), SVG, Danger Zone добавляются через модули.
 * Использует новую дизайн-систему Plathix (plathix-card, plathix-field, plathix-table и т.д.).
 */
class SettingsView
{
	private CronHealthService $cron_health_service;

	public function __construct(?CronHealthService $cron_health_service = null) {
		$this->cron_health_service = $cron_health_service ?? new CronHealthService();
	}

	/**
	 * Дескрипторы вкладок настроек.
	 *
	 * Хост объявляет свои платформенные табы (General/Access/Advanced); модули добавляют свои
	 * через фильтр `plathix/admin/settings_tabs` ([internal]). Каждый дескриптор:
	 *   slug    string   — ключ таба (?tab=slug)
	 *   label   string   — подпись вкладки
	 *   render  callable  — рендер КОНТЕНТА таба (без формы-обёртки; обёртку рендерит хост)
	 *
	 * @return array<int, array{slug:string,label:string,render:callable}>
	 */
	private function tabs(): array {
		$host = [
			[ 'slug' => 'general',  'label' => __( 'General', 'plathix' ),  'render' => [ $this, 'render_tab_general' ] ],
			// Таб Access убран из Free ([internal], [internal]): per-role UI никогда
			// не был рабочим здесь (register_setting отсутствовал), теперь PRO-модуль
			// plathixPro\Modules\Access\AccessSettings регистрирует рабочий таб 'access' сам,
			// через тот же фильтр plathix/admin/settings_tabs (паттерн Modules\Svg\SvgSettings).
			// Таб Advanced удалён ([internal]): Bulk/QuickEdit переехали в General.
			// SVG-таб приходит из Modules\Svg через фильтр plathix/admin/settings_tabs ([internal]).
			// Danger Zone приходит из Modules\DataWipe через тот же фильтр поздним приоритетом
			// (PHP_INT_MAX → всегда последним, add-on не вытеснит; [internal] / migration-loop DataWipe T1).
		];

		/**
		 * Фильтр-реестр табов страницы Settings.
		 * Модуль добавляет дескриптор своего таба (slug/label/render-контент).
		 *
		 * @param array<int, array{slug:string,label:string,render:callable}> $tabs
		 */
		$tabs = apply_filters( 'plathix/admin/settings_tabs', $host );
		$tabs = is_array( $tabs ) ? array_values( $tabs ) : $host;

		return $tabs;
	}

	/**
	 * Реестр секций вкладки General ([internal]). Уровень «ниже страницы»,
	 * по образцу `plathix/dashboard/widgets` (HomeDashboardPage): хост объявляет свои секции
	 * дескрипторами, модули добавляют/перекрывают свои через фильтр, хост-итератор вызывает
	 * render() каждой финальной секции внутри формы General.
	 *
	 * Зачем: PRO-модуль ContentTypes перекрывает Free-заглушку «Enabled Sections» рабочими
	 * чекбоксами выбора типов ПРЯМО в «Общих», вместо отдельной вкладки «Content Types». Free
	 * без PRO рендерит read-only заглушку (fallback), апселл виден только когда PRO неактивен.
	 *
	 * Дескриптор секции:
	 *   id       string   — ключ секции; при совпадении id секции ДЕДУПЛИЦИРУЮТСЯ (см. tie-break)
	 *   priority int      — порядок и приоритет перекрытия (больше = позже и «главнее»)
	 *   render   callable — рендер контента секции (без формы-обёртки; обёртку даёт хост)
	 *
	 * Tie-break дедупликации (в отличие от dashboard/widgets, который НЕ дедуплицирует —
	 * иначе Free-заглушка и PRO-секция с одним id отрендерились бы обе): при совпадении id
	 * побеждает дескриптор с БОЛЬШИМ priority; при равном priority — ПОСЛЕДНИЙ в массиве
	 * (позже зарегистрированный). Финальный список сортируется по priority по возрастанию.
	 *
	 * @return array<int, array{id:string,priority:int,render:callable}>
	 */
	private function general_sections(): array {
		$host = [
			// CTAN-201: Free-заглушка 'enabled-sections' (запертый чекбокс + апселл) удалена —
			// Guideline 5 запрещает locked UI; PRO приносит рабочую секцию в этот же слот (prio 20).
		];

		/**
		 * Фильтр-реестр секций вкладки General.
		 * Модуль добавляет свою секцию или перекрывает хостовую по совпадающему id.
		 *
		 * @param array<int, array{id:string,priority:int,render:callable}> $sections
		 */
		$sections = apply_filters( 'plathix/settings/general_sections', $host );
		$sections = is_array( $sections ) ? $sections : $host;

		return self::dedup_sections( $sections );
	}

	/**
	 * Dedup+сортировка дескрипторов секций реестра general_sections ([internal]).
	 * Чистая функция от массива (без WP) — отделена от источника данных, тестируется напрямую.
	 *
	 * Tie-break: при совпадении id побеждает больший priority; при равном priority — последний
	 * в массиве (позже зарегистрированный, `>=`). Финал сортируется по priority по возрастанию.
	 * В отличие от dashboard/widgets (не дедуплицирует) — здесь dedup обязателен, иначе
	 * Free-заглушка и PRO-секция с одним id отрендерились бы обе.
	 *
	 * @param array<int, mixed> $sections
	 * @return array<int, array{id:string,priority:int,render:callable}>
	 */
	public static function dedup_sections(array $sections): array {
		$winners = [];
		foreach ( $sections as $section ) {
			if ( ! is_array( $section ) || ! isset( $section['id'], $section['render'] ) ) {
				continue;
			}
			$id       = (string) $section['id'];
			$priority = (int) ( $section['priority'] ?? 0 );
			if ( ! isset( $winners[ $id ] ) || $priority >= (int) $winners[ $id ]['priority'] ) {
				$winners[ $id ] = [ 'id' => $id, 'priority' => $priority, 'render' => $section['render'] ];
			}
		}

		$final = array_values( $winners );
		usort( $final, static fn (array $a, array $b): int => $a['priority'] <=> $b['priority'] );

		return $final;
	}

	/**
	 * Slug-набор табов (для resolve/redirect-whitelist), в порядке дескрипторов.
	 * Public: используется SettingsPage::preserve_settings_tab для валидации redirect-таба.
	 * @return array<int, string>
	 */
	public function tab_slugs(): array {
		return array_map( static fn (array $t): string => (string) $t['slug'], $this->tabs() );
	}

	/** Возвращает активный таб из URL, fallback на первый (general). */
	private function resolve_active_tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only UI state
		$tab    = sanitize_key( (string) ( $_GET['tab'] ?? 'general' ) );
		$slugs  = $this->tab_slugs();
		return in_array( $tab, $slugs, true ) ? $tab : ( $slugs[0] ?? 'general' );
	}

	/** Строит URL таба настроек. */
	private function settings_tab_url(string $tab): string {
		return add_query_arg( 'tab', $tab, admin_url( 'admin.php?page=' . SettingsPage::PAGE_SLUG ) );
	}

	/** Основная точка входа — рендерит всю страницу. */
	public function render(): void {
		AdminLayout::render_page( SettingsPage::PAGE_SLUG, function (): void {
			if ( ! AccessResolver::currentUserIsFullAdmin() ) {
				return;
			}

			$active_tab = $this->resolve_active_tab();

			// Cron-warning: при миграции на render_page ([internal]) notice уехал
			// ВНУТРЬ обёртки (был голым над каркасом) — намеренно, чтобы он попал в буфер и
			// страница гарантированно получила футер. Косметическое смещение внутрь plathix-layout.
			if ( $this->cron_health_service->is_stalled() ) {
				echo '<div class="notice notice-error"><p>' .
					esc_html__( 'WP-Cron is unavailable. ZIP generation and import jobs will not run until WP-Cron or a real system cron is configured.', 'plathix' ) .
					'</p></div>';
			}
			?>
			<div class="plathix-page">

			<div class="plathix-page__head">
				<div>
					<h1 class="plathix-page__title"><?php esc_html_e( 'Settings', 'plathix' ); ?></h1>
					<div class="plathix-page__desc"><?php esc_html_e( 'Configure Plathix plugin behaviour, access and integrations.', 'plathix' ); ?></div>
				</div>
			</div>

			<?php $tabs = $this->tabs(); ?>
			<div class="plathix-card">
				<div class="plathix-tabs-bar" data-plathix-tabs="settings" role="tablist" aria-label="<?php esc_attr_e( 'Settings sections', 'plathix' ); ?>">
					<?php foreach ( $tabs as $tab ) :
						$slug = (string) $tab['slug']; ?>
						<a href="<?php echo esc_url( $this->settings_tab_url( $slug ) ); ?>"
						   data-plathix-tab="<?php echo esc_attr( $slug ); ?>"
						   role="tab"
						   aria-selected="<?php echo $active_tab === $slug ? 'true' : 'false'; ?>"
						   class="plathix-tab<?php echo $active_tab === $slug ? ' is-active' : ''; ?>"
						   <?php echo $active_tab === $slug ? 'aria-current="page"' : ''; ?>>
							<?php echo esc_html( (string) $tab['label'] ); ?>
						</a>
					<?php endforeach; ?>
				</div>

				<div class="plathix-card__body plathix-settings__body">
					<?php foreach ( $tabs as $tab ) :
						$slug = (string) $tab['slug']; ?>
						<section
							data-plathix-tab-panel="<?php echo esc_attr( $slug ); ?>"
							role="tabpanel"
							aria-hidden="<?php echo $active_tab === $slug ? 'false' : 'true'; ?>"
							<?php echo $active_tab === $slug ? '' : 'hidden'; ?>
						>
							<?php
							// Danger Zone — не форма настроек (options.php), а destructive AJAX-действие,
							// поэтому рендерится напрямую, без form-обёртки render_tab_form().
							if ( ( new DataWipeApi() )->tabSlug() === $slug ) {
								( $tab['render'] )();
							} else {
								$this->render_tab_form( $slug, $tab['render'] );
							}
							?>
						</section>
					<?php endforeach; ?>
				</div>
			</div>

			</div>
			<?php
		} );
	}

	/**
	 * Обёртка формы таба (единая для всех табов, host owns): options.php form +
	 * settings_fields(общая OPTION_GROUP) + redirect-hidden + save-bar. Контент таба
	 * рендерит переданный callable дескриптора (поля, без формы).
	 *
	 * @param callable $render Рендер контента полей таба.
	 */
	/**
	 * [internal]: options.php/OPTION_GROUP заменён изолированным per-таб admin-post
	 * ([internal]/#518) — form action строится по табу
	 * (admin_post_plathix_save_{$slug}), не по опции; один nonce на весь сабмит таба
	 * (SettingsSaveHandler::handle_tab_save() проверяет его централизованно, не каждый
	 * $save_callback сам). _plathix_redirect_tab остаётся без изменений —
	 * preserve_settings_tab() продолжает работать, т.к. матчит по итоговому redirect
	 * URL, не по источнику вызова.
	 */
	private function render_tab_form(string $slug, callable $render): void {
		$form_url = esc_url( admin_url( 'admin-post.php' ) );
		// [internal]: видимость бейджа решается на сервере из текущего $_GET, а не на
		// клиенте из location.search — WP core вставляет <link rel="canonical"> без
		// settings-updated в <head> каждой admin-страницы, браузер синхронизирует с ним
		// адресную строку ДО DOMContentLoaded, поэтому клиентское чтение всегда опаздывает.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only UI-visibility toggle, no data modified; real save is separately nonce-protected by admin-post.php before this param exists
		$just_saved     = isset( $_GET['settings-updated'] ) && $_GET['settings-updated'] === 'true';
		$notice_style   = $just_saved ? '' : ' style="display:none;"';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- same read-only UI-visibility rationale as $just_saved above
		$partial_failed = isset( $_GET['plathix_settings_partial_fail'] );
		$error_style    = $partial_failed ? '' : ' style="display:none;"';
		?>
		<form method="post" action="<?php echo $form_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $form_url is already esc_url()'d where it is assigned; escaping twice would corrupt the &amp; entities ?>">
			<input type="hidden" name="action" value="plathix_save_<?php echo esc_attr( $slug ); ?>">
			<?php wp_nonce_field( 'plathix_save_' . $slug ); ?>
			<input type="hidden" name="_plathix_redirect_tab" value="<?php echo esc_attr( $slug ); ?>">

			<div class="plathix-settings__fields">
				<?php $render(); ?>
			</div>

			<div class="plathix-save__bar plathix-settings__save-bar">
				<button type="submit" name="submit" class="plathix-btn plathix-btn--primary"><?php esc_html_e( 'Save Settings', 'plathix' ); ?></button>
				<span id="plathix-saved-notice-<?php echo esc_attr( $slug ); ?>" class="plathix-badge plathix-badge--ok"<?php echo $notice_style; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- value is one of two literal strings assigned above ('' or a fixed style attribute), no dynamic data ?>>✓ <?php esc_html_e( 'Saved!', 'plathix' ); ?></span>
				<span id="plathix-save-failed-notice-<?php echo esc_attr( $slug ); ?>" class="plathix-badge plathix-badge--error"<?php echo $error_style; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- value is one of two literal strings assigned above ('' or a fixed style attribute), no dynamic data ?>>✗ <?php esc_html_e( 'Save failed', 'plathix' ); ?></span>
			</div>
		</form>
		<?php
	}

	// -------------------------------------------------------------------------
	// Tab: General
	// -------------------------------------------------------------------------

	/**
	 * Контент таба General: секции реестра (Enabled Sections), Media Grid, Default Folder Mode.
	 * Форму-обёртку рендерит render_tab_form().
	 */
	private function render_tab_general(): void {
		// Секция «Enabled Sections» рендерится через реестр general_sections ([internal]):
		// Free-заглушка по умолчанию, PRO-модуль перекрывает её рабочими чекбоксами выбора типов.
		foreach ( $this->general_sections() as $section ) {
			( $section['render'] )();
		}
		?>
		<div class="plathix-field__separator"></div>

		<div class="plathix-field">
			<div class="plathix-field__label"><?php esc_html_e( 'Media Grid', 'plathix' ); ?></div>
			<div class="plathix-field__desc"><?php esc_html_e( 'Enables infinite scroll in the Media Library grid and media picker modals.', 'plathix' ); ?></div>
			<?php $this->render_infinite_scroll(); ?>
		</div>

		<div class="plathix-field__separator"></div>

		<div class="plathix-field">
			<div class="plathix-field__label"><?php esc_html_e( 'Default upload folder', 'plathix' ); ?></div>
			<div class="plathix-field__desc"><?php esc_html_e( 'New uploads go to this folder when no folder is chosen explicitly.', 'plathix' ); ?></div>
			<?php $this->render_default_upload_folder(); ?>
		</div>

		<div class="plathix-field__separator"></div>

		<div class="plathix-checkbox-field__section-title"><?php esc_html_e( 'Behavior', 'plathix' ); ?></div>

		<div class="plathix-field">
			<div class="plathix-field__label"><?php esc_html_e( 'Confirm bulk actions', 'plathix' ); ?></div>
			<div class="plathix-field__desc"><?php esc_html_e( 'Ask for confirmation before moving, deleting or restructuring 10 or more items at once.', 'plathix' ); ?></div>
			<?php $this->render_bulk_safe_mode(); ?>
		</div>

		<?php
	}

	// -------------------------------------------------------------------------
	// Field renderers
	// -------------------------------------------------------------------------

	/**
	 * Выпадающий список папки по умолчанию для новых загрузок ([internal]).
	 *
	 * НАЗВАНИЯ, а не ID. Прежняя редакция этой настройки была `<input type="number">` с
	 * ручным вводом term_id и была удалена именно поэтому ([internal]: «пользователь не
	 * видит ID папок и не понимает, какое значение вводить»). Возврат к вводу числа
	 * означал бы повтор той же ошибки.
	 *
	 * Из списка исключены цели, которые всё равно отвергнет `Upload::assign_folder_on_upload()`:
	 * «Несортированные» ([internal]), «Корзина» (#237) и папки в корзине — предлагать выбор,
	 * который молча не сработает, хуже, чем не предлагать вовсе.
	 *
	 * Если сохранённая папка недоступна (удалена в корзину или снесена), опция НЕ зануляется:
	 * restore возвращает тот же term_id и настройка оживает сама. Но пользователь обязан
	 * узнать о разрыве здесь — в фоновом хуке `add_attachment` UI-канала нет.
	 */
	private function render_default_upload_folder(): void {
		$selected = (int) get_option( 'plathix_default_folder_id', 0 );

		$folders = ( new FolderCountService( new FolderRepository(), Cache::make() ) )
			->get_all_cached( PLATHIX_TAXONOMY );

		$hidden     = HiddenFolders::ids( PLATHIX_TAXONOMY );
		$trash_id   = TrashFolder::id( PLATHIX_TAXONOMY );
		$repository = new FolderRepository();

		// Отступы обязаны совпадать с РЕАЛЬНОЙ иерархией, поэтому дерево обходится от
		// корней в глубину. Полагаться на порядок get_all_cached() нельзя: он сортирует
		// `parentId <=> parentId` (FolderCountService::get_all_cached), то есть плоско
		// группирует по родителю. Первая редакция этого метода ставила отступы поверх
		// такого порядка — и список показывал иерархию, которой не существует: дети
		// Editorial рисовались под Brand. Дефект того же класса, что убил настройку в
		// [internal] (интерфейс, который пользователь не может прочитать правильно).
		$children = [];
		foreach ( $folders as $folder ) {
			$id = (int) $folder->id;
			if ( $id <= 0 || $id === $trash_id || in_array( $id, $hidden, true ) ) {
				continue;
			}
			if ( $repository->is_uncategorized_folder( $id, PLATHIX_TAXONOMY ) ) {
				continue;
			}

			$children[ (int) $folder->parentId ][] = $folder;
		}

		$choices = [];
		$walk    = static function (int $parent_id, int $depth) use (&$walk, &$choices, $children): void {
			// Глубина ограничена PLATHIX_MAX_DEPTH; guard на 20 — страховка от цикла в
			// данных (папка-предок сама себе потомок), чтобы страница настроек не легла.
			if ( $depth > 20 || ! isset( $children[ $parent_id ] ) ) {
				return;
			}

			foreach ( $children[ $parent_id ] as $folder ) {
				$id = (int) $folder->id;
				// trim: имя приходит из терма, а отступ рисуем сами — лишние пробелы по
				// краям сбили бы выравнивание уровней.
				$choices[ $id ] = str_repeat( "\u{00A0}\u{00A0}", $depth ) . trim( $folder->name );
				$walk( $id, $depth + 1 );
			}
		};
		$walk( 0, 0 );

		$is_broken = $selected > 0 && ! isset( $choices[ $selected ] );
		?>
		<?php if ( $is_broken ) : ?>
			<?php
			// Сохранённая папка недоступна. Скрытое поле идёт ПЕРЕД select и несёт текущий
			// id: без него постороннее сохранение формы молча затёрло бы настройку нулём
			// (браузер показал бы «No folder»), а вместе с ней и обещание «restore вернёт
			// настройку». Порядок значим: одноимённый select ниже перекроет hidden, когда
			// пользователь ОСОЗНАННО выберет другую папку — иначе поле стало бы нередактируемым.
			?>
			<input type="hidden" name="plathix_default_folder_id" value="<?php echo esc_attr( (string) $selected ); ?>">
		<?php endif; ?>
		<?php // max-width задан явно, в паритете с соседним селектом (Modules\Svg\SvgSettings): ?>
		<?php // иначе ширина держалась бы на дефолте ядра WP (.wp-core-ui select), а не на нашем решении. ?>
		<select name="plathix_default_folder_id" class="plathix-select plathix-settings__folder-select">
			<?php if ( $is_broken ) : ?>
				<option value="<?php echo esc_attr( (string) $selected ); ?>" selected disabled><?php esc_html_e( '(folder unavailable)', 'plathix' ); ?></option>
			<?php endif; ?>
			<option value="0" <?php selected( 0, $selected ); ?>><?php esc_html_e( 'No folder', 'plathix' ); ?></option>
			<?php foreach ( $choices as $id => $label ) : ?>
				<option value="<?php echo esc_attr( (string) $id ); ?>" <?php selected( $id, $selected ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php if ( $is_broken ) : ?>
			<div class="plathix-field__desc plathix-settings__folder-broken-notice">
				<?php esc_html_e( 'The selected folder is no longer available — it was deleted or moved to trash. New uploads go to the media library root until you pick another folder. Restoring the folder brings this setting back.', 'plathix' ); ?>
			</div>
		<?php endif; ?>
		<?php
	}

	/** Чекбокс включения infinite scroll в сетке медиа. */
	private function render_infinite_scroll(): void {
		$enabled = (bool) get_option( 'plathix_infinite_scroll', false );
		?>
		<label class="plathix-checkbox-field__row plathix-checkbox-field__row--standalone">
			<input type="checkbox" name="plathix_infinite_scroll" value="1" <?php checked( $enabled ); ?>>
			<div class="plathix-checkbox-field__info">
				<span class="plathix-checkbox-field__label"><?php esc_html_e( 'Enable infinite scroll in Media Grid', 'plathix' ); ?></span>
				<span class="plathix-checkbox-field__desc"><?php esc_html_e( 'Replaces default pagination with seamless infinite loading.', 'plathix' ); ?></span>
			</div>
		</label>
		<?php
	}

	/** Чекбокс «Confirm bulk actions» (заголовок/описание рендерит plathix-field в General). */
	private function render_bulk_safe_mode(): void {
		$enabled = (bool) get_option( 'plathix_bulk_safe_mode', true );
		?>
		<label class="plathix-checkbox-field__row plathix-checkbox-field__row--standalone">
			<input type="checkbox" name="plathix_bulk_safe_mode" value="1" <?php checked( $enabled ); ?>>
			<div class="plathix-checkbox-field__info">
				<span class="plathix-checkbox-field__label"><?php esc_html_e( 'Confirm bulk actions on 10 or more items', 'plathix' ); ?></span>
			</div>
		</label>
		<?php
	}
}
