<?php

declare(strict_types=1);

namespace Plathix\Modules\Dashboard\Widgets;

use Plathix\PublicApi\ToolsApi;

class MigrationBannerWidget
{
	/**
	 * @param array<string, mixed> $data
	 */
	public function render(array $data): void {
		$plugin = $data['migration_plugin'];
		if ( $plugin === null ) {
			return;
		}

		$tools_url = ( new ToolsApi() )->pageUrl();
		?>
		<div class="plathix-migration-banner" id="plathix-migration-banner" data-source="<?php echo esc_attr( $plugin['key'] ); ?>">
			<div class="plathix-migration-banner__icon" aria-hidden="true">
				<svg width="20" height="20" viewBox="0 0 16 16" fill="currentColor"><path d="M1.5 3A1.5 1.5 0 000 4.5v7A1.5 1.5 0 001.5 13h13a1.5 1.5 0 001.5-1.5v-5A1.5 1.5 0 0014.5 5H7.5L6 3H1.5z"/></svg>
			</div>
			<div class="plathix-migration-banner__body">
				<div class="plathix-migration-banner__title">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: plugin name */
							__( '%s detected', 'plathix' ),
							$plugin['label']
						)
					);
					?>
				</div>
				<div class="plathix-migration-banner__desc">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: plugin name */
							__( 'We found an existing folder structure from %s. Import it to Plathix in one click — your organisation will be preserved.', 'plathix' ),
							$plugin['label']
						)
					);
					?>
				</div>
			</div>
			<div class="plathix-migration-banner__actions">
				<a href="<?php echo esc_url( add_query_arg( 'adapter', $plugin['key'], $tools_url ) ); ?>"
				   class="plathix-btn plathix-btn--primary plathix-btn--sm">
					<svg width="13" height="13" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M8 2v9M4 8l4 4 4-4"/><path d="M2 14h12"/></svg>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: source name (plugin or import adapter, e.g. FileBird). */
							__( 'Import from %s', 'plathix' ),
							$plugin['label']
						)
					);
					?>
				</a>
				<button type="button" class="plathix-btn plathix-btn--ghost plathix-btn--sm"
					id="plathix-migration-dismiss"
					aria-label="<?php esc_attr_e( 'Dismiss', 'plathix' ); ?>">
					<?php esc_html_e( 'Dismiss', 'plathix' ); ?>
				</button>
			</div>
		</div>
		<?php
	}
}
