<?php

declare(strict_types=1);

namespace Plathix\Modules\Dashboard;

use Plathix\PublicApi\SettingsApi;
use Plathix\Modules\Dashboard\Stats\FolderStatsService;
use Plathix\Modules\Dashboard\Stats\MediaStatsService;
use Plathix\Modules\Dashboard\Stats\StorageStatsService;
use Plathix\Modules\Dashboard\Stats\UserFavoritesService;
use Plathix\PublicApi\ImportExportApi;
use Plathix\PublicApi\PresetsApi;
use Plathix\PublicApi\SvgApi;

class HomeDashboardData
{
	private readonly MediaStatsService $media;
	private readonly StorageStatsService $storage;
	private readonly UserFavoritesService $favorites;
	private readonly FolderStatsService $folders;

	public function __construct(
		?MediaStatsService $media = null,
		?StorageStatsService $storage = null,
		?UserFavoritesService $favorites = null,
		?FolderStatsService $folders = null
	) {
		$this->media     = $media ?? new MediaStatsService();
		$this->storage   = $storage ?? new StorageStatsService();
		$this->favorites = $favorites ?? new UserFavoritesService();
		$this->folders   = $folders ?? new FolderStatsService();
	}

	/**
	 * @return array<string, mixed>
	 */
	public function collect(): array {

		$post_types    = [ 'attachment' ];
		$enabled_count = count( $post_types );

		$folder_stats            = $this->folders->collect( $post_types );
		$total_folders           = $folder_stats['total_folders'];
		$total_files             = $folder_stats['total_files'];
		$distribution            = $folder_stats['distribution'];
		$orphaned_files          = $folder_stats['orphaned_files'];
		$uncategorized_folder_id = $folder_stats['uncategorized_folder_id'];
		$depth_stats             = [ 'maxDepth' => $folder_stats['maxDepth'] ];

		$health_issues    = $this->collectHealthIssues();
		$onboarding_cards = $this->filterDismissedOnboardingCards( $this->collectOnboardingCards( $post_types ) );
		$show_onboarding  = count( $onboarding_cards ) > 0;

		$migration_plugin = $this->detectMigrationPlugin();
		$applied_preset   = $this->findAppliedPreset();

		$mimeStats       = $this->media->mimeStats();
		$uploadActivity  = $this->media->uploadActivity();
		$favorites_stats  = $this->favorites->stats();
		$diskUsage       = $this->storage->diskUsage();

		return compact(
			'post_types',
			'enabled_count',
			'total_folders',
			'total_files',
			'orphaned_files',
			'uncategorized_folder_id',
			'distribution',
			'health_issues',
			'onboarding_cards',
			'show_onboarding',
			'migration_plugin',
			'applied_preset',
			'mimeStats',
			'uploadActivity',
			'favorites_stats',
			'depth_stats',
			'diskUsage'
		);
	}

	/**
	 * @return array<int, string>
	 */

	private function collectHealthIssues(): array {
		$issues = [];

		if ( ! version_compare( PHP_VERSION, '8.1', '>=' ) ) {
			/* translators: %s: current PHP version. */
			$issues[] = sprintf( __( 'PHP %s is below the minimum required 8.1.', 'plathix' ), PHP_VERSION );
		}

		return array_merge( $issues, ( new \Plathix\Infrastructure\Health\HealthCheckRegistry() )->issues() );
	}

	/**
	 * @param  string[] $post_types
	 * @return array<int, array{title:string, desc:string, url:string, id:string}>
	 */
	private function collectOnboardingCards(array $post_types): array {
		$cards = [];

		if ( ! (bool) get_option( 'plathix_infinite_scroll', false ) ) {
			$cards[] = [
				'title' => __( 'Enable infinite scroll', 'plathix' ),
				'desc'  => __( 'Replace default pagination with seamless infinite loading in the Media Library grid.', 'plathix' ),
				'url'   => ( new SettingsApi() )->pageUrl( 'general' ),
			];
		}

		if ( ( new SvgApi() )->isPolicySanitize() ) {
			$cards[] = [
				'title' => __( 'Review SVG policy', 'plathix' ),
				'desc'  => __( 'SVG uploads are enabled. Review the allowed roles and safe mode setting.', 'plathix' ),
				'url'   => ( new SettingsApi() )->pageUrl( 'svg' ),
			];
		}

		/**
		 * @param array<int, array{title:string, desc:string, url:string}> $cards
		 * @param string[] $post_types
		 */

		/** @var array<int, array{title:string, desc:string, url:string}> $cards */
		$cards = (array) apply_filters( 'plathix/dashboard/onboarding_cards', $cards, $post_types );

		return array_map(
			static fn (array $card): array => $card + [ 'id' => sanitize_key( (string) $card['title'] ) ],
			$cards
		);
	}

	/**
	 * @param array<int, array{title:string, desc:string, url:string, id:string}> $cards
	 * @return array<int, array{title:string, desc:string, url:string, id:string}>
	 */

	private function filterDismissedOnboardingCards(array $cards): array {
		$dismissed = get_user_meta( get_current_user_id(), HomeDashboardPage::blogScopedMetaKey( HomeDashboardPage::DISMISS_META_KEY ), true );

		if ( ! is_array( $dismissed ) ) {
			return $dismissed ? [] : $cards;
		}

		return array_values( array_filter( $cards, static fn (array $card): bool => ! in_array( $card['id'], $dismissed, true ) ) );
	}

	/** @return array{key:string, label:string}|null */
	private function detectMigrationPlugin(): ?array {
		$labels = [
			'filebird'      => 'FileBird',
			'wpmediafolder' => 'WP Media Folder',
			'realmedialib'  => 'Real Media Library',
			'happyfiles'    => 'HappyFiles',
			'wickedfolders' => 'Wicked Folders',
		];

		$import_api = new ImportExportApi();
		$available  = $import_api->availableImports();

		$imported   = $import_api->importedSources();

		$dismissed = get_user_meta( get_current_user_id(), HomeDashboardPage::blogScopedMetaKey( HomeDashboardPage::MIGRATION_DISMISS_META_KEY ), true );
		$dismissed = is_array( $dismissed ) ? $dismissed : [];

		foreach ( $labels as $key => $label ) {
			if ( ! empty( $available[ $key ] ) && ! in_array( $key, $dismissed, true ) && empty( $imported[ $key ] ) ) {
				return [ 'key' => $key, 'label' => $label ];
			}
		}

		return null;
	}

	/** @return array{title:string, folder_count:int, applied_at:string}|null */
	private function findAppliedPreset(): ?array {
		return ( new PresetsApi() )->lastApplied();
	}
}
