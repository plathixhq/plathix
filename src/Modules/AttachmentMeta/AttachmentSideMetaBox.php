<?php

declare(strict_types=1);

namespace Plathix\Modules\AttachmentMeta;

use Plathix\Core\TaxonomyResolver;
use Plathix\PublicApi\ReplaceApi;
use Plathix\User\AccessLevel;
use Plathix\User\AccessResolver;

final class AttachmentSideMetaBox
{
	private const META_BOX_ID = 'plathix-attachment-side';

	public function __construct() {
		add_action( 'add_meta_boxes_attachment', [ $this, 'register' ] );
	}

	public function register(): void {
		add_meta_box(
			self::META_BOX_ID,
			__( 'Folder & file', 'plathix' ),
			[ $this, 'render' ],
			'attachment',
			'side',
			'default'
		);
	}

	public function render(\WP_Post $post): void {
		if ( AccessResolver::forCurrentUser() === AccessLevel::None ) {
			return;
		}

		$taxonomy = TaxonomyResolver::fromPostType( 'attachment' );
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return;
		}

		/** @var \WP_Post&object{ID:int} $post -- phpstan-wordpress stub omits declared WP_Post properties */
		?>
		<p class="plathix-side__field-label"><?php esc_html_e( 'Folder', 'plathix' ); ?></p>
		<?php
		echo FolderSwitchField::render( $post->ID ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup is built with esc_* internally
		?>

		<p class="plathix-side__field-label"><?php esc_html_e( 'Replace file', 'plathix' ); ?></p>
		<?php
		echo ( new ReplaceApi() )->renderTrigger( $post->ID ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup is built with esc_* internally
		?>
		<p class="description">
			<?php esc_html_e( 'Keeps the same attachment ID and WordPress relationships. Direct URL references may require manual review if filename or file type changes.', 'plathix' ); ?>
		</p>
		<?php
	}
}
