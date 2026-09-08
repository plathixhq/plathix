<?php


declare(strict_types=1);

return [
	'prefix'  => 'Plathix\\Vendor',
	'finders' => [

		\Isolated\Symfony\Component\Finder\Finder::create()
			->files()
			->in( 'vendor/enshrined' ),

		\Isolated\Symfony\Component\Finder\Finder::create()
			->files()
			->name( '*.php' )
			->in( 'src' ),
	],

	'exclude-namespaces' => [ 'Plathix', 'PlathixPro' ],

	//

	// (`EnrichedReflector::isFunctionExcluded()` → `isFunctionInternal()`).
	//

	'exclude-functions' => [
		// WP core
		'add_query_arg',
		'apply_filters',
		'dbDelta',
		'get_current_screen',
		'get_current_user_id',
		'home_url',
		'trailingslashit',
		'wp_delete_file',
		'wp_doing_ajax',
		'wp_enqueue_media',
		'wp_generate_attachment_metadata',
		'wp_get_image_editor',
		'wp_handle_sideload',
		'wp_mkdir_p',
		'wp_parse_url',
		'wp_set_script_translations',
		'wp_upload_dir',
		'wp_using_ext_object_cache',

		'as_get_scheduled_actions',
		'as_has_scheduled_action',
		'as_schedule_recurring_action',
		'as_schedule_single_action',
		'as_unschedule_action',
		'as_unschedule_all_actions',

		'pll_languages_list',

	],

	//

	'exclude-classes' => [
		'/^WP_/',              // WP_Error, WP_Query, WP_REST_*, WP_Term, WP_User, WP_Post…
		'/^wpdb$/',

		'/^ActionScheduler/',  // ActionScheduler, ActionScheduler_Store, _NullAction…
		'/^Polylang/',

	],

	'expose-global-constants' => true,

	'patchers'           => [],
];
