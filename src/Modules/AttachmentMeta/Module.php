<?php

declare(strict_types=1);

namespace Plathix\Modules\AttachmentMeta;

use Plathix\Contracts\ModuleInterface;

class Module implements ModuleInterface
{

	public function register(): void
	{
		add_action( 'plathix/modules/boot', [ $this, 'boot' ] );
	}

	public function boot(): void
	{
		if ( is_admin() ) {
			new AttachmentSideMetaBox();
			( new AttachmentDetails() )->register();
			( new FolderSwitchUi() )->register();
		}
	}
}
