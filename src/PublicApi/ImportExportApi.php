<?php

declare(strict_types=1);

namespace Plathix\PublicApi;

use Plathix\Core\ImportJobDTO;
use Plathix\Modules\Import\ImportManager;
use Plathix\Modules\Import\StructureExporter;
use Plathix\Modules\Import\StructureImporter;
use Plathix\Infrastructure\Cache;
use Plathix\Infrastructure\JobDispatcher;
use Plathix\Infrastructure\RateLimiter;

final class ImportExportApi
{
	/** @var \Closure(): array<string, bool> */
	private \Closure $imports_loader;
	/** @var \Closure(): array<string, bool> */
	private \Closure $imported_loader;
	/** @var \Closure(string, string): (ImportJobDTO|\WP_Error) */
	private \Closure $import_starter;
	/** @var \Closure(): array<string, mixed> */
	private \Closure $export_builder;
	/** @var \Closure(string[]|null): array<string, mixed> */
	private \Closure $export_builder_filtered;
	/** @var \Closure(array<string, mixed>, string[]|null): array{imported:int, taxonomies:int, errors:int} */
	private \Closure $structure_importer;

	public function __construct(
		?callable $imports_loader = null,
		?callable $import_starter = null,
		?callable $export_builder = null,
		?callable $export_builder_filtered = null,
		?callable $structure_importer = null,
		?callable $imported_loader = null
	) {
		$this->imports_loader = \Closure::fromCallable($imports_loader ?? [$this, 'defaultImportsLoader']);
		$this->import_starter = \Closure::fromCallable($import_starter ?? [$this, 'defaultImportStarter']);
		$this->export_builder = \Closure::fromCallable($export_builder ?? [$this, 'defaultExportBuilder']);
		$this->export_builder_filtered = \Closure::fromCallable($export_builder_filtered ?? [$this, 'defaultExportBuilderFiltered']);
		$this->structure_importer = \Closure::fromCallable($structure_importer ?? [$this, 'defaultStructureImporter']);
		$this->imported_loader = \Closure::fromCallable($imported_loader ?? [$this, 'defaultImportedLoader']);
	}

	/**
	 * @return array<string, bool>
	 */
	public function availableImports(): array
	{
		return ($this->imports_loader)();
	}

	/**
	 * Per-adapter факт "перенос из этого источника уже выполнен" (проекция
	 * {@see ImportManager::imported()}, [internal]).
	 *
	 * @return array<string, bool>
	 */
	public function importedSources(): array
	{
		return ($this->imported_loader)();
	}

	public function startImport(string $adapter, string $postType = 'attachment'): ImportJobDTO|\WP_Error
	{
		return ($this->import_starter)($adapter, $postType);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function exportStructure(): array
	{
		return ($this->export_builder)();
	}

	/**
	 * Экспорт структуры с опциональным taxonomy-фильтром.
	 *
	 * Стабильная граница для потребителей вне ядра (CLI): отдаёт готовый payload,
	 * не раскрывая `StructureExporter`. `null` = все таксономии.
	 *
	 * @api
	 * @param string[]|null $taxonomies
	 * @return array<string, mixed>
	 */
	public function exportStructureFiltered(?array $taxonomies = null): array
	{
		return ($this->export_builder_filtered)($taxonomies);
	}

	/**
	 * Импорт структуры из готового payload (JSON).
	 *
	 * Стабильная граница для потребителей вне ядра (CLI): принимает payload и
	 * опциональный список таксономий, отдаёт нормализованную сводку, не раскрывая
	 * `StructureImporter`.
	 *
	 * @api
	 * @param  array<string, mixed> $payload
	 * @param  string[]|null        $taxonomies
	 * @return array{imported:int, taxonomies:int, errors:int}
	 */
	public function importStructure(array $payload, ?array $taxonomies = null): array
	{
		return ($this->structure_importer)($payload, $taxonomies);
	}

	/**
	 * @return array<string, bool>
	 */
	private function defaultImportsLoader(): array
	{
		return (new ImportManager())->available();
	}

	/**
	 * @return array<string, bool>
	 */
	private function defaultImportedLoader(): array
	{
		return (new ImportManager())->imported();
	}

	private function defaultImportStarter(string $adapter, string $postType): ImportJobDTO|\WP_Error
	{
		$adapter = sanitize_key($adapter);
		$postType = sanitize_key($postType === '' ? 'attachment' : $postType);
		$available = $this->defaultImportsLoader();

		if ( $adapter === '' || ! array_key_exists($adapter, $available) || ! $available[$adapter] ) {
			return new \WP_Error('invalid_import_adapter', __('Import adapter is not available.', 'plathix'));
		}

		// [internal] skeptic follow-up: explicit pre-check дублирует gate внутри
		// JobDispatcher::dispatch() (defense-in-depth), но даёт точный код ошибки — без
		// него блокировка здесь была бы неотличима от generic import_dispatch_failed,
		// в отличие от ImportAjaxHandler, который это уже делает.
		$dispatchReason = (new RateLimiter(Cache::make()))->can_dispatch_heavy_job(
			JobDispatcher::JOB_IMPORT,
			[ 'adapter' => $adapter, 'post_type' => $postType ],
			get_current_user_id()
		);

		if ( $dispatchReason === 'per_user' ) {
			return new \WP_Error('import_job_already_queued', __('Import job already queued for this user.', 'plathix'));
		}

		if ( $dispatchReason === 'server_cap' ) {
			return new \WP_Error('import_queue_busy', __('Import queue is busy.', 'plathix'));
		}

		// [internal]: payload+dispatch сборка унифицирована в ImportManager::start_import()
		// — этот транспорт теперь тонкий адаптер, не независимая копия. Контракт
		// array|WP_Error этого метода не меняется — status='dispatch_failed' мапится
		// в тот же WP_Error('import_dispatch_failed', ...), что и раньше при $jobId<=0.
		$result = (new ImportManager())->start_import($adapter, $postType, get_current_user_id());

		if ( ! $result->isQueued() ) {
			return new \WP_Error('import_dispatch_failed', __('Unable to queue import job.', 'plathix'));
		}

		// BOUNDDTO-002 ([internal]): наружу уходит тот же ImportJobDTO, что собрал
		// ImportManager — потребитель читает свойство, и опечатка в имени ловится
		// анализом. Второй DTO под этот транспорт не заводится: единственный живой
		// потребитель (PRO ImportCommand::start()) читает только jobId, а поля
		// 'queued'/'status' => 'pending' прежнего массива не читал никто, кроме теста
		// этого же метода — проверено Serena find_referencing_symbols и rg по PRO.
		return $result;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function defaultExportBuilder(): array
	{
		return (new StructureExporter())->export_payload();
	}

	/**
	 * @param  string[]|null $taxonomies
	 * @return array<string, mixed>
	 */
	private function defaultExportBuilderFiltered(?array $taxonomies): array
	{
		return (new StructureExporter())->export_payload($taxonomies);
	}

	/**
	 * @param  array<string, mixed> $payload
	 * @param  string[]|null        $taxonomies
	 * @return array{imported:int, taxonomies:int, errors:int}
	 */
	private function defaultStructureImporter(array $payload, ?array $taxonomies): array
	{
		$stats = (new StructureImporter())->import($payload, $taxonomies);

		return [
			'imported'   => (int) ( $stats['imported'] ?? 0 ),
			'taxonomies' => count( $payload['taxonomies'] ?? [] ),
			'errors'     => (int) ( $stats['errors'] ?? 0 ),
		];
	}
}
