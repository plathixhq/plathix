<?php

declare(strict_types=1);

namespace Plathix\Modules\Preset;

final class PresetParser
{
	/** Ширина одного уровня вложенности в пробелах. */
	private const INDENT_WIDTH = 2;

	/**
	 * Сколько ошибок на секцию собирать, прежде чем прекратить разбор.
	 *
	 * Голое накопление вредно не меньше выхода по первой ошибке: забытые
	 * кавычки в структуре из 200 строк дадут 200 одинаковых записей, и отчёт
	 * станет нечитаем. Пяти достаточно, чтобы автор увидел закономерность.
	 * Бюджеты шапки и структуры раздельные — битый заголовок не должен
	 * скрывать проблемы дерева.
	 */
	private const MAX_ERRORS_PER_SECTION = 5;

	private const METADATA_ORDER = [
		'FormatVersion',
		'Title',
		'Slug',
		'Version',
		'Description',
		'Preview',
		'Tags',
		'Author',
		'AuthorURL',
		'Generator',
	];

	/** @return array{preset: array<string, mixed>, errors: array<int, array<string, mixed>>, valid: bool} */
	public function parse_markdown(string $markdown): array {
		$lines = preg_split("/\r\n|\n|\r/", $markdown) ?: [];
		$preset = [
			'title' => '',
			'slug' => '',
			'version' => '',
			'description' => '',
			'preview' => '',
			'tags' => [],
			'author' => '',
			'author_url' => '',
			'format_version' => PresetFormat::FORMAT_VERSION,
			'generator' => '',
			'structure' => [],
		];
		$errors = [];
		$inStructure = false;
		/** @var array<string, true> Уже встреченные поля шапки — для контроля дубликатов. */
		$seenFields = [];
		// -1, чтобы первая строка структуры допускала только depth 0: у корневой
		// папки нет родителя, глубже начинать нельзя.
		$previousDepth = -1;
		$structureErrors = 0;
		$metadataErrors = 0;
		$skippedLines = 0;

		foreach ( $lines as $index => $rawLine ) {
			$lineNumber = $index + 1;
			$line = (string) $rawLine;
			$trimmed = trim($line);

			if ( $trimmed === '' ) {
				continue;
			}

			if ( ! $inStructure ) {
				if ( $trimmed === '## Structure' ) {
					$inStructure = true;
					continue;
				}

				// Бюджет шапки исчерпан — пропускаем остаток метаданных, но не
				// файл целиком: проблемы дерева важнее ещё одной кривой строки
				// заголовка и обязаны попасть в отчёт.
				if ( $metadataErrors >= self::MAX_ERRORS_PER_SECTION ) {
					continue;
				}

				if ( ! preg_match('/^([A-Za-z]+):\s*(.*)$/', $trimmed, $matches) ) {
					$errors[] = (new PresetError(
						'preset_invalid_metadata_line',
						__('Invalid metadata line.', 'plathix'),
						$lineNumber,
						'metadata'
					))->to_array();
					$metadataErrors++;
					continue;
				}

				$key = $matches[1];
				$value = $matches[2];

				// Порядок полей произволен: позиционный курсор с проматыванием
				// опциональных полей снесён вместе с самой идеей «поля идут в
				// фиксированной последовательности». Он требовал спецслучая под
				// каждое новое опциональное поле и уже накопил два костыля.
				if ( ! in_array($key, self::METADATA_ORDER, true) ) {
					// Ключ, отличающийся ТОЛЬКО регистром, — почти наверняка
					// опечатка автора, а не расширение формата, поэтому получает
					// собственное сообщение вместо молчаливого пропуска.
					$caseMismatch = false;
					foreach ( self::METADATA_ORDER as $known ) {
						if ( strcasecmp($known, $key) === 0 ) {
							$caseMismatch = true;
							break;
						}
					}

					if ( $caseMismatch ) {
						$errors[] = (new PresetError(
							'preset_invalid_key_case',
							__('Metadata field name has wrong letter case.', 'plathix'),
							$lineNumber,
							'metadata'
						))->to_array();
						$metadataErrors++;
						continue;
					}

					// Неизвестное поле игнорируется молча: формат эволюционирует
					// аддитивно, и файл, написанный более новым генератором, не
					// должен падать из-за поля, которого этот парсер не знает.
					// Правило действует ТОЛЬКО в шапке — в `## Structure`
					// неизвестная строка остаётся фатальной, потому что там она
					// означает потерянную папку, а не потерянные метаданные.
					continue;
				}

				if ( isset($seenFields[$key]) ) {
					$errors[] = (new PresetError(
						'preset_duplicate_field',
						__('Metadata field is defined more than once.', 'plathix'),
						$lineNumber,
						'metadata'
					))->to_array();
					// Значение берём от ПЕРВОГО вхождения: второе уже забраковано,
					// доверять помеченному ошибкой нельзя.
					$metadataErrors++;
					continue;
				}

				$seenFields[$key] = true;

				switch ( $key ) {
					case 'FormatVersion':
						// Строго целое: `2.0` и пустое значение — ошибка, а не
						// «примерно двойка». Поле написано — значит автор что-то
						// имел в виду, и молча подставлять дефолт нельзя.
						if ( preg_match('/^[0-9]+$/', trim($value)) !== 1 ) {
							$errors[] = (new PresetError(
								'preset_invalid_format_version',
								__('Format version must be a whole number.', 'plathix'),
								$lineNumber,
								'metadata'
							))->to_array();
							break 2;
						}

						$declared = (int) trim($value);
						if ( $declared > PresetFormat::FORMAT_VERSION ) {
							$errors[] = (new PresetError(
								'preset_unsupported_format_version',
								__('This preset was created by a newer version of Plathix. Update the plugin to use it.', 'plathix'),
								$lineNumber,
								'metadata'
							))->to_array();
							break 2;
						}

						$preset['format_version'] = $declared;
						break;
					case 'Generator':
						$preset['generator'] = $value;
						break;
					case 'Title':
						$preset['title'] = $value;
						break;
					case 'Slug':
						$preset['slug'] = $value;
						break;
					case 'Version':
						$preset['version'] = $value;
						break;
					case 'Description':
						$preset['description'] = $value;
						break;
					case 'Preview':
						$preset['preview'] = $value;
						break;
					case 'Tags':
						$preset['tags'] = $value === '' ? [] : array_map('trim', explode(',', $value));
						break;
					case 'Author':
						$preset['author'] = $value;
						break;
					case 'AuthorURL':
						$preset['author_url'] = $value;
						break;
				}

				continue;
			}

			// Лимит достигнут — дальше не разбираем, но честно говорим, сколько
			// строк осталось непроверенными, чтобы автор понимал масштаб.
			if ( $structureErrors >= self::MAX_ERRORS_PER_SECTION ) {
				$skippedLines = count($lines) - $index;
				break;
			}

			// Таб в отступе — явная ошибка, а не молчаливая нормализация: смешение
			// табов и пробелов даёт дерево, не совпадающее с тем, что видит автор.
			if ( preg_match('/^\t+/', $line) === 1 ) {
				$errors[] = (new PresetError(
					'preset_tab_indent',
					__('Structure indentation must use spaces, not tabs.', 'plathix'),
					$lineNumber,
					'structure'
				))->to_array();
				$structureErrors++;
				continue;
			}

			// Легаси-грамматика опознаётся ПО СОДЕРЖИМОМУ, а не по номеру версии:
			// поля `FormatVersion` не существовало, когда такие файлы писались,
			// поэтому объявленной версии в них нет и быть не может. Сквозная
			// нумерация `1.2: Folder(Name)` синтаксически не пересекается с новой
			// грамматикой, что и делает её надёжной приметой.
			if ( preg_match('/^\s*[0-9]+(?:\.[0-9]+)*\s*:\s*Folder\(/', $line) === 1 ) {
				$errors[] = (new PresetError(
					'preset_legacy_grammar',
					__('This preset uses the old format. Convert it with bin/convert-preset-format.php.', 'plathix'),
					$lineNumber,
					'structure'
				))->to_array();
				break;
			}

			// Хвостовые пробелы больше не ломают строку: до [internal] они
			// делали файл невалидным с сообщением, не указывающим на причину.
			if ( ! preg_match('/^( *)- (".*")(?:\s*\{(.*)\})?\s*$/', $line, $matches) ) {
				$errors[] = (new PresetError(
					'preset_invalid_structure_line',
					__('Invalid structure line.', 'plathix'),
					$lineNumber,
					'structure'
				))->to_array();
				$structureErrors++;
				continue;
			}

			$indent = strlen($matches[1]);
			if ( $indent % self::INDENT_WIDTH !== 0 ) {
				$errors[] = (new PresetError(
					'preset_invalid_indent',
					__('Structure indentation must be a multiple of two spaces.', 'plathix'),
					$lineNumber,
					'structure'
				))->to_array();
				$structureErrors++;
				continue;
			}

			$depth = intdiv($indent, self::INDENT_WIDTH);
			// Глубина растёт только на один уровень за строку: родителем является
			// ближайшая строка меньшей глубины, поэтому скачок оставил бы папку
			// без родителя. Первая строка обязана быть корневой (depth 0).
			if ( $depth > $previousDepth + 1 ) {
				$errors[] = (new PresetError(
					'preset_indent_jump',
					__('Structure line is nested deeper than its parent allows.', 'plathix'),
					$lineNumber,
					'structure'
				))->to_array();
				$structureErrors++;
				continue;
			}

			$name = PresetFormat::unescape_name($matches[2]);
			// NFC до проверки длины: без нормализации «й» как база+комбинирующий
			// символ и как готовый код-поинт дают разную длину и разные термы.
			// ext-intl не гарантирован на хостинге — без него имя идёт как есть.
			if ( $name !== null && class_exists(\Normalizer::class) ) {
				$normalized = \Normalizer::normalize($name, \Normalizer::FORM_C);
				if ( is_string($normalized) ) {
					$name = $normalized;
				}
			}
			if ( $name === null ) {
				$errors[] = (new PresetError(
					'preset_invalid_folder_quoting',
					__('Folder name must be wrapped in double quotes.', 'plathix'),
					$lineNumber,
					'structure'
				))->to_array();
				$structureErrors++;
				continue;
			}

			$attributes = $this->parse_attributes($matches[3] ?? '');
			if ( $attributes === null ) {
				$errors[] = (new PresetError(
					'preset_invalid_structure_attributes',
					__('Invalid folder attributes.', 'plathix'),
					$lineNumber,
					'structure'
				))->to_array();
				$structureErrors++;
				continue;
			}

			$preset['structure'][] = [
				'depth' => $depth,
				'name' => $name,
				'color' => $attributes['color'],
				'favorite' => $attributes['favorite'],
				'line' => $lineNumber,
			];

			$previousDepth = $depth;
		}

		// Маркер обрыва: без него «5 ошибок» неотличимо от «всего 5 проблем»,
		// и автор решит, что починил всё, хотя проверена лишь часть файла.
		if ( $skippedLines > 0 ) {
			$errors[] = (new PresetError(
				'preset_too_many_errors',
				sprintf(
					/* translators: %d: number of structure lines left unchecked. */
					__('Too many errors: %d more structure lines were not checked.', 'plathix'),
					$skippedLines
				),
				null,
				'structure'
			))->to_array();
		}

		if ( ! $inStructure && $errors === [] ) {
			$errors[] = (new PresetError(
				'preset_missing_structure_section',
				__('Missing ## Structure section.', 'plathix'),
				null,
				'structure'
			))->to_array();
		}

		return [
			'preset' => $preset,
			'errors' => $errors,
			'valid' => $errors === [],
		];
	}

	/**
	 * Разбирает содержимое опциональной секции `{color: …, favorite}`.
	 *
	 * Отсутствие секции равнозначно `{color: default}` без флага избранного —
	 * поэтому 16 строк из 18 в типовом пресете больше не несут `Color(default)`.
	 * Значение `color` здесь не валидируется по формату: это задача
	 * PresetValidator, парсер отвечает только за синтаксис.
	 *
	 * @return array{color: string, favorite: bool}|null null при синтаксической ошибке.
	 */
	private function parse_attributes(string $raw): ?array {
		$result = [
			'color' => 'default',
			'favorite' => false,
		];

		$trimmed = trim($raw);
		if ( $trimmed === '' ) {
			return $result;
		}

		$colorSeen = false;
		$favoriteSeen = false;

		foreach ( explode(',', $trimmed) as $part ) {
			$part = trim($part);

			if ( $part === 'favorite' ) {
				// Повтор флага — синтаксическая ошибка, а не «последний выигрывает»:
				// молчаливое проглатывание мусора скрывает опечатку в генераторе.
				if ( $favoriteSeen ) {
					return null;
				}

				$favoriteSeen = true;
				$result['favorite'] = true;
				continue;
			}

			if ( preg_match('/^color\s*:\s*(\S+)$/', $part, $matches) === 1 ) {
				if ( $colorSeen ) {
					return null;
				}

				$colorSeen = true;
				$result['color'] = $matches[1];
				continue;
			}

			return null;
		}

		return $result;
	}
}
