<?php

declare(strict_types=1);

namespace Plathix\Modules\ListScreen;

class SearchSortFields
{
	public function register(): void {
		add_action('restrict_manage_posts', [ $this, 'render' ], 10, 2);
	}

	public function render(string $post_type, string $which): void {

		if ( ! in_array($which, [ 'top', 'bar' ], true) || 'attachment' !== $post_type ) {
			return;
		}

		$context = ListScreenQueryContext::fromRequest();
		unset($context['m']);

		foreach ( $context as $name => $value ) {
			printf('<input type="hidden" name="%s" value="%s" />', esc_attr($name), esc_attr($value));
		}
	}
}
