<?php

declare(strict_types=1);

namespace Plathix\Modules\DataWipe;

/**
 * UI-таб Danger Zone: полная очистка данных плагина ([internal] / migration-loop DataWipe T1).
 *
 * Вынесено из {@see \Plathix\Admin\SettingsView}: это НЕ секция настроек (не форма
 * options.php/OPTION_GROUP), а самостоятельная destructive-операция. Свой AJAX-контракт
 * {@see self::WIPE_ACTION} (роут — {@see DataWipeAjax}; исполнитель {@see DataWiper}), свой
 * PRO-слот `plathix/cleanup/preview_items`, свой модальный флоу с inline-JS.
 *
 * Рендерится напрямую (не через form-обёртку options.php) — снос идёт AJAX-действием, а не
 * сохранением настроек. Регистрируется в реестр табов модулем {@see Module}.
 */
final class DangerZoneTab
{
	/** Слаг таба (публичный — {@see \Plathix\Admin\SettingsView} ветвит прямой рендер по нему). */
	public const TAB = 'danger';

	/**
	 * AJAX-действие полной очистки. Единый источник action-строки для модуля: JS в render()
	 * шлёт её, {@see Module} вешает на неё `wp_ajax_`-хендлер {@see DataWipeAjax}. Прежде была
	 * парна с AjaxRouter actions_map; с T2 роут владеет модуль. НЕ переименовывать.
	 */
	public const WIPE_ACTION = 'plathix_delete_all_data';

	/**
	 * Контент таба Danger Zone: destructive-кнопка полной очистки + модалка подтверждения.
	 * Не форма настроек — снос идёт AJAX-действием plathix_delete_all_data (исполнитель
	 * {@see DataWipeAjax::handle} → {@see DataWiper} + хук plathix/data_wipe/cleanup). Стиль — дизайн-система
	 * плагина (plathix-modal__backdrop / plathix-notice / plathix-btn--danger).
	 */
	public function render(): void {
		$items    = $this->preview_items();
		$ajax_url = admin_url( 'admin-ajax.php' );
		$nonce    = \Plathix\Http\Nonce::create();
		?>
		<div class="plathix-field">
			<div class="plathix-field__label plathix-danger-zone__label"><?php esc_html_e( 'Delete all plugin data', 'plathix' ); ?></div>
			<div class="plathix-field__desc">
				<?php esc_html_e( 'Permanently removes everything Plathix created — folders, presets, settings. Your media files, images and posts are NOT touched.', 'plathix' ); ?>
			</div>
			<div class="plathix-danger-zone__trigger-row">
				<button type="button" class="plathix-btn plathix-btn--danger" id="plathix-wipe-open">
					<?php esc_html_e( 'Delete all data…', 'plathix' ); ?>
				</button>
			</div>
		</div>

		<div id="plathix-wipe-modal" class="plathix-modal__backdrop" style="display:none;">
			<div class="plathix-modal__box">
				<div class="plathix-modal__head">
					<span class="plathix-danger-zone__modal-title"><?php esc_html_e( 'Delete all Plathix data?', 'plathix' ); ?></span>
					<button class="plathix-modal__close" id="plathix-wipe-close" aria-label="<?php esc_attr_e( 'Close', 'plathix' ); ?>">✕</button>
				</div>

				<div class="plathix-notice plathix-notice--err plathix-danger-zone__warning">
					<?php esc_html_e( 'This cannot be undone.', 'plathix' ); ?>
				</div>

				<div class="plathix-danger-zone__list-title"><?php esc_html_e( 'Will be permanently deleted:', 'plathix' ); ?></div>
				<ul class="plathix-danger-zone__list">
					<?php foreach ( $items as $item ) : ?>
						<li><?php echo esc_html( $item ); ?></li>
					<?php endforeach; ?>
				</ul>

				<div class="plathix-notice plathix-notice--ok plathix-danger-zone__safe-notice">
					<strong><?php esc_html_e( 'NOT touched:', 'plathix' ); ?></strong>
					<?php esc_html_e( 'your images, files and posts stay in the Media Library — they only lose their folder assignment.', 'plathix' ); ?>
				</div>

				<label class="plathix-checkbox-field__row plathix-danger-zone__confirm-row">
					<input type="checkbox" id="plathix-wipe-confirm">
					<span class="plathix-checkbox-field__label"><?php esc_html_e( 'I understand this is permanent and cannot be undone.', 'plathix' ); ?></span>
				</label>

				<div class="plathix-danger-zone__actions">
					<button type="button" class="plathix-btn plathix-btn--danger" id="plathix-wipe-run" disabled>
						<?php esc_html_e( 'Delete all data', 'plathix' ); ?>
					</button>
					<button type="button" class="plathix-btn plathix-btn--ghost" id="plathix-wipe-cancel">
						<?php esc_html_e( 'Cancel', 'plathix' ); ?>
					</button>
				</div>

				<div class="plathix-notice plathix-notice--err plathix-danger-zone__error" id="plathix-wipe-error" style="display:none;"></div>
			</div>
		</div>
		<?php
		// WP.org review round 1 ([internal]): wp_add_inline_script() вместо голого echo
		// '<script>'. Не anti-FOUC (обычный UI-behavior script, нет мигания-риска) — handle
		// 'plathix-admin-ui' уже enqueue на этой странице (AdminUiEnqueueService), 'after'
		// печатается в обычном footer-проходе, timing не критичен для модалки.
		wp_add_inline_script( 'plathix-admin-ui', self::modal_script( $nonce, $ajax_url ), 'after' );
	}

	private static function modal_script(string $nonce, string $ajax_url): string {
		ob_start();
		?>
		( function () {
			var open    = document.getElementById( 'plathix-wipe-open' );
			var modal   = document.getElementById( 'plathix-wipe-modal' );
			var close   = document.getElementById( 'plathix-wipe-close' );
			var cancel  = document.getElementById( 'plathix-wipe-cancel' );
			var confirm = document.getElementById( 'plathix-wipe-confirm' );
			var run     = document.getElementById( 'plathix-wipe-run' );
			var errBox  = document.getElementById( 'plathix-wipe-error' );
			if ( ! open || ! modal ) { return; }

			function show() { modal.style.display = 'flex'; }
			function hide() {
				modal.style.display = 'none';
				confirm.checked = false;
				run.disabled = true;
				errBox.style.display = 'none';
			}

			open.addEventListener( 'click', show );
			close.addEventListener( 'click', hide );
			cancel.addEventListener( 'click', hide );
			// Чекбокс «понимаю» — единственный, что разблокирует destructive-кнопку (двойное
			// подтверждение: открыть модалку + осознанно отметить необратимость).
			confirm.addEventListener( 'change', function () { run.disabled = ! confirm.checked; } );

			run.addEventListener( 'click', function () {
				if ( ! confirm.checked ) { return; }
				run.disabled = true;
				run.textContent = <?php echo wp_json_encode( __( 'Deleting…', 'plathix' ) ); ?>;

				var body = new URLSearchParams();
				body.set( 'action', <?php echo wp_json_encode( self::WIPE_ACTION ); ?> );
				body.set( 'nonce', <?php echo wp_json_encode( $nonce ); ?> ); // Nonce::verify_or_die() читает $_REQUEST['nonce']

				fetch( <?php echo wp_json_encode( $ajax_url ); ?>, {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
					body: body.toString()
				} )
				.then( function ( r ) { return r.json(); } )
				.then( function ( res ) {
					if ( res && res.success ) {
						window.location.reload();
					} else {
						throw new Error( ( res && res.data && res.data.message ) || 'error' );
					}
				} )
				.catch( function () {
					errBox.textContent = <?php echo wp_json_encode( __( 'Cleanup failed. Please try again.', 'plathix' ) ); ?>;
					errBox.style.display = 'block';
					run.disabled = false;
					run.textContent = <?php echo wp_json_encode( __( 'Delete all data', 'plathix' ) ); ?>;
				} );
			} );
		} )();
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Список того, что удалится при полной очистке — для честного предупреждения в модалке.
	 * Free-базовый набор; PRO дописывает свои пункты (шорткоды/журнал) через фильтр-слот
	 * `plathix/cleanup/preview_items` (нейтральный слот, не DOM-mount — инвариант PRO-слотов).
	 *
	 * @return list<string>
	 */
	private function preview_items(): array {
		$items = [
			__( 'All folders and their structure', 'plathix' ),
			__( 'Folder presets and applied-preset history', 'plathix' ),
			__( 'All plugin settings and preferences', 'plathix' ),
			__( 'Favorites, colors and folder positions', 'plathix' ),
		];

		/**
		 * Слот для PRO-модулей: дописать свои удаляемые данные в предупреждение
		 * (например «Shortcodes», «Activity log»). Только строки-подписи для UI.
		 *
		 * @param list<string> $items
		 */
		$items = (array) apply_filters( 'plathix/cleanup/preview_items', $items );

		return array_values( array_filter( array_map( 'strval', $items ) ) );
	}
}
