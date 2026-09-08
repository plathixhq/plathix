<?php

declare(strict_types=1);

namespace Plathix\Modules\DataWipe;

final class DangerZoneTab
{

	public const TAB = 'danger';

	public const WIPE_ACTION = 'plathix_delete_all_data';

	public function render(): void {
		$items    = $this->previewItems();
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

		wp_add_inline_script( 'plathix-admin-ui', self::modalScript( $nonce, $ajax_url ), 'after' );
	}

	private static function modalScript(string $nonce, string $ajax_url): string {
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


			confirm.addEventListener( 'change', function () { run.disabled = ! confirm.checked; } );

			run.addEventListener( 'click', function () {
				if ( ! confirm.checked ) { return; }
				run.disabled = true;
				run.textContent = <?php echo wp_json_encode( __( 'Deleting…', 'plathix' ) ); ?>;

				var body = new URLSearchParams();
				body.set( 'action', <?php echo wp_json_encode( self::WIPE_ACTION ); ?> );
				body.set( 'nonce', <?php echo wp_json_encode( $nonce ); ?> );

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
	 * @return list<string>
	 */

	private function previewItems(): array {
		$items = [
			__( 'All folders and their structure', 'plathix' ),
			__( 'Folder presets and applied-preset history', 'plathix' ),
			__( 'All plugin settings and preferences', 'plathix' ),
			__( 'Favorites, colors and folder positions', 'plathix' ),
		];

		/**
		 * @param list<string> $items
		 */

		$items = (array) apply_filters( 'plathix/cleanup/previewItems', $items );

		return array_values( array_filter( array_map( 'strval', $items ) ) );
	}
}
