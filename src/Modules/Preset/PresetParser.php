<?php

declare(strict_types=1);

namespace Plathix\Modules\Preset;

final class PresetParser
{

	private const INDENT_WIDTH = 2;

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
	public function parseMarkdown(string $markdown): array {
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

		$seenFields = [];

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

				if ( $metadataErrors >= self::MAX_ERRORS_PER_SECTION ) {
					continue;
				}

				if ( ! preg_match('/^([A-Za-z]+):\s*(.*)$/', $trimmed, $matches) ) {
					$errors[] = (new PresetError(
						'preset_invalid_metadata_line',
						__('Invalid metadata line.', 'plathix'),
						$lineNumber,
						'metadata'
					))->toArray();
					$metadataErrors++;
					continue;
				}

				$key = $matches[1];
				$value = $matches[2];

				if ( ! in_array($key, self::METADATA_ORDER, true) ) {

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
						))->toArray();
						$metadataErrors++;
						continue;
					}

					continue;
				}

				if ( isset($seenFields[$key]) ) {
					$errors[] = (new PresetError(
						'preset_duplicate_field',
						__('Metadata field is defined more than once.', 'plathix'),
						$lineNumber,
						'metadata'
					))->toArray();

					$metadataErrors++;
					continue;
				}

				$seenFields[$key] = true;

				switch ( $key ) {
					case 'FormatVersion':

						if ( preg_match('/^[0-9]+$/', trim($value)) !== 1 ) {
							$errors[] = (new PresetError(
								'preset_invalid_format_version',
								__('Format version must be a whole number.', 'plathix'),
								$lineNumber,
								'metadata'
							))->toArray();
							break 2;
						}

						$declared = (int) trim($value);
						if ( $declared > PresetFormat::FORMAT_VERSION ) {
							$errors[] = (new PresetError(
								'preset_unsupported_format_version',
								__('This preset was created by a newer version of Plathix. Update the plugin to use it.', 'plathix'),
								$lineNumber,
								'metadata'
							))->toArray();
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

			if ( $structureErrors >= self::MAX_ERRORS_PER_SECTION ) {
				$skippedLines = count($lines) - $index;
				break;
			}

			if ( preg_match('/^\t+/', $line) === 1 ) {
				$errors[] = (new PresetError(
					'preset_tab_indent',
					__('Structure indentation must use spaces, not tabs.', 'plathix'),
					$lineNumber,
					'structure'
				))->toArray();
				$structureErrors++;
				continue;
			}

			if ( preg_match('/^\s*[0-9]+(?:\.[0-9]+)*\s*:\s*Folder\(/', $line) === 1 ) {
				$errors[] = (new PresetError(
					'preset_legacy_grammar',
					__('This preset uses the old format. Convert it with bin/convert-preset-format.php.', 'plathix'),
					$lineNumber,
					'structure'
				))->toArray();
				break;
			}

			if ( ! preg_match('/^( *)- (".*")(?:\s*\{(.*)\})?\s*$/', $line, $matches) ) {
				$errors[] = (new PresetError(
					'preset_invalid_structure_line',
					__('Invalid structure line.', 'plathix'),
					$lineNumber,
					'structure'
				))->toArray();
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
				))->toArray();
				$structureErrors++;
				continue;
			}

			$depth = intdiv($indent, self::INDENT_WIDTH);

			if ( $depth > $previousDepth + 1 ) {
				$errors[] = (new PresetError(
					'preset_indent_jump',
					__('Structure line is nested deeper than its parent allows.', 'plathix'),
					$lineNumber,
					'structure'
				))->toArray();
				$structureErrors++;
				continue;
			}

			$name = PresetFormat::unescapeName($matches[2]);

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
				))->toArray();
				$structureErrors++;
				continue;
			}

			$attributes = $this->parseAttributes($matches[3] ?? '');
			if ( $attributes === null ) {
				$errors[] = (new PresetError(
					'preset_invalid_structure_attributes',
					__('Invalid folder attributes.', 'plathix'),
					$lineNumber,
					'structure'
				))->toArray();
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
			))->toArray();
		}

		if ( ! $inStructure && $errors === [] ) {
			$errors[] = (new PresetError(
				'preset_missing_structure_section',
				__('Missing ## Structure section.', 'plathix'),
				null,
				'structure'
			))->toArray();
		}

		return [
			'preset' => $preset,
			'errors' => $errors,
			'valid' => $errors === [],
		];
	}

	/**
	 * @return array{color: string, favorite: bool}|null
	 */

	private function parseAttributes(string $raw): ?array {
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
