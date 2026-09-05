<?php

declare(strict_types=1);

namespace Plathix\Infrastructure\Health;

use Plathix\Infrastructure\CronStatusResolver;
use Plathix\Infrastructure\JobDispatcher;
use Plathix\Infrastructure\TempDirectory;

/**
 * Единый источник правды по health-статусу Plathix ([internal]).
 *
 * До этого класса Dashboard (`HomeDashboardData::collect_health_issues()`) и System Info
 * (`SystemInfoProvider::health_check_rows()`/`plathix_info()`) считали health-статус двумя
 * независимыми узкими проверками — бейдж «Всё в порядке» на Главной мог оставаться зелёным,
 * даже когда System Info уже показывал проблему. Оба модуля теперь читают из этого реестра;
 * ни один не зависит от другого (project_module_autonomy_invariant).
 *
 * `severity: 'ignored'` — не ошибка и не должен считаться issue (например SVG-санитайзер,
 * когда SVG-политика не sanitize — отсутствие санитайзера тогда не имеет значения).
 */
final class HealthCheckRegistry
{
	/**
	 * @return array<int, array{key:string, label:string, ok:bool, severity:'error'|'ignored', value:string}>
	 */
	public function checks(): array {
		return [
			$this->check_cron(),
			$this->check_temp_dir_writable(),
			$this->check_temp_dir_size(),
			$this->check_stuck_jobs(),
			$this->check_boot_integrity(),
			$this->check_svg_sanitizer(),
			$this->check_presets_dir_guard(),
		];
	}

	/**
	 * Строка SVG-санитайзера отдельно от checks() — SystemInfoProvider::plathix_info() нужен
	 * тот же health-факт, чтобы построить своё расширенное отображение (нейтраль-суффикс при
	 * выключенном SVG), не пересчитывая его самостоятельно.
	 *
	 * @return array{key:string, label:string, ok:bool, severity:'error'|'ignored', value:string}
	 */
	public function svg_sanitizer(): array {
		return $this->check_svg_sanitizer();
	}

	/**
	 * Labels проверок с severity 'error' и ok=false — тот же формат, что раньше отдавал
	 * `HomeDashboardData::collect_health_issues()` (список строк для бейджа).
	 *
	 * @return array<int, string>
	 */
	public function issues(): array {
		$labels = [];
		foreach ( $this->checks() as $check ) {
			if ( 'error' === $check['severity'] && ! $check['ok'] ) {
				$labels[] = $check['value'];
			}
		}
		return $labels;
	}

	/** @return array{key:string, label:string, ok:bool, severity:'error', value:string} */
	private function check_cron(): array {
		$status = ( new CronStatusResolver() )->resolve();

		if ( ! $status['disabled'] ) {
			return [
				'key'      => 'cron',
				'label'    => __( 'WP Cron / Action Scheduler', 'plathix' ),
				'ok'       => true,
				'severity' => 'error',
				'value'    => __( 'Enabled', 'plathix' ),
			];
		}

		if ( $status['idle'] ) {
			// [internal], M11: DISABLE_WP_CRON включён, за последний час не завершалось
			// задач, но и в очереди ничего не было — здоровый idle, не сигнал зависания.
			return [
				'key'      => 'cron',
				'label'    => __( 'WP Cron / Action Scheduler', 'plathix' ),
				'ok'       => true,
				'severity' => 'error',
				'value'    => __( 'DISABLE_WP_CRON, Action Scheduler idle (nothing scheduled)', 'plathix' ),
			];
		}

		if ( ! $status['stalled'] ) {
			return [
				'key'      => 'cron',
				'label'    => __( 'WP Cron / Action Scheduler', 'plathix' ),
				'ok'       => true,
				'severity' => 'error',
				'value'    => __( 'DISABLE_WP_CRON but Action Scheduler active', 'plathix' ),
			];
		}

		return [
			'key'      => 'cron',
			'label'    => __( 'WP Cron / Action Scheduler', 'plathix' ),
			'ok'       => false,
			'severity' => 'error',
			'value'    => __( 'WP-Cron disabled, Action Scheduler stalled — ZIP/import jobs may not run', 'plathix' ),
		];
	}

	/** @return array{key:string, label:string, ok:bool, severity:'error', value:string} */
	private function check_temp_dir_writable(): array {
		$temp_dir = ( new TempDirectory() )->path();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- read-only diagnostic probe of the plugin's own temp dir for the health registry; reports status only, writes nothing.
		$ok = '' !== $temp_dir && ( ! is_dir( $temp_dir ) || is_writable( $temp_dir ) );

		return [
			'key'      => 'temp_dir_writable',
			'label'    => __( 'Temp Dir Writable', 'plathix' ),
			'ok'       => $ok,
			'severity' => 'error',
			'value'    => $ok
				? __( 'Writable', 'plathix' )
				: __( 'Temp directory is not writable. ZIP downloads will fail.', 'plathix' ),
		];
	}

	/** @return array{key:string, label:string, ok:bool, severity:'error', value:string} */
	private function check_temp_dir_size(): array {
		$temp_dir = ( new TempDirectory() )->path();
		$max_size = (int) apply_filters( 'plathix/infrastructure/temp_dir_max_bytes', 2 * GB_IN_BYTES );

		$zips = is_dir( $temp_dir ) ? ( glob( rtrim( $temp_dir, '/\\' ) . DIRECTORY_SEPARATOR . '*.zip' ) ?: [] ) : [];
		$size = array_sum( array_map( 'filesize', $zips ) );
		$ok   = $size <= $max_size;

		return [
			'key'      => 'temp_dir_size',
			'label'    => __( 'Temp Dir Size', 'plathix' ),
			'ok'       => $ok,
			'severity' => 'error',
			'value'    => $ok
				? sprintf(
					/* translators: 1: current size, 2: limit. */
					__( '%1$s of %2$s limit', 'plathix' ),
					size_format( $size ),
					size_format( $max_size )
				)
				: sprintf(
					/* translators: 1: current size, 2: limit. */
					__( 'Temp directory exceeds size limit (%1$s of %2$s) — cleanup job may be stalled.', 'plathix' ),
					size_format( $size ),
					size_format( $max_size )
				),
		];
	}

	/**
	 * "Завис" — либо реальный overrun сверх сконфигурированного кэпа, либо самая старая
	 * running-задача старше этого порога. Достижение кэпа само по себе (at capacity) —
	 * штатное поведение, не сигнал ([internal], finding M12).
	 */
	private const STUCK_JOB_AGE_THRESHOLD = 10 * MINUTE_IN_SECONDS;

	/** @return array{key:string, label:string, ok:bool, severity:'error', value:string} */
	private function check_stuck_jobs(): array {
		if ( ! function_exists( 'as_get_scheduled_actions' ) || ! class_exists( '\\ActionScheduler_Store' ) ) {
			return [
				'key'      => 'stuck_jobs',
				'label'    => __( 'Stuck Running Jobs', 'plathix' ),
				'ok'       => true,
				'severity' => 'error',
				'value'    => __( 'Action Scheduler not available', 'plathix' ),
			];
		}

		// Единый источник кэпа с RateLimiter::can_dispatch_heavy_job() — тот же фильтр,
		// тот же дефолт-массив, иначе кэп рассинхронизируется между throttling и health.
		$caps       = (array) apply_filters( 'plathix/jobs/heavy_caps', [ JobDispatcher::JOB_IMPORT => 2 ] );
		$zip_cap    = (int) ( $caps[ JobDispatcher::JOB_ZIP_GENERATE ] ?? 5 );
		$import_cap = (int) ( $caps[ JobDispatcher::JOB_IMPORT ] ?? 3 );

		[ $zip_running, $zip_stuck ]       = $this->running_and_stuck( JobDispatcher::JOB_ZIP_GENERATE, $zip_cap );
		[ $import_running, $import_stuck ] = $this->running_and_stuck( JobDispatcher::JOB_IMPORT, $import_cap );
		$ok                                = ! $zip_stuck && ! $import_stuck;

		return [
			'key'      => 'stuck_jobs',
			'label'    => __( 'Stuck Running Jobs', 'plathix' ),
			'ok'       => $ok,
			'severity' => 'error',
			'value'    => $ok
				/* translators: 1: running ZIP jobs count, 2: running import jobs count. */
				? sprintf( __( 'ZIP: %1$d running, import: %2$d running', 'plathix' ), $zip_running, $import_running )
				/* translators: 1: running ZIP jobs count, 2: running import jobs count. */
				: sprintf( __( 'Possibly stuck — ZIP: %1$d, import: %2$d', 'plathix' ), $zip_running, $import_running ),
		];
	}

	/**
	 * `per_page` = cap+1, чтобы overrun сверх кэпа был физически видим в count() (не
	 * обрезан ровно по кэпу). Age проверяется ОДИН раз, по самой старой running-задаче
	 * (query_actions default order — 'date'/'ASC', первый id самый старый), не циклом по
	 * всем running — иначе cost растёт вместе с будущим ростом кэпа.
	 *
	 * @return array{0:int, 1:bool} [running count, is stuck]
	 */
	private function running_and_stuck(string $hook, int $cap): array {
		if ( ! function_exists( 'as_get_scheduled_actions' ) || ! class_exists( '\\ActionScheduler_Store' ) ) {
			return [ 0, false ];
		}

		$ids = as_get_scheduled_actions(
			[
				'hook'     => $hook,
				'status'   => \ActionScheduler_Store::STATUS_RUNNING,
				'per_page' => max( 1, $cap + 1 ),
			],
			'ids'
		);

		$running = count( $ids );
		$age     = null;

		if ( $running > 0 && class_exists( '\\ActionScheduler' ) ) {
			try {
				$action = \ActionScheduler::store()->fetch_action( (int) reset( $ids ) );
				if ( ! ( $action instanceof \ActionScheduler_NullAction ) ) {
					$date = $action->get_schedule()->get_date();
					if ( $date instanceof \DateTime ) {
						$age = time() - $date->getTimestamp();
					}
				}
			} catch ( \Throwable ) {
				// Возраст неизвестен — не считаем это сигналом зависания самим по себе;
				// overrun-условие в is_stuck() всё ещё может сработать независимо.
			}
		}

		return [ $running, self::is_stuck( $running, $cap, $age ) ];
	}

	/**
	 * Чистое решение "завис ли hook" — без I/O, тестируется unit-тестом напрямую без
	 * мокирования Action Scheduler. `$oldest_running_age_seconds` — null, если возраст
	 * неизвестен (Action Scheduler недоступен для конкретной задачи, fetch упал).
	 */
	private static function is_stuck(int $running, int $cap, ?int $oldest_running_age_seconds): bool {
		if ( $running > $cap ) {
			return true;
		}

		return null !== $oldest_running_age_seconds && $oldest_running_age_seconds > self::STUCK_JOB_AGE_THRESHOLD;
	}

	/**
	 * [internal] (Слой 3): наблюдаемость lazy self-heal.
	 *
	 * @return array{key:string, label:string, ok:bool, severity:'error', value:string}
	 */
	private function check_boot_integrity(): array {
		$recovered = 1 === (int) get_option( 'plathix_boot_recovered_lazily', 0 );

		return [
			'key'      => 'boot_integrity',
			'label'    => __( 'Boot Integrity', 'plathix' ),
			'ok'       => ! $recovered,
			'severity' => 'error',
			'value'    => $recovered
				? __( 'Init interrupted by another plugin — system terms recovered lazily. Check for a plugin fataling on the init hook.', 'plathix' )
				: __( 'Normal', 'plathix' ),
		];
	}

	/**
	 * SVG-санитайзер релевантен только когда Plathix реально принимает и чистит SVG
	 * (policy=sanitize); при block/ignore отсутствие санитайзера не имеет значения ([internal]).
	 *
	 * @return array{key:string, label:string, ok:bool, severity:'error'|'ignored', value:string}
	 */
	private function check_svg_sanitizer(): array {
		$svg_enabled = \Plathix\Modules\Svg\SvgSettings::current_policy() === \Plathix\Modules\Svg\SvgSettings::POLICY_SANITIZE;
		[ $ok, $value ] = $this->svg_sanitizer_status();

		return [
			'key'      => 'svg_sanitizer',
			'label'    => __( 'SVG Sanitizer', 'plathix' ),
			'ok'       => $ok,
			'severity' => $svg_enabled ? 'error' : 'ignored',
			'value'    => $value,
		];
	}

	/**
	 * Статус SVG-санитайзера: [ok, локализованное человекочитаемое значение]. `ok` вычисляется
	 * из реального факта установки/версии — не парсится обратно из локализованной строки value
	 * ([internal]: раньше сравнение шло по __('Outdated')-фрагменту, что ломалось на переводах,
	 * поскольку value уже локализовано, а сравниваемая строка — нет).
	 *
	 * @return array{0:bool, 1:string}
	 */
	private function svg_sanitizer_status(): array {
		if ( ! class_exists( \enshrined\svgSanitize\Sanitizer::class ) ) {
			return [ false, __( 'Missing or outdated', 'plathix' ) ];
		}

		if ( ! $this->isOwnSanitizerEngine( \enshrined\svgSanitize\Sanitizer::class ) ) {
			return [ false, __( 'Provided by another plugin — not verifiable', 'plathix' ) ];
		}

		return [ true, __( 'Available', 'plathix' ) ];
	}

	/**
	 * Проверяет, что загруженный класс движка — та копия, которую поставил сам Plathix.
	 *
	 * [internal]. Раньше версия читалась через `Composer\InstalledVersions`, и это врало
	 * структурно, а не изредка:
	 *
	 * 1. `InstalledVersions::getInstalled()` обходит `ClassLoader::getRegisteredLoaders()` в
	 *    порядке РЕГИСТРАЦИИ и возвращает первое совпадение, тогда как автолоад выигрывает по
	 *    prepend (последний зарегистрированный). Порядки инвертированы — метод спокойно
	 *    рапортовал нашу версию, пока в рантайме работал класс из чужого vendor;
	 * 2. `enshrined/svg-sanitize` несут и другие плагины/темы (живые примеры: FileBird Pro
	 *    0.15.4, тема Bricks 0.14.0), а темы грузятся позже всех плагинов — то есть чужая
	 *    копия перехватывает глобальное имя штатно, а не в экзотике;
	 * 3. после изоляции (php-scoper на стадии сборки) `InstalledVersions` в поставку не едет
	 *    вовсе: ссылка на него префиксуется, а сам класс остаётся в dev-vendor — ветка молча
	 *    не выполнялась бы и статус всегда был бы зелёным.
	 *
	 * Поэтому проверяется не реестр, а происхождение фактически загруженного класса: файл
	 * обязан лежать внутри каталога плагина. Сравнение по `realpath()` — каталог плагина
	 * часто симлинк (типовой dev-случай `wp-content/plugins/plathix` → рабочее дерево), и
	 * сравнение сырых путей давало бы ложное «чужая копия» у самих разработчиков.
	 *
	 * Версия здесь намеренно не показывается: в собранном артефакте её неоткуда взять без
	 * `InstalledVersions`, а показывать «Available» честнее, чем печатать число, за которое
	 * нельзя поручиться. Требование к минимальной версии обеспечивается `composer.lock`.
	 *
	 * @param class-string $engineClass FQCN движка (в поставке — префиксованный php-scoper).
	 */
	private function isOwnSanitizerEngine(string $engineClass): bool {
		try {
			$file = ( new \ReflectionClass( $engineClass ) )->getFileName();
		} catch ( \ReflectionException $e ) {
			return false;
		}

		if ( ! is_string( $file ) || '' === $file ) {
			return false;
		}

		$engineReal = realpath( $file );
		$pluginReal = defined( 'PLATHIX_PATH' ) ? realpath( PLATHIX_PATH ) : false;

		if ( false === $engineReal || false === $pluginReal ) {
			return false;
		}

		return str_starts_with( $engineReal, rtrim( $pluginReal, '/\\' ) . DIRECTORY_SEPARATOR );
	}

	/**
	 * [internal]: `Activator::ensure_presets_dir()` кладёт directory-guard на
	 * `activate()`; апгрейд существующей установки без реактивации не создаёт его
	 * немедленно — recovery самовосстанавливается лениво при первом
	 * `PresetUploadPipeline::store_preview()`. До этого момента у админа не было
	 * способа в UI подтвердить состояние guard. Путь строится тем же способом, что
	 * `Activator::ensure_guarded_dir()` — не через отдельный resolver-класс.
	 *
	 * @return array{key:string, label:string, ok:bool, severity:'error', value:string}
	 */
	private function check_presets_dir_guard(): array {
		$upload = wp_upload_dir();
		$dir    = ! empty( $upload['basedir'] ) ? trailingslashit( $upload['basedir'] ) . 'plathix/presets' : '';

		if ( '' === $dir || ! is_dir( $dir ) ) {
			return [
				'key'      => 'presets_dir_guard',
				'label'    => __( 'Presets Directory Guard', 'plathix' ),
				'ok'       => true,
				'severity' => 'error',
				'value'    => __( 'Directory not created yet — guard will be added automatically on first preset upload.', 'plathix' ),
			];
		}

		$missing = [];
		if ( ! file_exists( trailingslashit( $dir ) . 'index.php' ) ) {
			$missing[] = 'index.php';
		}
		if ( ! file_exists( trailingslashit( $dir ) . '.htaccess' ) ) {
			$missing[] = '.htaccess';
		}

		return [
			'key'      => 'presets_dir_guard',
			'label'    => __( 'Presets Directory Guard', 'plathix' ),
			'ok'       => [] === $missing,
			'severity' => 'error',
			'value'    => [] === $missing
				? __( 'Protected', 'plathix' )
				: sprintf(
					/* translators: %s: comma-separated list of missing guard files, e.g. "index.php, .htaccess". */
					__( 'Guard incomplete — missing: %s', 'plathix' ),
					implode( ', ', $missing )
				),
		];
	}
}
