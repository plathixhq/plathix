<?php

declare(strict_types=1);

namespace Plathix\PublicApi;

use Plathix\Modules\Preset\BuiltInPresetDiscovery;
use Plathix\Modules\Preset\PresetApplyPipeline;
use Plathix\Modules\Preset\PresetExportDefaults;
use Plathix\Modules\Preset\PresetExportPipeline;
use Plathix\Modules\Preset\PresetRepository;

/**
 * Стабильная граница Free для операций с пресетами, нужных потребителям вне модуля Preset
 * (в частности — PRO-модулю Onboarding, который держит визард в отдельном плагине и не
 * должен `use`-ить потроха feature-модуля Preset напрямую — ось B1 module-standard).
 *
 * Инкапсулирует ДВЕ операции first-run визарда:
 *  - {@see builtinPresets()} — список встроенных пресетов для UI (discover + list + filter + map);
 *  - {@see apply()} — применение пресета по id с нормализованным результатом для UI.
 *
 * ВЕСЬ прямой `use Plathix\Modules\Preset\...` (Discovery/Repository/Pipeline) собран здесь — это
 * единственный Free-файл, где он платформенно-легален (Preset — Free, PublicApi — Free).
 * Форма apply() 1:1 проецирует {@see PresetApplyPipeline::run()} и не расширяется превентивно.
 *
 * Конструктор принимает `?callable`-дефолты (эталон {@see AssignmentsApi}) — для тестируемости
 * без global-стабов: тест подставляет spy-замыкания, прод использует default-методы с `new`.
 */
final class PresetsApi
{
	/** @var \Closure(): array<int, array{id:int, title:string, description:string, folder_count:int, category:string}> */
	private \Closure $builtin_lister;
	/** @var \Closure(int): array{success:bool, created:int, errors:int, scope:string, error?:array{code:string, message:string, line:mixed, section:mixed, fatal:bool}} */
	private \Closure $applier;
	/** @var \Closure(int): string */
	private \Closure $title_reader;
	/** @var \Closure(): ?array{title:string, folder_count:int, applied_at:string} */
	private \Closure $last_applied_reader;
	/** @var \Closure(): array{success:bool, zip_path?:string, temp_dir?:string, slug?:string, error?:array{message:string}} */
	private \Closure $site_exporter;
	/** @var \Closure(): array<int, array{id:int, title:string, description:string, folder_count:int}> */
	private \Closure $valid_presets_lister;

	public function __construct(
		?callable $builtin_lister = null,
		?callable $applier = null,
		?callable $title_reader = null,
		?callable $last_applied_reader = null,
		?callable $site_exporter = null,
		?callable $valid_presets_lister = null
	) {
		$this->builtin_lister = \Closure::fromCallable($builtin_lister ?? [ $this, 'defaultBuiltinLister' ]);
		$this->applier        = \Closure::fromCallable($applier ?? [ $this, 'defaultApplier' ]);
		$this->title_reader   = \Closure::fromCallable($title_reader ?? [ $this, 'defaultTitleReader' ]);
		$this->last_applied_reader = \Closure::fromCallable($last_applied_reader ?? [ $this, 'defaultLastAppliedReader' ]);
		$this->site_exporter  = \Closure::fromCallable($site_exporter ?? [ $this, 'defaultSiteExporter' ]);
		$this->valid_presets_lister = \Closure::fromCallable($valid_presets_lister ?? [ $this, 'defaultValidPresetsLister' ]);
	}

	/**
	 * Встроенные пресеты для UI визарда.
	 *
	 * @return array<int, array{id:int, title:string, description:string, folder_count:int, category:string}>
	 */
	public function builtinPresets(): array
	{
		return ( $this->builtin_lister )();
	}

	/**
	 * Применяет пресет по id. Форма результата проецирует PresetApplyPipeline::run():
	 * при отсутствии пресета pipeline сам возвращает `error.code = 'preset_not_found'`
	 * (404-семантику выставляет ПОТРЕБИТЕЛЬ по code, транспорт здесь не решается). `title`
	 * в результат НЕ входит — потребитель берёт его своим find() (форма run() title не несёт).
	 *
	 * @return array{success:bool, created:int, errors:int, scope:string, error?:array{code:string, message:string, line:mixed, section:mixed, fatal:bool}}
	 */
	public function apply(int $presetId): array
	{
		return ( $this->applier )( $presetId );
	}

	/**
	 * Заголовок пресета по id (для success-notice потребителя). `''` если пресета нет.
	 * Узкий read-канал, чтобы потребитель (в т.ч. PRO-визард) не держал прямой `use` на
	 * PresetRepository ради одного title — форма apply() title сознательно не несёт.
	 */
	public function presetTitle(int $presetId): string
	{
		return ( $this->title_reader )( $presetId );
	}

	/**
	 * Последний применённый пресет (для карточки дашборда). `null`, если ни один
	 * пресет ещё не применялся. Проекция {@see PresetRepository::find_last_applied()}
	 * в стабильную форму (потребитель ранее нормализовал raw-строку сам).
	 *
	 * @return array{title:string, folder_count:int, applied_at:string}|null
	 */
	public function lastApplied(): ?array
	{
		return ( $this->last_applied_reader )();
	}

	/**
	 * Экспортирует текущий сайт (структуру папок + метаданные) как zip-архив пресета,
	 * готовый к скачиванию. Проекция {@see PresetExportDefaults}/{@see PresetExportPipeline}
	 * (потребитель ранее вызывал их напрямую).
	 *
	 * @return array{success:bool, zip_path?:string, temp_dir?:string, slug?:string, error?:array{message:string}}
	 */
	public function exportCurrentSiteAsPreset(): array
	{
		return ( $this->site_exporter )();
	}

	/**
	 * Все валидные пресеты (не только builtin — в отличие от {@see builtinPresets()}),
	 * для UI first-run визарда (карточки выбора). Проекция {@see PresetRepository::list()}
	 * с фильтром `validation_status=valid`, без сужения по `source_type`.
	 *
	 * @return array<int, array{id:int, title:string, description:string, folder_count:int}>
	 */
	public function validPresets(): array
	{
		return ( $this->valid_presets_lister )();
	}

	// -------------------------------------------------------------------------

	/**
	 * @return array<int, array{id:int, title:string, description:string, folder_count:int, category:string}>
	 */
	private function defaultBuiltinLister(): array
	{
		( new BuiltInPresetDiscovery() )->discover();

		$presets = ( new PresetRepository() )->list( [ 'validation_status' => 'valid' ] );

		$result = [];
		foreach ( $presets as $p ) {
			// 'builtin' — инлайн PresetSourceType::BUILTIN (константа остаётся во Free,
			// в PublicApi её не тянем ради одной строки сравнения).
			if ( (string) ( $p['source_type'] ?? '' ) !== 'builtin' ) {
				continue;
			}
			$result[] = [
				'id'           => (int) $p['id'],
				'title'        => (string) ( $p['title'] ?? '' ),
				'description'  => (string) ( $p['description'] ?? '' ),
				'folder_count' => (int) ( $p['folder_count'] ?? 0 ),
				'category'     => (string) ( $p['category'] ?? '' ),
			];
		}

		return $result;
	}

	/**
	 * Проецирует результат PresetApplyPipeline::run() в стабильную форму контракта. Нормализация
	 * явная (а не сквозной проброс `array`), чтобы форма была доказуема статически: run() при
	 * отсутствии пресета сам возвращает error.code='preset_not_found' — пробрасываем error как есть.
	 *
	 * @return array{success:bool, created:int, errors:int, scope:string, error?:array{code:string, message:string, line:mixed, section:mixed, fatal:bool}}
	 */
	private function defaultApplier(int $presetId): array
	{
		$raw = ( new PresetApplyPipeline() )->run( $presetId );

		$result = [
			'success' => (bool) ( $raw['success'] ?? false ),
			'created' => (int) ( $raw['created'] ?? 0 ),
			'errors'  => (int) ( $raw['errors'] ?? 0 ),
			'scope'   => (string) ( $raw['scope'] ?? 'media' ),
		];

		if ( isset( $raw['error'] ) && is_array( $raw['error'] ) ) {
			$err              = $raw['error'];
			$result['error'] = [
				'code'    => (string) ( $err['code'] ?? '' ),
				'message' => (string) ( $err['message'] ?? '' ),
				'line'    => $err['line'] ?? null,
				'section' => $err['section'] ?? null,
				'fatal'   => (bool) ( $err['fatal'] ?? false ),
			];
		}

		return $result;
	}

	private function defaultTitleReader(int $presetId): string
	{
		$preset = ( new PresetRepository() )->find( $presetId );

		return null === $preset ? '' : (string) ( $preset['title'] ?? '' );
	}

	/**
	 * @return array{title:string, folder_count:int, applied_at:string}|null
	 */
	private function defaultLastAppliedReader(): ?array
	{
		$applied = ( new PresetRepository() )->find_last_applied();

		if ( $applied === null ) {
			return null;
		}

		return [
			'title'        => (string) ( $applied['title'] ?? '' ),
			'folder_count' => (int) ( $applied['folder_count'] ?? 0 ),
			'applied_at'   => (string) ( $applied['last_applied_at'] ?? '' ),
		];
	}

	/**
	 * @return array{success:bool, zip_path?:string, temp_dir?:string, slug?:string, error?:array{message:string}}
	 */
	private function defaultSiteExporter(): array
	{
		$metadata = PresetExportDefaults::metadata();
		$preview  = PresetExportDefaults::preview_file();

		if ( $preview === null ) {
			return [
				'success' => false,
				'error'   => [ 'message' => __( 'Could not resolve a preview image for the preset. Please add a site logo or icon first.', 'plathix' ) ],
			];
		}

		$result = ( new PresetExportPipeline() )->run( $metadata, $preview );

		if ( ! $result['success'] ) {
			$msg = (string) ( $result['error']['message'] ?? __( 'Export failed.', 'plathix' ) );
			return [ 'success' => false, 'error' => [ 'message' => $msg ] ];
		}

		return [
			'success'  => true,
			'zip_path' => (string) ( $result['zip_path'] ?? '' ),
			'temp_dir' => (string) ( $result['temp_dir'] ?? '' ),
			'slug'     => sanitize_key( (string) ( $metadata['slug'] ?? 'plathix-preset' ) ),
		];
	}

	/**
	 * @return array<int, array{id:int, title:string, description:string, folder_count:int}>
	 */
	private function defaultValidPresetsLister(): array
	{
		$presets = ( new PresetRepository() )->list( [ 'validation_status' => 'valid' ] );

		$result = [];
		foreach ( $presets as $p ) {
			$result[] = [
				'id'           => (int) $p['id'],
				'title'        => (string) ( $p['title'] ?? '' ),
				'description'  => (string) ( $p['description'] ?? '' ),
				'folder_count' => (int) ( $p['folder_count'] ?? 0 ),
			];
		}

		return $result;
	}
}
