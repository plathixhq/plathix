<?php

declare(strict_types=1);

namespace Plathix\Svg;

use Plathix\Svg\Sanitizer\Sanitizer;

/**
 * Единый владелец markup-политики SVG-загрузки ([internal], [internal]).
 *
 * Раньше эта политика была продублирована в двух местах: {@see \Plathix\Modules\Replace\AttachmentReplaceService}
 * (replace-flow) и {@see \Plathix\Modules\Svg\SvgSupport} (обычная загрузка через upload-prefilter).
 * Копии разошлись (у сервиса reject-набор был строже), а сервис держал свою копию даже при
 * отключённом модуле Svg. Этот класс — единственный источник markup-правила, которым делятся
 * ОБА потребителя.
 *
 * Класс НЕЙТРАЛЕН и живёт в core-неймспейсе `Plathix\Svg\` (рядом с {@see Sanitizer}), а НЕ в
 * отключаемом модуле `Modules\Svg`: replace обязан санитайзить SVG всегда (fail-closed),
 * поэтому политика не может зависеть от включённости модуля/наличия хука. Класс НЕ знает про:
 * cap/actor-проверки, `Features`-gate, size-limit, notice-фильтры и логирование — эту обвязку
 * каждый потребитель держит у себя (у сервиса — actor mode system_cli; у SvgSupport — роли,
 * per-user override, notice, rate-limited log).
 *
 * @see \Plathix\Modules\Replace\AttachmentReplaceService::validate_svg_if_needed()
 * @see \Plathix\Modules\Svg\SvgSupport::sanitize_svg_upload()
 */
final class SvgUploadPolicy
{
	public function __construct(
		private readonly Sanitizer $sanitizer = new Sanitizer()
	) {
	}

	/**
	 * Применяет markup-политику к содержимому SVG-файла.
	 *
	 * Шаги: (1) на СЫРОМ входе проверяем detection {@see Sanitizer::hasUnsafeUseOrImageReference()} —
	 * Sanitizer::sanitize() безусловно вырезает внешний href у `<use>`/`<image>` независимо от
	 * safe-mode, поэтому этот факт проверяем ДО очистки, а не после ([internal], OQ-2: проверка
	 * постфактум на очищенной строке была недостижимой веткой — атрибут к тому моменту уже вырезан).
	 * (2) прогон через {@see Sanitizer::sanitize()} — вырезает script/foreignObject/on*-хендлеры/
	 * внешние href и т.п.; пустой выход = файл невалиден/полностью вредоносен → reject. (3) В
	 * safe-mode дополнительно reject'ит остаточные конструкции, которые санитайзер намеренно
	 * пропускает (`<style>` держится ради легитимных стилей) — defense-in-depth поверх Sanitizer.
	 *
	 * Reject-набор в safe-mode: `<use>`/`<image>` с внешней ссылкой во входе (detection на сыром
	 * `$contents`), `<style`, `<foreignobject` (на очищенном `$sanitized` — `<foreignobject>`
	 * Sanitizer тоже вырезает безусловно, эта подстрока остаётся как defense-in-depth на случай
	 * будущего ослабления Sanitizer, см. тест `testForeignObjectSubstringIsNeutralizedBySanitizerBeforeSafeMode`).
	 *
	 * Fail-closed: при любом нарушении возвращает `\WP_Error`, а не молча пропускает.
	 * Класс НЕ применяет notice-фильтр и НЕ логирует — это обвязка потребителя.
	 *
	 * @param string $contents Сырое содержимое загруженного SVG-файла.
	 * @param bool   $safeMode Включён ли safe-mode (дополнительный строгий reject-набор).
	 * @return string|\WP_Error Санитайзенное содержимое или `\WP_Error('invalid_mime')` при reject.
	 */
	public function sanitizeMarkup(string $contents, bool $safeMode): string|\WP_Error {
		if ( $safeMode && $this->sanitizer->hasUnsafeUseOrImageReference( $contents ) ) {
			return new \WP_Error( 'invalid_mime', __( 'SVG file contains unsafe content and was rejected.', 'plathix' ) );
		}

		$sanitized = $this->sanitizer->sanitize( $contents );

		if ( '' === $sanitized ) {
			return new \WP_Error( 'invalid_mime', __( 'SVG file contains unsafe content and was rejected.', 'plathix' ) );
		}

		if ( $safeMode ) {
			$lower = strtolower( $sanitized );
			if (
				str_contains( $lower, '<style' )
				|| str_contains( $lower, '<foreignobject' )
			) {
				return new \WP_Error( 'invalid_mime', __( 'SVG file contains unsafe content and was rejected.', 'plathix' ) );
			}
		}

		return $sanitized;
	}
}
