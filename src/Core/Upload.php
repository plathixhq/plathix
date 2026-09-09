<?php

declare(strict_types=1);

namespace Plathix\Core;

use Plathix\Loader;

class Upload
{

	public function __construct(
		private readonly Loader $loader
	) {
		$this->loader->addAction( 'add_attachment', $this, 'assignFolderOnUpload' );
	}

	public function assignFolderOnUpload(int $attachment_id): void {
		if ( $attachment_id <= 0 ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended

		$folder_id = absint( wp_unslash( $_POST['plathix_folder'] ?? $_REQUEST['plathix_folder'] ?? get_option( 'plathix_default_folder_id', FolderId::ROOT ) ) );
		if ( $folder_id <= 0 ) {
			return;
		}

		if ( ! term_exists( $folder_id, PLATHIX_TAXONOMY ) ) {
			return;
		}

		$repository = new FolderRepository();
		if ( $repository->isUncategorizedFolder( $folder_id, PLATHIX_TAXONOMY ) ) {
			return;
		}

		if ( $folder_id === TrashFolder::id( PLATHIX_TAXONOMY ) ) {
			return;
		}

		if ( in_array( $folder_id, HiddenFolders::ids( PLATHIX_TAXONOMY ), true ) ) {
			return;
		}

		wp_set_object_terms( $attachment_id, [ $folder_id ], PLATHIX_TAXONOMY, false );

		do_action(
			'plathix/audit/record',
			'attachment_uploaded',
			[
				'objectType' => 'attachment',
				'objectId'   => $attachment_id,
				'targetType' => 'folder',
				'targetId'   => $folder_id,
				'summary'     => sprintf( 'Uploaded attachment %d', $attachment_id ),
				'context'     => [
					'file_name' => basename( (string) get_attached_file( $attachment_id ) ),
				],
			]
		);
	}
}
