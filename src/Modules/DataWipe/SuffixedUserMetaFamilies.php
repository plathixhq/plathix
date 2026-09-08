<?php

declare(strict_types=1);

namespace Plathix\Modules\DataWipe;

final class SuffixedUserMetaFamilies
{
	/** @var array<string, bool> */
	public const FAMILIES = [
		'plathix_favorites'            => true,
		'plathix_open_folder_id'       => true,
		'plathix_onboarding_dismissed' => false,
		'plathix_migration_dismissed'  => false,
		'plathix_user_access'          => false,
	];
}
