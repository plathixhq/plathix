<?php

declare(strict_types=1);

namespace Plathix\Modules\DataWipe;

use Plathix\Http\AjaxGuard;
use Plathix\User\AccessLevel;

class DataWipeAjax
{

	public function handle(): void {

		$this->assertAuthorized();

		$blog_id = get_current_blog_id();

		$this->wipeFreeData( $blog_id );

		do_action( 'plathix/data_wipe/cleanup', $blog_id );

		wp_send_json_success( [ 'wiped' => true ] );
	}

	protected function assertAuthorized(): void {
		AjaxGuard::require( AccessLevel::Full, 'manage_options', null );
	}

	protected function wipeFreeData(int $blog_id): void {
		( new DataWiper() )->wipe( $blog_id );
	}
}
