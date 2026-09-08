<?php

declare(strict_types=1);

namespace Plathix\Svg\Sanitizer;

class Sanitizer
{
	public function sanitize(string $svg): string {
		if ( '' === trim($svg) ) {
			return '';
		}

		if ( ! class_exists(\DOMDocument::class) ) {
			return '';
		}

		// If enshrined library is available, use it as the primary sanitizer,
		// then run our own policy pass on the result via the same DOM path.
		$source = $svg;
		if ( class_exists(\enshrined\svgSanitize\Sanitizer::class) ) {
			try {
				$engine = new \enshrined\svgSanitize\Sanitizer();
				$engine->removeRemoteReferences( true );
				$result = $engine->sanitize($svg);
			} catch ( \Throwable $e ) {
				return '';
			}
			if ( ! is_string($result) || '' === trim($result) ) {
				return '';
			}
			$source = $result;
		} else {

			do_action( 'plathix/audit/record', 'svg_sanitizer_fallback_used', [] );
		}

		$dom = new \DOMDocument();
		$previous = libxml_use_internal_errors(true);
		$loaded = $dom->loadXML($source, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);

		if ( ! $loaded || ! $dom->documentElement || strtolower($dom->documentElement->tagName) !== 'svg' ) {
			return '';
		}

		if ( $dom->doctype ) {
			$dom->removeChild( $dom->doctype );
		}

		if ( ! $this->cleanDom($dom) ) {
			return '';
		}

		return (string) $dom->saveXML();
	}

	private const ANIMATION_TAGS = [ 'set', 'animate', 'animatemotion', 'animatetransform', 'animatecolor', 'animatecolour' ];

	public function hasUnsafeUseOrImageReference(string $svg): bool {
		if ( '' === trim($svg) || ! class_exists(\DOMDocument::class) ) {
			return false;
		}

		$dom = new \DOMDocument();
		$previous = libxml_use_internal_errors(true);
		$loaded = $dom->loadXML($svg, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);

		if ( ! $loaded || ! $dom->documentElement || strtolower($dom->documentElement->tagName) !== 'svg' ) {
			return false;
		}

		foreach ( $dom->getElementsByTagName('*') as $element ) {
			if ( ! $element instanceof \DOMElement ) {
				continue;
			}

			$tag_name = strtolower($element->tagName);
			if ( ! in_array($tag_name, [ 'use', 'image' ], true) ) {
				continue;
			}

			foreach ( [ 'href', 'xlink:href' ] as $attr ) {
				$value = strtolower(trim($element->getAttribute($attr)));
				if ( '' !== $value && $this->isExternalReference($tag_name, $value) ) {
					return true;
				}
			}
		}

		return false;
	}

	private function isExternalReference(string $tag_name, string $value): bool {
		$value = (string) preg_replace('/[\t\n\r]+/', '', $value);

		return str_starts_with($value, 'javascript:')
			|| str_starts_with($value, 'data:')
			|| preg_match('#^https?://#i', $value) === 1
			|| ( $tag_name === 'use' && ! str_starts_with($value, '#') );
	}

	private const BLOCKED_TAGS = [ 'script', 'foreignobject', 'iframe', 'object', 'embed' ];

	private const PRESENTATION_URL_ATTRIBUTES = [
		'fill',
		'stroke',
		'filter',
		'clip-path',
		'mask',
		'marker-start',
		'marker-mid',
		'marker-end',
		'marker',
		'cursor',
	];

	private function cleanDom(\DOMDocument $dom): bool {

		$to_remove = [];
		foreach ( $dom->getElementsByTagName('*') as $element ) {
			if ( ! $element instanceof \DOMElement ) {
				continue;
			}

			$raw_name = (string) ( $element->localName !== '' ? $element->localName : $element->tagName );
			if ( in_array( strtolower( $raw_name ), self::BLOCKED_TAGS, true ) ) {
				$to_remove[] = $element;
			}
		}

		foreach ( $to_remove as $node ) {
			if ( $node->parentNode ) {
				$node->parentNode->removeChild($node);
			}
		}

		$this->removeHrefAnimationNodes($dom);

		$xpath = new \DOMXPath($dom);
		$all   = $xpath->query('//*');
		if ( ! $all ) {
			return false;
		}

		foreach ( $all as $element ) {
			if ( ! $element instanceof \DOMElement || ! $element->hasAttributes() ) {
				continue;
			}

			$tag_name = strtolower($element->tagName);
			$remove   = [];
			foreach ( $element->attributes as $attribute ) {
				$name  = strtolower($attribute->nodeName);
				$value = strtolower(trim($attribute->nodeValue));

				if ( str_starts_with($name, 'on') ) {
					$remove[] = $attribute->nodeName;
					continue;
				}

				if ( in_array($name, [ 'href', 'xlink:href' ], true) && $this->isExternalReference($tag_name, $value) ) {
					$remove[] = $attribute->nodeName;
				}
			}

			foreach ( $remove as $name ) {
				$element->removeAttribute($name);
			}

			$this->cleanStyleAttribute($element);
			$this->cleanPresentationAttributes($element);
		}

		$this->cleanStyleNodes($dom);

		// Ensure the root SVG element still exists after node removal.
		if ( ! $dom->documentElement || strtolower($dom->documentElement->tagName) !== 'svg' ) {
			return false;
		}

		return true;
	}

	private function removeHrefAnimationNodes(\DOMDocument $dom): void {
		$to_remove = [];
		foreach ( $dom->getElementsByTagName('*') as $element ) {
			if ( ! $element instanceof \DOMElement ) {
				continue;
			}

			$raw_name = (string) ( $element->localName !== '' ? $element->localName : $element->tagName );
			$local    = strtolower( $raw_name );
			if ( ! in_array($local, self::ANIMATION_TAGS, true) ) {
				continue;
			}

			$target = strtolower( trim( html_entity_decode( (string) $element->getAttribute('attributeName'), ENT_QUOTES | ENT_HTML5 ) ) );
			if ( in_array($target, [ 'href', 'xlink:href' ], true) ) {
				$to_remove[] = $element;
			}
		}

		foreach ( $to_remove as $element ) {
			if ( $element->parentNode ) {
				$element->parentNode->removeChild($element);
			}
		}
	}

	private function cleanStyleNodes(\DOMDocument $dom): void {
		$nodes = $dom->getElementsByTagName('style');
		for ( $i = $nodes->length - 1; $i >= 0; $i-- ) {
			$node = $nodes->item($i);
			if ( ! $node instanceof \DOMElement ) {
				continue;
			}

			$css     = $node->textContent;
			$cleaned = $this->sanitizeCssText($css);
			if ( $cleaned !== $css ) {
				$node->textContent = $cleaned;
			}
		}
	}

	private function cleanStyleAttribute(\DOMElement $element): void {
		if ( ! $element->hasAttribute('style') ) {
			return;
		}

		$css     = $element->getAttribute('style');
		$cleaned = $this->sanitizeCssText($css);
		if ( $cleaned !== $css ) {
			$element->setAttribute('style', $cleaned);
		}
	}

	private function cleanPresentationAttributes(\DOMElement $element): void {
		if ( ! $element->hasAttributes() ) {
			return;
		}

		foreach ( iterator_to_array($element->attributes) as $attribute ) {
			$name = strtolower($attribute->nodeName);
			if ( ! in_array($name, self::PRESENTATION_URL_ATTRIBUTES, true) ) {
				continue;
			}

			$value   = $attribute->nodeValue;
			$cleaned = $this->sanitizeCssText($value);
			if ( $cleaned !== $value ) {
				$element->setAttribute($attribute->nodeName, $cleaned);
			}
		}
	}

	private const CSS_FIXPOINT_MAX_ITERATIONS = 128;

	private function sanitizeCssText(string $css): string {
		$reached_fixpoint = false;

		for ( $i = 0; $i < self::CSS_FIXPOINT_MAX_ITERATIONS; $i++ ) {
			$before = $css;

			$css = (string) preg_replace_callback(
				'#url\s*\(\s*([\'"]?)(.*?)\1\s*\)#is',
				static function (array $m): string {
					$value = trim( $m[2] );
					if ( preg_match( '/^#[A-Za-z0-9_-]+$/', $value ) ) {
						return $m[0];
					}
					if ( preg_match( '#^data:image/(?:png|jpe?g|gif|svg\+xml|webp);base64,[A-Za-z0-9+/=]+$#i', $value ) ) {
						return $m[0];
					}
					return 'url()';
				},
				$css
			);

			$css = (string) preg_replace(
				'#@import\s+[\'"][^\'"]*[\'"]\s*;?#i',
				'',
				$css
			);

			if ( $css === $before ) {
				$reached_fixpoint = true;
				break;
			}
		}

		if ( ! $reached_fixpoint ) {
			return '';
		}

		return $css;
	}
}
