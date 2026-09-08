<?php

declare(strict_types=1);

namespace Plathix\Modules\AttachmentMeta;

final class AttachmentEditContext
{

	public static function isAttachmentEditPage(): bool {
		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return false;
		}

		global $pagenow;

		return is_admin() && $pagenow === 'post.php';
	}
}
