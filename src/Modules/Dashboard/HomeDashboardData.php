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

/**
 * Собирает все данные для HomeDashboardPage.
 *
 * Оркеструет узкие сервисы-подсистемы (по источнику данных) и доменные коллекторы
 * дашборда. Сервисы инжектируются (P4); default-инстансы — чтобы `new HomeDashboardData()`
 * продолжал работать без явной проводки.
 */
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
		// CTAN-201: Free attachment-native — дашборд считает медиатеку безусловно; CPT-цифры
		// добавляет PRO своим виджетом через слот plathix/dashboard/widgets.
		$post_types    = [ 'attachment' ];
		$enabled_count = count( $post_types );

		// One-pass по таксономиям: счётчики папок/файлов, distribution, глубина, orphaned, uncategorized.
		$folder_stats            = $this->folders->collect( $post_types );
		$total_folders           = $folder_stats['total_folders'];
		$total_files             = $folder_stats['total_files'];
		$distribution            = $folder_stats['distribution'];
		$orphaned_files          = $folder_stats['orphaned_files'];
		$uncategorized_folder_id = $folder_stats['uncategorized_folder_id'];
		$depth_stats             = [ 'max_depth' => $folder_stats['max_depth'] ];

		// [internal] ([internal]): журнал и виджет «недавняя активность» уехали в
		// PRO целиком. Free больше не собирает данные журнала и не кладёт recent_activity в
		// $data — виджет рендерит PRO самостоятельно (данные из AuditLog внутри PRO).
		$health_issues    = $this->collect_health_issues();
		$onboarding_cards = $this->filter_dismissed_onboarding_cards( $this->collect_onboarding_cards( $post_types ) );
		$show_onboarding  = count( $onboarding_cards ) > 0;

		$migration_plugin = $this->detect_migration_plugin();
		$applied_preset   = $this->find_applied_preset();
		// [internal] ([internal]): shortcode_stats уехал в PRO целиком вместе с
		// ShortcodesWidget — виджет теперь сам считает свою статистику
		// (PlathixPro\Modules\Gallery\ShortcodeUsageScanner::dashboard_stats()), не читает
		// $data из этой сборки.
		$mime_stats       = $this->media->mime_stats();
		$upload_activity  = $this->media->upload_activity();
		$favorites_stats  = $this->favorites->stats();
		$disk_usage       = $this->storage->disk_usage();

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
			'mime_stats',
			'upload_activity',
			'favorites_stats',
			'depth_stats',
			'disk_usage'
		);
	}

	/**
	 * [internal]: health-факты (cron, temp dir, stuck jobs, boot integrity, SVG sanitizer)
	 * читаются из HealthCheckRegistry — том же реестре, что питает System Info, чтобы бейдж
	 * на Главной не расходился с реальным состоянием. PHP-версия остаётся отдельной проверкой
	 * здесь: это факт про сам PHP-раннер дашборда, не входивший в прежний health_check_rows().
	 *
	 * @return array<int, string>
	 */
	private function collect_health_issues(): array {
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
	private function collect_onboarding_cards(array $post_types): array {
		$cards = [];

		// CTAN-201: карточка «Select content types» удалена — выбор типов не существует во Free
		// (Guideline 5); PRO добавляет свои карточки слотом plathix/dashboard/onboarding_cards.

		// [internal] ([internal]): карточка раньше вела на таб Access, унесённый в PRO
		// ([internal], [internal]) — ссылка стала мёртвой во Free. Перепрофилирована
		// на реально существующую Free-настройку (infinite scroll, таб general), не удалена и
		// не перенесена в PRO — слот остаётся простым UI-элементом с рабочим содержимым.
		if ( ! (bool) get_option( 'plathix_infinite_scroll', false ) ) {
			$cards[] = [
				'title' => __( 'Enable infinite scroll', 'plathix' ),
				'desc'  => __( 'Replace default pagination with seamless infinite loading in the Media Library grid.', 'plathix' ),
				'url'   => ( new SettingsApi() )->pageUrl( 'general' ),
			];
		}

		// [internal]: карточка «Enable audit log» уехала в PRO (журнал = PRO).
		// Free её не собирает. PRO добавляет свою карточку через фильтр ниже.

		// [internal]: карточка про роли/safe mode релевантна только когда svg реально принимается
		// и очищается Plathix — т.е. политика sanitize (при block/ignore роли/safe mode не работают).
		if ( ( new SvgApi() )->isPolicySanitize() ) {
			$cards[] = [
				'title' => __( 'Review SVG policy', 'plathix' ),
				'desc'  => __( 'SVG uploads are enabled. Review the allowed roles and safe mode setting.', 'plathix' ),
				'url'   => ( new SettingsApi() )->pageUrl( 'svg' ),
			];
		}

		/**
		 * Extension point: PRO-модули дописывают свои онбординг-карточки ([internal]).
		 * Free собирает базовые карточки (content types / permissions / SVG); PRO-модуль Audit
		 * возвращает через этот фильтр карточку «Enable audit log». Без PRO фильтр — no-op.
		 * Explicit override non-goal [internal]: сбор карточек намеренно
		 * расширен фильтром, т.к. audit-карточка физически уехала в PRO.
		 *
		 * @param array<int, array{title:string, desc:string, url:string}> $cards
		 * @param string[] $post_types
		 */
		/** @var array<int, array{title:string, desc:string, url:string}> $cards */
		$cards = (array) apply_filters( 'plathix/dashboard/onboarding_cards', $cards, $post_types );

		// [internal]: стабильный id на карточку вычисляется ЗДЕСЬ, после фильтра, единым
		// способом для Free- и PRO-карточек — PRO не обязан сам передавать id.
		return array_map(
			static fn (array $card): array => $card + [ 'id' => sanitize_key( (string) $card['title'] ) ],
			$cards
		);
	}

	/**
	 * [internal]: dismiss теперь per-card, не per-block. DISMISS_META_KEY хранит массив
	 * dismissed card id (форма как MIGRATION_DISMISS_META_KEY), без TTL ([internal] — dismiss
	 * постоянный, прежнее 7-дневное окно убрано без замены).
	 *
	 * Legacy fallback: старый формат meta был одиночным int timestamp (весь блок дизмиснут
	 * целиком). Если сохранённое значение — не массив, но truthy, весь текущий набор карточек
	 * остаётся скрытым (не хуже прежнего поведения — единственный факт "когда-то дизмиснуто"
	 * и раньше гасил всё, здесь просто гасится явно, а не пересчитывается из несуществующих
	 * per-card данных).
	 *
	 * @param array<int, array{title:string, desc:string, url:string, id:string}> $cards
	 * @return array<int, array{title:string, desc:string, url:string, id:string}>
	 */
	private function filter_dismissed_onboarding_cards(array $cards): array {
		$dismissed = get_user_meta( get_current_user_id(), HomeDashboardPage::blog_scoped_meta_key( HomeDashboardPage::DISMISS_META_KEY ), true );

		if ( ! is_array( $dismissed ) ) {
			return $dismissed ? [] : $cards;
		}

		return array_values( array_filter( $cards, static fn (array $card): bool => ! in_array( $card['id'], $dismissed, true ) ) );
	}

	/** @return array{key:string, label:string}|null */
	private function detect_migration_plugin(): ?array {
		$labels = [
			'filebird'      => 'FileBird',
			'wpmediafolder' => 'WP Media Folder',
			'realmedialib'  => 'Real Media Library',
			'happyfiles'    => 'HappyFiles',
			'wickedfolders' => 'Wicked Folders',
		];

		$import_api = new ImportExportApi();
		$available  = $import_api->availableImports();
		// [internal]: источник, из которого перенос уже выполнен, не
		// предлагается баннером повторно (per-site факт, отдельный от per-user dismissed ниже).
		$imported   = $import_api->importedSources();

		// Источники, которые текущий юзер уже скрыл ([internal], Вариант 2): пропускаем их, но
		// покажем баннер для НОВОГО обнаруженного источника, если он ещё не dismissed.
		$dismissed = get_user_meta( get_current_user_id(), HomeDashboardPage::blog_scoped_meta_key( HomeDashboardPage::MIGRATION_DISMISS_META_KEY ), true );
		$dismissed = is_array( $dismissed ) ? $dismissed : [];

		foreach ( $labels as $key => $label ) {
			if ( ! empty( $available[ $key ] ) && ! in_array( $key, $dismissed, true ) && empty( $imported[ $key ] ) ) {
				return [ 'key' => $key, 'label' => $label ];
			}
		}

		return null;
	}

	/** @return array{title:string, folder_count:int, applied_at:string}|null */
	private function find_applied_preset(): ?array {
		return ( new PresetsApi() )->lastApplied();
	}
}
