<?php

declare(strict_types=1);

namespace Plathix\Modules\Preset;

use Plathix\Http\AjaxGuard;
use Plathix\Infrastructure\Keys;
use Plathix\PublicApi\PlathixAPI;
use Plathix\User\AccessLevel;
use Plathix\User\AccessResolver;

final class PresetPostActions
{
	public function __construct(
		private readonly PresetRepository $repository = new PresetRepository(),
		private readonly PresetUploadPipeline $upload_pipeline = new PresetUploadPipeline(),
	) {
	}

	public function register(): void {
		add_action( 'admin_post_' . PresetsPage::APPLY_ACTION, [ $this, 'handleApply' ] );
		add_action( 'admin_post_' . PresetsPage::DELETE_ACTION, [ $this, 'handleDelete' ] );
		add_action( 'admin_post_' . PresetsPage::UPLOAD_ACTION, [ $this, 'handleUpload' ] );
		add_action( 'admin_post_' . PresetsPage::SCRATCH_ACTION, [ $this, 'handleScratch' ] );

		add_action( 'wp_ajax_' . PresetsPage::VALIDATE_ACTION, [ $this, 'handleValidate' ] );
		add_action( 'admin_notices', [ $this, 'maybeShowScratchNotice' ] );
	}

	public function handleApply(): void {

		if ( ! AccessResolver::currentUserIsFullAdmin() ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'plathix' ), 403 );
		}

		$preset_id = (int) ( $_REQUEST['preset_id'] ?? 0 ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cap verified above; (int) cast; nonce checked against this value on next line
		check_admin_referer( PresetsPage::APPLY_ACTION . '_' . $preset_id );

		$preset = $this->repository->find( $preset_id );

		if ( $preset === null ) {
			$this->redirectWithNotice( 'error', __( 'Preset not found.', 'plathix' ) );
			return;
		}

		$result = PlathixAPI::presets()->apply( $preset_id );

		if ( ! $result['success'] ) {
			$msg = (string) ( $result['error']['message'] ?? __( 'Apply failed.', 'plathix' ) );
			$this->redirectWithNotice( 'error', $msg );
			return;
		}

		$created = (int) ( $result['created'] ?? 0 );
		$this->redirectWithNotice(
			'success',
			sprintf(
				/* translators: 1: preset title, 2: folder count */
				__( 'Preset "%1$s" applied. %2$d folders created.', 'plathix' ),
				(string) ( $preset['title'] ?? '' ),
				$created
			)
		);
	}

	public function handleDelete(): void {

		if ( ! AccessResolver::currentUserIsFullAdmin() ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'plathix' ), 403 );
		}

		$preset_id = (int) ( $_REQUEST['preset_id'] ?? 0 ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cap verified above; (int) cast; nonce checked against this value on next line
		check_admin_referer( PresetsPage::DELETE_ACTION . '_' . $preset_id );

		$preset = $this->repository->find( $preset_id );

		if ( $preset === null ) {
			$this->redirectWithNotice( 'error', __( 'Preset not found.', 'plathix' ) );
			return;
		}

		if ( (string) ( $preset['source_type'] ?? '' ) === PresetSourceType::BUILTIN ) {
			$this->redirectWithNotice( 'error', __( 'Built-in presets cannot be deleted.', 'plathix' ) );
			return;
		}

		$this->repository->delete( $preset_id );

		do_action( 'plathix/audit/record', 'preset_deleted', [
			'presetId'   => $preset_id,
			'slug'       => (string) ( $preset['slug'] ?? '' ),
			'title'      => (string) ( $preset['title'] ?? '' ),
			'sourceType' => (string) ( $preset['source_type'] ?? '' ),
		]);

		$this->redirectWithNotice( 'success', __( 'Preset deleted.', 'plathix' ) );
	}

	public function handleUpload(): void {

		if ( ! AccessResolver::currentUserIsFullAdmin() ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'plathix' ), 403 );
		}

		check_admin_referer( PresetsPage::UPLOAD_ACTION );

		$file = $_FILES['plathix_preset_zip'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce+cap verified above; $_FILES array handled by upload pipeline (no text sanitization applicable)
		if ( ! is_array( $file ) || empty( $file['tmp_name'] ) ) {
			$this->redirectWithNotice( 'error', __( 'No file uploaded.', 'plathix' ) );
			return;
		}

		$result = $this->upload_pipeline->run( $file );

		if ( ! $result['success'] ) {
			$msg = (string) ( $result['error']['message'] ?? __( 'Upload failed.', 'plathix' ) );
			$this->redirectWithNotice( 'error', $msg );
			return;
		}

		$new_preset_id = (int) ( $result['preset']['id'] ?? 0 );

		$this->redirectWithNotice(
			'success',
			sprintf(
				/* translators: %s: preset title */
				__( 'Preset "%s" uploaded successfully.', 'plathix' ),
				(string) ( $result['preset']['title'] ?? $result['title'] ?? '' )
			),
			$new_preset_id > 0 ? $new_preset_id : null
		);
	}

	public function handleValidate(): void {

		AjaxGuard::requireCap( AccessLevel::Full, 'manage_options' );

		check_ajax_referer( PresetsPage::UPLOAD_ACTION );

		$file = $_FILES['plathix_preset_zip'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce+cap verified above; $_FILES array handled by upload pipeline (no text sanitization applicable)
		if ( ! is_array( $file ) || empty( $file['tmp_name'] ) ) {
			wp_send_json_error( [ 'message' => __( 'No file uploaded.', 'plathix' ) ] );
		}

		$result = $this->upload_pipeline->run( $file, PresetSourceType::CUSTOM, true );

		if ( ! $result['success'] ) {
			wp_send_json_error( $result['error'] );
		}

		wp_send_json_success( $result['preset'] );
	}

	public function handleScratch(): void {

		if ( ! AccessResolver::currentUserIsFullAdmin() ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'plathix' ), 403 );
		}

		check_admin_referer( PresetsPage::SCRATCH_ACTION );

		$result = ( new FolderResetService() )->run();

		if ( ! $result['success'] ) {
			$errors = (int) ( $result['errors'] ?? 0 );
			$this->setScratchNotice(
				'error',
				sprintf(
					/* translators: %d: error count */
					__( 'Reset completed with %d errors.', 'plathix' ),
					$errors
				)
			);
			wp_safe_redirect( admin_url( 'upload.php' ) );
			exit;
		}

		$this->setScratchNotice( 'success', __( 'All folders have been reset. You can now start from scratch.', 'plathix' ) );
		wp_safe_redirect( admin_url( 'upload.php' ) );
		exit;
	}

	private function setScratchNotice(string $type, string $message): void {
		set_transient( Keys::transient( 'scratch_notice_' . get_current_user_id() ), [ 'type' => $type, 'message' => $message ], 60 );
	}

	public function maybeShowScratchNotice(): void {
		$key  = Keys::transient( 'scratch_notice_' . get_current_user_id() );
		$data = get_transient( $key );
		if ( ! is_array( $data ) ) {
			return;
		}
		delete_transient( $key );
		$type    = in_array( $data['type'] ?? '', [ 'success', 'error' ], true ) ? $data['type'] : 'info';
		$message = (string) ( $data['message'] ?? '' );
		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $type ),
			esc_html( $message )
		);
	}

	/**
	 * @param int|null $new_preset_id
	 */

	private function redirectWithNotice(string $type, string $message, ?int $new_preset_id = null): void {
		$args = [
			'page'            => PresetsPage::PAGE_SLUG,
			'plathix_notice'  => rawurlencode( $message ),
			'plathix_ntype'   => $type,
		];

		if ( $new_preset_id !== null && $new_preset_id > 0 ) {
			$args['plathix_new_preset'] = $new_preset_id;
		}

		$url = add_query_arg( $args, admin_url( 'admin.php' ) );
		wp_safe_redirect( $url );
		exit;
	}
}
