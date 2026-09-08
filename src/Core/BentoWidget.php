<?php

declare(strict_types=1);

namespace Plathix\Core;

final class BentoWidget
{

	public static function label(string $label, string $tag = 'h2'): void {
		echo '<' . esc_html( $tag ) . ' class="plathix-bento__label">' . esc_html( $label ) . '</' . esc_html( $tag ) . '>';
	}
}
