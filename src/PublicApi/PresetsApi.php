<?php

declare(strict_types=1);

namespace Plathix\PublicApi;

use Plathix\Modules\Preset\BuiltInPresetDiscovery;
use Plathix\Modules\Preset\PresetApplyPipeline;
use Plathix\Modules\Preset\PresetExportDefaults;
use Plathix\Modules\Preset\PresetExportPipeline;
use Plathix\Modules\Preset\PresetRepository;

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
	 * @return array<int, array{id:int, title:string, description:string, folder_count:int, category:string}>
	 */

	public function builtinPresets(): array
	{
		return ( $this->builtin_lister )();
	}

	/**
	 * @return array{success:bool, created:int, errors:int, scope:string, error?:array{code:string, message:string, line:mixed, section:mixed, fatal:bool}}
	 */

	public function apply(int $presetId): array
	{
		return ( $this->applier )( $presetId );
	}

	public function presetTitle(int $presetId): string
	{
		return ( $this->title_reader )( $presetId );
	}

	/**
	 * @return array{title:string, folder_count:int, applied_at:string}|null
	 */

	public function lastApplied(): ?array
	{
		return ( $this->last_applied_reader )();
	}

	/**
	 * @return array{success:bool, zip_path?:string, temp_dir?:string, slug?:string, error?:array{message:string}}
	 */

	public function exportCurrentSiteAsPreset(): array
	{
		return ( $this->site_exporter )();
	}

	/**
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
		$applied = ( new PresetRepository() )->findLastApplied();

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
		$preview  = PresetExportDefaults::previewFile();

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
