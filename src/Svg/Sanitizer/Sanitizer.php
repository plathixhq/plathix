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
		}

		$dom = new \DOMDocument();
		$previous = libxml_use_internal_errors(true);
		$loaded = $dom->loadXML($source, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);

		if ( ! $loaded || ! $dom->documentElement || strtolower($dom->documentElement->tagName) !== 'svg' ) {
			return '';
		}

		if ( ! $this->clean_dom($dom) ) {
			return '';
		}

		return (string) $dom->saveXML();
	}

	/** Локальные имена animation-тегов (SMIL), способных подменять целевой атрибут в рантайме. */
	private const ANIMATION_TAGS = [ 'set', 'animate', 'animatemotion', 'animatetransform', 'animatecolor', 'animatecolour' ];

	/**
	 * Детектирует, содержит ли ИСХОДНЫЙ (ещё не очищенный) SVG `<use>`/`<image>` с внешней
	 * ссылкой ([internal], safe-mode reject). Чистая функция чтения — не мутирует DOM, не
	 * вызывает enshrined, ничего не удаляет.
	 *
	 * Вызывать ОБЯЗАТЕЛЬНО на сыром вводе, ДО {@see sanitize()}: сам sanitize() безусловно
	 * вырезает эти атрибуты независимо от safe-mode, поэтому после него факт присутствия
	 * опасной ссылки уже недоступен для проверки — этот метод существует именно чтобы safe-mode
	 * увидел признак ДО того, как sanitize() его нормализует.
	 *
	 * Критерий "внешняя ссылка" идентичен тому, что {@see clean_dom} использует для удаления
	 * атрибута — {@see is_external_reference()} — чтобы detection и очистка не расходились.
	 */
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
				if ( '' !== $value && $this->is_external_reference($tag_name, $value) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Признак "внешняя ссылка" для href/xlink:href: javascript:/data:/http(s):// схемы, либо
	 * (для `<use>`) любое значение без ведущего `#` — same-document fragment refs безопасны,
	 * всё остальное для `<use>` трактуется как внешнее по политике ([internal]).
	 */
	private function is_external_reference(string $tag_name, string $value): bool {
		return str_starts_with($value, 'javascript:')
			|| str_starts_with($value, 'data:')
			|| preg_match('#^https?://#i', $value) === 1
			|| ( $tag_name === 'use' && ! str_starts_with($value, '#') );
	}

	private function clean_dom(\DOMDocument $dom): bool {
		$blocked_tags = [ 'script', 'foreignObject', 'iframe', 'object', 'embed' ];
		foreach ( $blocked_tags as $tag ) {
			$nodes = $dom->getElementsByTagName($tag);
			for ( $i = $nodes->length - 1; $i >= 0; $i-- ) {
				$node = $nodes->item($i);
				if ( $node && $node->parentNode ) {
					$node->parentNode->removeChild($node);
				}
			}
		}

		$this->remove_href_animation_nodes($dom);

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

				if ( in_array($name, [ 'href', 'xlink:href' ], true) && $this->is_external_reference($tag_name, $value) ) {
					$remove[] = $attribute->nodeName;
				}
			}

			foreach ( $remove as $name ) {
				$element->removeAttribute($name);
			}

			$this->clean_style_attribute($element);
		}

		$this->clean_style_nodes($dom);

		// Ensure the root SVG element still exists after node removal.
		if ( ! $dom->documentElement || strtolower($dom->documentElement->tagName) !== 'svg' ) {
			return false;
		}

		return true;
	}

	/**
	 * Удаляет animation-теги (set/animate/animateMotion/animateTransform/animateColor),
	 * целящиеся в href/xlink:href ([internal]). SMIL-движок браузера в рантайме подменяет
	 * href целевого элемента на значение to/values — так `values="javascript:…"` даёт XSS,
	 * минуя проверку прямых href-атрибутов.
	 *
	 * Критерий удаления — только нормализованный attributeName ∈ {href, xlink:href}, НЕ значение
	 * (проверка значения обходится обфускацией to/values: entity, `java\tscript:`, пробелы —
	 * браузер декодирует схему, санитайзер бы матчил сырую строку). Легитимная анимация
	 * (fill/opacity/transform/геометрия) целится в другой attributeName и остаётся нетронутой;
	 * файл принимается, не отклоняется целиком.
	 *
	 * Автономно от версии enshrined: часть этих тегов текущий vendor режет сам, но allowlist —
	 * деталь версии, санитайзер обязан блокировать весь класс независимо.
	 */
	private function remove_href_animation_nodes(\DOMDocument $dom): void {
		$to_remove = [];
		foreach ( $dom->getElementsByTagName('*') as $element ) {
			if ( ! $element instanceof \DOMElement ) {
				continue;
			}

			// localName — имя без namespace-префикса, ловит и <animateMotion>, и <svg:animateMotion>.
			$raw_name = (string) ( $element->localName !== '' ? $element->localName : $element->tagName );
			$local    = strtolower( $raw_name );
			if ( ! in_array($local, self::ANIMATION_TAGS, true) ) {
				continue;
			}

			// Нормализация цели: entity-decode (обход `&#104;ref`) + trim + lowercase (обход `HREF`).
			$target = strtolower( trim( html_entity_decode( (string) $element->getAttribute('attributeName'), ENT_QUOTES | ENT_HTML5 ) ) );
			if ( in_array($target, [ 'href', 'xlink:href' ], true) ) {
				$to_remove[] = $element;
			}
		}

		// Удаляем отдельным проходом по snapshot — не мутируем live-коллекцию во время итерации.
		foreach ( $to_remove as $element ) {
			if ( $element->parentNode ) {
				$element->parentNode->removeChild($element);
			}
		}
	}

	private function clean_style_nodes(\DOMDocument $dom): void {
		$nodes = $dom->getElementsByTagName('style');
		for ( $i = $nodes->length - 1; $i >= 0; $i-- ) {
			$node = $nodes->item($i);
			if ( ! $node instanceof \DOMElement ) {
				continue;
			}

			$css     = $node->textContent;
			$cleaned = $this->sanitize_css_text($css);
			if ( $cleaned !== $css ) {
				$node->textContent = $cleaned;
			}
		}
	}

	/**
	 * Применяет ту же CSS url()-allowlist политику, что {@see clean_style_nodes} — [internal]:
	 * `style="..."` — второй носитель CSS в SVG, не покрытый политикой [internal], которая
	 * чистила только элемент `<style>`. Один и тот же {@see sanitize_css_text}, чтобы оба
	 * носителя не могли разойтись так же, как элемент и атрибут разошлись до этого пакета.
	 */
	private function clean_style_attribute(\DOMElement $element): void {
		if ( ! $element->hasAttribute('style') ) {
			return;
		}

		$css     = $element->getAttribute('style');
		$cleaned = $this->sanitize_css_text($css);
		if ( $cleaned !== $css ) {
			$element->setAttribute('style', $cleaned);
		}
	}

	/**
	 * Единая CSS-политика для обоих носителей (`<style>`-элемент и атрибут `style=""`) —
	 * [internal] (url() allowlist) + [internal] (охват второго носителя + @import строковая
	 * форма). Порядок неважен: оба прохода независимы (@import строковая форма не содержит
	 * `url(`, url()-allowlist не матчит `@import "..."` без `url()`), поэтому пересечения нет.
	 */
	private function sanitize_css_text(string $css): string {
		// Allowlist (не denylist) внутри url() — [internal], WP Security skeptic verdict:
		// denylist на конкретную опасную схему (было: только https?://) наследует тот же
		// класс обхода, что уже был закрыт для href/animateMotion в [internal] — CSS-парсер
		// браузера декодирует unicode-escape (\6a avascript:), допускает CSS-комментарии
		// и whitespace внутри схемы (java/**/script:, java\tscript:), которые regex-denylist
		// на конкретную строку не ловит. Позитивный allowlist не обходится этими трюками:
		// он не матчит НИЧЕГО постороннего, независимо от кодирования содержимого.
		// Разрешено: url(#fragment) — внутренние SVG-ссылки (градиенты/паттерны/маски);
		// url(data:image/<safe-subtype>;base64,...) — растровые изображения инлайн
		// (raster embedded images tradeoff). Всё остальное (http/https/javascript/ftp/
		// относительные пути без схемы/произвольный data: не-image MIME) вырезается.
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

		// [internal]: @import в строковой форме (без url()) — не матчится regex'ом выше,
		// который требует литерал `url(`. @import по CSS-спецификации всегда указывает на
		// внешний stylesheet — легитимного безопасного значения (в отличие от url()) нет,
		// поэтому строковая форма вырезается целиком, а не проходит через allowlist.
		$css = (string) preg_replace(
			'#@import\s+[\'"][^\'"]*[\'"]\s*;?#i',
			'',
			$css
		);

		return $css;
	}
}
