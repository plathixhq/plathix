<?php

declare(strict_types=1);

namespace Plathix\Modules\Preset;

use Plathix\Core\FolderName;

final class PresetValidator
{
	public function __construct(
		private readonly PresetParser $parser = new PresetParser(),
		private readonly ?PresetRepository $repository = null
	) {
	}

	/** @return array{preset: array<string, mixed>, errors: array<int, array<string, mixed>>, valid: bool} */
	public function validate_markdown(string $markdown): array {
		$parsed = $this->parser->parse_markdown($markdown);
		if ( ! $parsed['valid'] ) {
			return $parsed;
		}

		$preset = $parsed['preset'];
		$errors = [];
		$errors = array_merge($errors, $this->validate_metadata($preset));
		$errors = array_merge($errors, $this->validate_structure($preset));

		return [
			'preset' => $preset,
			'errors' => $errors,
			'valid' => $errors === [],
		];
	}

	/** @param array<string, mixed> $preset
	 *  @return array<int, array<string, mixed>>
	 */
	public function validate_metadata(array $preset): array {
		$errors = [];

		// `preview` намеренно не в списке: картинка — требование витрины, а не
		// формата. Автор, описывающий структуру папок, не обязан рисовать превью;
		// пустое поле лучше, чем ссылка на несуществующий файл.
		foreach ( ['title', 'slug', 'version', 'description', 'author'] as $required ) {
			if ( trim( (string) ($preset[$required] ?? '')) === '' ) {
				$errors[] = (new PresetError(
					'preset_missing_required_metadata',
					__('Missing required metadata field.', 'plathix'),
					null,
					'metadata'
				))->to_array();
				return $errors;
			}
		}

		// `Version` — версия ПРЕСЕТА (контента), свободная строка: автор вправе
		// выпустить вторую редакцию своей структуры папок. Версия ФОРМАТА живёт
		// отдельно в `FormatVersion` и проверяется парсером. До [internal] эти две
		// сущности были смешаны в одном поле, требовавшем строго '1', — из-за
		// чего автор, обновивший свой пресет до '2', получал невнятный отказ.
		if ( trim( (string) $preset['version']) === '' ) {
			$errors[] = (new PresetError(
				'preset_invalid_version',
				__('Preset version cannot be empty.', 'plathix'),
				null,
				'metadata'
			))->to_array();
		}

		$slug = sanitize_key( (string) $preset['slug']);
		if ( $slug === '' || $slug !== (string) $preset['slug'] ) {
			$errors[] = (new PresetError(
				'preset_invalid_slug',
				__('Invalid preset slug.', 'plathix'),
				null,
				'metadata'
			))->to_array();
		}

		// Preview опционален; если указан — обязан быть одним из канонических имён.
		// Раньше проверялось лишь отсутствие слешей, а реальная защита держалась на
		// совпадении с whitelist в PresetUploadPipeline — инвариант, который сам
		// валидатор не выражал (`..`, нулевой байт и `.htaccess` он пропускал).
		$preview = (string) ($preset['preview'] ?? '');
		if ( $preview !== '' && ! in_array($preview, PresetFormat::ALLOWED_PREVIEWS, true) ) {
			$errors[] = (new PresetError(
				'preset_invalid_preview',
				__('Preview must be one of: preview.webp, preview.png, preview.jpg, preview.jpeg.', 'plathix'),
				null,
				'metadata'
			))->to_array();
		}

		foreach ( (array) ($preset['tags'] ?? []) as $tag ) {
			$tag = trim( (string) $tag);
			if ( $tag === '' || ! str_starts_with($tag, '#') ) {
				$errors[] = (new PresetError(
					'preset_invalid_tags',
					__('Invalid tags format.', 'plathix'),
					null,
					'metadata'
				))->to_array();
				break;
			}
		}

		if ( $this->repository instanceof PresetRepository ) {
			$existing = $this->repository->find_by_slug( (string) $preset['slug']);

			// Слаг встроенного пресета зарезервирован. Спека §26.4 запрещает
			// удалять built-in (гард в PresetPostActions при delete), а перезапись
			// загрузкой сильнее удаления: подменяет содержимое одним ходом. Без
			// этой проверки чужой файл со `Slug: restaurant` затирал бы встроенный
			// пресет — непостоянно (BuiltInPresetDiscovery::discover() при
			// следующем открытии страницы вернёт его из файлов плагина), но с
			// молчаливой потерей того, что загрузил пользователь.
			if ( $existing !== null && (string) ($existing['source_type'] ?? '') === PresetSourceType::BUILTIN ) {
				$errors[] = (new PresetError(
					'preset_builtin_slug_reserved',
					__('This slug is reserved by a built-in preset. Rename the preset before uploading.', 'plathix'),
					null,
					'metadata'
				))->to_array();

				return $errors;
			}

			// Три исхода при совпадении слага:
			//  1. структура отличается   → не ошибка: новый пресет, которому не
			//     повезло с именем; суффикс к слагу применит репозиторий;
			//  2. структура и версия совпали → буквальный дубль, ошибка;
			//  3. структура та же, версия другая → не ошибка: автор выпустил
			//     вторую редакцию своей структуры (см. Version выше), запись
			//     обновляется пайплайном через upsert_by_slug.
			//
			// Версии сравниваются строгим неравенством: `Version` — свободная
			// строка, старшинство на ней не определено (`version_compare` даст
			// мусор на `v2` против `2`), да оно здесь и не нужно — понижение
			// версии это осознанный откат автором собственной записи.
			if (
				$existing !== null
				&& trim( (string) ($preset['version'] ?? '')) === trim( (string) ($existing['version'] ?? ''))
				&& $this->structures_equal(
					(array) ($preset['structure'] ?? []),
					(array) ($existing['structure'] ?? [])
				)
			) {
				$errors[] = (new PresetError(
					'preset_slug_conflict',
					__('This preset already exists.', 'plathix'),
					null,
					'metadata'
				))->to_array();
			}
		}

		return $errors;
	}

	/**
	 * Сравнивает две структуры папок пресета на смысловое равенство.
	 *
	 * Сравнивается только то, что определяет «тот же пресет»: depth (позиция в
	 * дереве), name (имя папки), color. Ключ `line` (номер строки в исходном файле)
	 * исключается — он зависит от форматирования файла, а не от содержимого.
	 * Порядок строк значим: в грамматике на отступах он и есть структура дерева,
	 * поэтому сортировка (как было при сквозной нумерации) здесь не применяется.
	 *
	 * @param array<int, array<string, mixed>> $a
	 * @param array<int, array<string, mixed>> $b
	 */
	private function structures_equal(array $a, array $b): bool {
		return $this->structures_match($a, $b);
	}

	/**
	 * Публичная форма сравнения структур: нужна upload-пайплайну, чтобы решить,
	 * обновлять существующую запись или создавать новую. Логика та же, что у
	 * внутренней проверки конфликта, — второй копии правил сравнения быть не
	 * должно, иначе валидатор и пайплайн разъедутся в оценке «тот же пресет».
	 *
	 * @param array<int, array<string, mixed>> $a
	 * @param array<int, array<string, mixed>> $b
	 */
	public function structures_match(array $a, array $b): bool {
		return $this->normalize_structure($a) === $this->normalize_structure($b);
	}

	/**
	 * @param array<int, array<string, mixed>> $structure
	 * @return list<array{depth:int,name:string,color:string}>
	 */
	private function normalize_structure(array $structure): array {
		$normalized = [];
		foreach ( $structure as $folder ) {
			$normalized[] = [
				'depth'     => (int) ($folder['depth'] ?? 0),
				'name'      => (string) ($folder['name'] ?? ''),
				'color'     => (string) ($folder['color'] ?? ''),
			];
		}

		return $normalized;
	}

	/** @param array<string, mixed> $preset
	 *  @return array<int, array<string, mixed>>
	 */
	public function validate_structure(array $preset): array {
		$errors = [];
		$structure = (array) ($preset['structure'] ?? []);

		if ( count($structure) > 200 ) {
			$errors[] = (new PresetError(
				'preset_structure_limit_exceeded',
				__('Preset exceeds the maximum number of structure lines.', 'plathix'),
				null,
				'structure'
			))->to_array();
			return $errors;
		}

		foreach ( $structure as $entry ) {
			$line = (int) ($entry['line'] ?? 0);
			$depth = (int) ($entry['depth'] ?? 0);
			$name = (string) ($entry['name'] ?? '');
			$color = (string) ($entry['color'] ?? '');

			if ( $name === '' ) {
				$errors[] = (new PresetError('preset_invalid_folder_name', __('Folder name cannot be empty.', 'plathix'), $line, 'structure'))->to_array();
			}
			// [internal]: правило control/bidi/zero-width + длина переехало в общий
			// Core\FolderName (единый источник с интерактивным REST-путём,
			// FolderTreeService). Тексты ошибок не меняются — маппинг 1:1 со старым inline-кодом.
			foreach ( FolderName::validate( $name, 150 ) as $code ) {
				$message = match ( $code ) {
					FolderName::ERROR_LINE_BREAK => __('Folder name contains forbidden characters.', 'plathix'),
					FolderName::ERROR_DANGEROUS_CHARS => __('Folder name contains control or bidirectional characters.', 'plathix'),
					FolderName::ERROR_TOO_LONG_CHARS => __('Folder name is too long.', 'plathix'),
					FolderName::ERROR_TOO_LONG_BYTES => __('Folder name is too long in bytes.', 'plathix'),
					default => null,
				};
				if ( null !== $message ) {
					$errors[] = (new PresetError('preset_invalid_folder_name', $message, $line, 'structure'))->to_array();
				}
			}
			if ( $color !== 'default' && preg_match('/^#[0-9A-Fa-f]{6}$/', $color) !== 1 ) {
				$errors[] = (new PresetError('preset_invalid_color', __('Invalid color value.', 'plathix'), $line, 'structure'))->to_array();
			}

			// Глубина считается по отступу; корень — 0, поэтому 7 уровней это 0..6.
			// Дубликаты и потерянные родители невозможны по построению: иерархию
			// задаёт отступ, а не сквозной номер, который можно продублировать.
			if ( $depth > 6 ) {
				$errors[] = (new PresetError('preset_max_depth_exceeded', __('Preset exceeds maximum depth.', 'plathix'), $line, 'structure'))->to_array();
			}
		}

		return $errors;
	}
}
