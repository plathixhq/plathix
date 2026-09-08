<?php

declare(strict_types=1);

namespace Plathix\Modules\Replace;

use Plathix\Http\RestController;
use Plathix\Infrastructure\MediaModalEnqueue;
use Plathix\PublicApi\AttachmentMetaApi;

final class AttachmentReplaceUi
{
	public function register(): void {
		add_filter( 'attachment_fields_to_edit', [ $this, 'addReplaceField' ], 10, 2 );
		add_filter( 'media_row_actions', [ $this, 'addReplaceRowAction' ], 10, 2 );

		MediaModalEnqueue::register( [ $this, 'enqueueReplaceScript' ] );
	}

	public function enqueueReplaceScript(): void {
		if ( wp_script_is( 'plathix-replace-media', 'enqueued' ) ) {
			return;
		}

		$asset = \Plathix\Infrastructure\AssetManifest::read( 'js/replace-media.asset.php' );
		$deps  = $asset['dependencies'] ?? [];

		$version = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? (string) time() : $asset['version'];

		wp_enqueue_script( 'plathix-replace-media', PLATHIX_ASSETS_URL . 'js/replace-media.js', $deps, $version, true );
		wp_localize_script( 'plathix-replace-media', 'PlathixReplace', [
			'restUrl'   => rest_url( 'plathix/v1/' ),

			'restUrlFallback' => RestController::restRouteFallbackBase(),
			'restNonce' => wp_create_nonce( 'wp_rest' ),
		] );

		if ( file_exists( PLATHIX_ASSETS_PATH . 'css/attachment-fields.css' ) ) {
			wp_enqueue_style( 'plathix-attachment-fields', PLATHIX_ASSETS_URL . 'css/attachment-fields.css', [], $version );
		}
	}

	/**
	 * @param array<string,mixed> $form_fields
	 * @return array<string,mixed>
	 */
	public function addReplaceField(array $form_fields, \WP_Post $post): array {

		if ( ( new AttachmentMetaApi() )->isAttachmentEditPage() ) {
			return $form_fields;
		}

		/** @var \WP_Post&object{ID:int} $post -- phpstan-wordpress stub omits declared WP_Post properties */
		$form_fields['plathix_replace_file'] = [
			'label' => __( 'Replace file', 'plathix' ),
			'input' => 'html',
			'html'  => $this->replaceTriggerMarkup( $post->ID, true ),
			'helps' => esc_html__( 'Keeps the same attachment ID and WordPress relationships. Direct URL references may require manual review if filename or file type changes.', 'plathix' ),
		];

		return $form_fields;
	}

	/**
	 * @param array<string,string> $actions
	 * @return array<string,string>
	 */
	public function addReplaceRowAction(array $actions, \WP_Post $post): array {
		/** @var \WP_Post&object{ID:int} $post -- phpstan-wordpress stub omits declared WP_Post properties */
		if ( $post->post_type !== 'attachment' ) {
			return $actions;
		}

		$actions['plathix_replace_file'] = $this->replaceTriggerMarkup( $post->ID, false );

		return $actions;
	}

	public function renderReplaceTrigger(int $attachment_id): string {
		return $this->replaceTriggerMarkup( $attachment_id, true );
	}

	private function replaceTriggerMarkup(int $attachment_id, bool $details_view): string {

		$button_class = $details_view ? 'button plathix-replace__file-button' : 'plathix-replace-file-link';
		$note         = $details_view
			? ''
			: '<span class="screen-reader-text">' . esc_html__( 'Replace file', 'plathix' ) . '</span>';

		$icon = $details_view
			? '<svg class="plathix-replace__file-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="17 1 21 5 17 9"></polyline><path d="M3 11V9a4 4 0 0 1 4-4h14"></path><polyline points="7 23 3 19 7 15"></polyline><path d="M21 13v2a4 4 0 0 1-4 4H3"></path></svg>'
			: '';

		return sprintf(
			'<span class="plathix-replace__file-wrap" data-attachment-id="%1$d">' .
			'<button type="button" class="%2$s plathix-replace__file-trigger" data-attachment-id="%1$d">%3$s%4$s</button>' .
			'<input type="file" class="plathix-replace__file-input" data-attachment-id="%1$d" hidden>' .
			'%5$s' .
			'</span>',
			$attachment_id,
			esc_attr( $button_class ),
			$icon,
			esc_html__( 'Replace file', 'plathix' ),
			$note
		);
	}
}
