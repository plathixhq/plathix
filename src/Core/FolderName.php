<?php

declare(strict_types=1);

namespace Plathix\Core;

/**
 * Единый источник правды для валидности/нормализации имени папки ([internal]).
 *
 * До этого класса `FolderTreeService::normalize_name/validate_name` (интерактивный REST-путь)
 * и `Modules\Preset\PresetValidator::validate_structure` (путь импорта пресетов) реализовывали
 * два независимых, расходящихся правила: интерактивный путь молча обрезал имя до 200 символов
 * и не фильтровал control/bidi/zero-width символы вообще; путь импорта отвергал такие имена и
 * не давал их создать. `FolderName` — общий примитив, оба потребителя вызывают его и
 * оборачивают нейтральный результат в свой собственный формат ошибки (`\WP_Error` /
 * `PresetError`), не наоборот — Core не знает ни про HTTP, ни про Modules\Preset.
 */
final class FolderName
{
	public const ERROR_EMPTY = 'empty';
	public const ERROR_LINE_BREAK = 'line_break';
	public const ERROR_DANGEROUS_CHARS = 'dangerous_chars';
	public const ERROR_TOO_LONG_CHARS = 'too_long_chars';
	public const ERROR_TOO_LONG_BYTES = 'too_long_bytes';

	/** Схлопывает пробелы, обрезает до 200 символов. Единый источник для всех путей создания папки. */
	public static function normalize(string $name): string {
		return mb_substr( trim( preg_replace( '/\s+/', ' ', $name ) ?? '' ), 0, 200 );
	}

	/**
	 * Валидирует уже нормализованное имя. Пустой список = валидно.
	 *
	 * `$mbLimit` — опциональный символьный (mb-safe) верхний порог сверх байтового лимита
	 * 200 (жёсткий предел `wp_terms.name` — `varchar(200)` в байтах, не параметризуется).
	 * Путь импорта пресетов передаёт 150 (собственный, более строгий продуктовый лимит);
	 * интерактивный REST-путь не передаёт ничего — сохраняет текущее отсутствие
	 * символьного ограничения сверх обрезки в `normalize()`.
	 *
	 * @return list<string>
	 */
	public static function validate(string $normalized, ?int $mbLimit = null): array {
		$errors = [];

		if ( $normalized === '' ) {
			$errors[] = self::ERROR_EMPTY;
		}

		// Переводы строк физически разорвали бы строку структуры пресета — отдельный код
		// от общего control/bidi-паттерна ниже, т.к. потребители дают им разный текст ошибки.
		if ( preg_match( '/[\r\n]/', $normalized ) === 1 ) {
			$errors[] = self::ERROR_LINE_BREAK;
		}

		// Управляющие символы C0/C1, bidi-override и нулевой ширины: не видны в UI, но
		// позволяют выдать папку за системную («Trash» с подменённым порядком символов) и
		// дают недетерминированное поведение при передаче имени в term_exists()/wp_insert_term().
		if ( preg_match( '/[\x00-\x1F\x7F\x{0080}-\x{009F}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', $normalized ) === 1 ) {
			$errors[] = self::ERROR_DANGEROUS_CHARS;
		}

		if ( null !== $mbLimit && mb_strlen( $normalized ) > $mbLimit ) {
			$errors[] = self::ERROR_TOO_LONG_CHARS;
		}

		// Колонка term.name в WP — varchar(200) в БАЙТАХ: многобайтовые (эмодзи) имена
		// могут пройти символьный лимит и всё равно превысить байтовый предел БД, MySQL
		// обрежет имя посреди многобайтовой последовательности без этого guard'а.
		if ( strlen( $normalized ) > 200 ) {
			$errors[] = self::ERROR_TOO_LONG_BYTES;
		}

		return $errors;
	}
}
