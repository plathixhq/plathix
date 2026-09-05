<?php

declare(strict_types=1);

namespace Plathix\Modules\AttachmentMeta;

final class AttachmentDetails
{
	public function register(): void {
		add_filter( 'attachment_fields_to_edit', [ $this, 'add_folder_field' ], 10, 2 );
	}

	/**
	 * @param array<string, mixed> $form_fields
	 * @return array<string, mixed>
	 */
	public function add_folder_field(array $form_fields, \WP_Post $post): array {
		// На странице файла папка идёт из мета-бокса справа — не дублируем compat-полем.
		if ( AttachmentEditContext::is_attachment_edit_page() ) {
			return $form_fields;
		}

		/** @var \WP_Post&object{ID:int} $post */
		$form_fields['plathix_folder_location'] = [
			'label' => __( 'Folder', 'plathix' ),
			'input' => 'html',
			'html'  => FolderSwitchField::render( $post->ID ),
			'helps' => '',
		];

		return $form_fields;
	}
}
