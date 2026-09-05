<?php

declare(strict_types=1);

namespace Plathix\Infrastructure\Jobs;

use Plathix\Infrastructure\JobDispatcher;
use Plathix\Infrastructure\Logger;
use Plathix\Infrastructure\TempDirectory;

/**
 * Handles the plathix_job_cleanup_temp Action Scheduler job.
 */
final class CleanupJobRunner
{
	/**
	 * Интервал, с которым Activator планирует `plathix_job_cleanup_temp`
	 * (`dispatch_recurring( JOB_CLEANUP_TEMP, JobDispatcher::JOB_CLEANUP_TEMP_INTERVAL )`,
	 * src/Activator.php). Единственный источник значения — `JobDispatcher` ([internal]).
	 *
	 * Нужен purge_stale_job_results(), чтобы порог жизни записи гарантированно захватывал
	 * хотя бы один прогон этой очистки после удаления файла.
	 */
	private const CLEANUP_INTERVAL = JobDispatcher::JOB_CLEANUP_TEMP_INTERVAL;

	public function run(callable $get_temp_dir): void {
		$temp_dir  = $get_temp_dir();
		$max_age   = (int) apply_filters( 'plathix/infrastructure/temp_file_max_age', HOUR_IN_SECONDS * 2 );
		$grace     = (int) apply_filters( 'plathix/infrastructure/temp_file_grace_period', 1800 );
		$threshold = time() - $max_age - $grace;

		$raw_dir  = rtrim( $temp_dir, '/\\' );
		$real_dir = realpath( $raw_dir );

		if ( false === $real_dir || is_link( $raw_dir ) ) {
			Logger::warning( 'cleanup_aborted_invalid_temp_dir', [ 'path' => $temp_dir ] );
			return;
		}

		// [internal]: AttachmentReplaceService::backup_collision_target() создаёt
		// `replace_collision_<uniqid>-<basename>` в этой же temp-директории — с расширением
		// ИСХОДНОГО файла (jpg/png/mp4/...), не .zip. replace() удаляет её сам при любом
		// управляемом исходе (success/WP_Error/пойманный Throwable через finally), но истинный
		// PHP fatal (max_execution_time) не выполняет finally — без этого второго допустимого
		// класса файлов такая копия остаётся orphaned навсегда, потому что $safe_path раньше
		// пропускал только .zip.
		//
		// [internal]: PRO ZipJobRunner::generate() создаёт `plathix-stage-<hex>/` — КАТАЛОГ со
		// staged-копиями медиа для сборки архива, удаляемый только в собственном finally
		// генератора; killed-процесс (OOM-killer, kill воркера, fatal вне try) не выполняет PHP
		// finally, и каталог остаётся навсегда. $safe_path теперь возвращает найденный путь
		// вместе с явным `is_dir`-флагом, потому что file-ориентированные примитивы ниже
		// (filesize/wp_delete_file) не применимы к каталогу без этого различения.
		$safe_path = static function (string $file) use ($real_dir): array|false {
			if ( is_link( $file ) ) {
				return false;
			}

			$real = realpath( $file );
			if ( false === $real ) {
				return false;
			}

			if ( ! str_starts_with( $real, $real_dir . DIRECTORY_SEPARATOR ) ) {
				return false;
			}

			$is_dir = is_dir( $real );
			if ( $is_dir ) {
				$is_managed_stage = str_starts_with( basename( $real ), 'plathix-stage-' );
				if ( ! $is_managed_stage ) {
					return false;
				}

				return [ 'path' => $real, 'is_dir' => true ];
			}

			$is_managed_zip      = 'zip' === pathinfo( $real, PATHINFO_EXTENSION );
			$is_collision_backup = str_starts_with( basename( $real ), 'replace_collision_' );
			if ( ! $is_managed_zip && ! $is_collision_backup ) {
				return false;
			}

			return [ 'path' => $real, 'is_dir' => false ];
		};

		$find_managed_files = static function () use ($real_dir): array {
			$zips    = glob( $real_dir . DIRECTORY_SEPARATOR . '*.zip' ) ?: [];
			$backups = glob( $real_dir . DIRECTORY_SEPARATOR . 'replace_collision_*' ) ?: [];
			$stages  = glob( $real_dir . DIRECTORY_SEPARATOR . 'plathix-stage-*', GLOB_ONLYDIR ) ?: [];
			return array_merge( $zips, $backups, $stages );
		};

		// stage-каталог никогда не отдаётся клиенту на скачивание (в отличие от .zip) — он
		// одноразовый (bin2hex(random_bytes(8))-суффикс на каждый прогон job'а) и никогда не
		// переиспользуется параллельно, поэтому flock-probe (нужен для .zip именно из-за риска
		// "файл ещё читается клиентом при скачивании") здесь неприменим и не нужен: age-check
		// (filemtime каталога) — единственная защита, которая имеет смысл для этого типа.
		$directory_size = static function (string $dir): int {
			$total = 0;
			foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) ) as $entry ) {
				if ( $entry->isFile() ) {
					$total += (int) $entry->getSize();
				}
			}
			return $total;
		};

		$max_dir_size = (int) apply_filters( 'plathix/infrastructure/temp_dir_max_bytes', 2 * GB_IN_BYTES );
		$all_managed  = $find_managed_files();
		$safe_entries = array_values( array_filter( array_map( $safe_path, $all_managed ) ) );
		$entry_size   = static fn (array $entry): int => $entry['is_dir'] ? $directory_size( $entry['path'] ) : (int) filesize( $entry['path'] );
		$dir_size     = array_sum( array_map( $entry_size, $safe_entries ) );

		if ( $dir_size > $max_dir_size ) {
			usort( $safe_entries, static fn(array $a, array $b): int => filemtime( $a['path'] ) <=> filemtime( $b['path'] ) );

			foreach ( $safe_entries as $entry ) {
				if ( $dir_size <= $max_dir_size ) {
					break;
				}

				if ( $entry['is_dir'] ) {
					$dir_size -= $entry_size( $entry );
					TempDirectory::remove_tree( $entry['path'] );
					continue;
				}

				$file = $entry['path'];
				$fh   = fopen( $file, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- cron job-runner over own temp dir; opened only to flock()-probe an in-use ZIP before eviction (disk-guard); WP_Filesystem exposes no advisory-lock API and its credentials-flow is unavailable in cron.
				if ( false === $fh ) {
					continue;
				}

				if ( ! flock( $fh, LOCK_EX | LOCK_NB ) ) {
					fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- releases the flock-probe handle opened above when the file is still locked by another worker.
					continue;
				}

				flock( $fh, LOCK_UN );
				fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- releases the flock-probe handle before wp_delete_file() evicts the temp ZIP.

				$dir_size -= (int) filesize( $file );
				wp_delete_file( $file );
			}
		}

		foreach ( $find_managed_files() as $file ) {
			$safe = $safe_path( $file );
			if ( false === $safe || (int) filemtime( $safe['path'] ) >= $threshold ) {
				continue;
			}

			if ( $safe['is_dir'] ) {
				TempDirectory::remove_tree( $safe['path'] );
				continue;
			}

			$fh = fopen( $safe['path'], 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- cron job-runner over own temp dir; opened only to flock()-probe a stale ZIP before TTL eviction; WP_Filesystem exposes no advisory-lock API and its credentials-flow is unavailable in cron.
			if ( false === $fh ) {
				continue;
			}

			if ( ! flock( $fh, LOCK_EX | LOCK_NB ) ) {
				fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- releases the flock-probe handle opened above when the stale ZIP is still locked by another worker.
				continue;
			}

			flock( $fh, LOCK_UN );
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- releases the flock-probe handle before wp_delete_file() evicts the stale temp ZIP.
			wp_delete_file( $safe['path'] );
		}

		$this->purge_stale_job_results();
	}

	/**
	 * [internal]: job_result-опции (`Keys::job_result()`) пишутся на каждый ZIP/Import job
	 * (`JobDispatcher::resolve_and_store_job_result()`, `ImportManager::handle_job_import()`),
	 * но раньше не имели TTL-очистки — накапливались в wp_options бессрочно (Deactivator
	 * чистит их только при деактивации плагина). Опции, записанные ДО этого пакета, не имеют
	 * `_created_at` — трактуются как заведомо просроченные и удаляются сразу (safe-default:
	 * TTL уже истёк бы к первому прогону cron после деплоя).
	 */
	private function purge_stale_job_results(): void {
		global $wpdb;

		$max_age   = (int) apply_filters( 'plathix/infrastructure/job_result_max_age', DAY_IN_SECONDS );
		$threshold = time() - $max_age;

		// [internal] ([internal]): запись, ссылающаяся на временный файл, не должна
		// переживать сам файл. Два TTL в этом же классе жили независимо друг от друга: файл
		// удаляется через temp_file_max_age + grace (см. run() выше), а его job_result — только
		// через сутки. В зазоре между ними REST честно отдаёт `result: {expired: true}` на
		// давно мёртвый архив, и на живом стенде так накопились 19 из 25 записей.
		//
		// Порог считается из ТЕХ ЖЕ фильтров, что владеют файловой частью, плюс один интервал
		// планировщика — то есть переживает файл ровно настолько, чтобы гарантированно застать
		// хотя бы один прогон этой очистки после его удаления. Связка через общие фильтры —
		// причина того, что рассинхрон не воспроизведётся, если кто-то поменяет любой из двух.
		//
		// Проверять `file_exists()` вместо TTL намеренно НЕ стали (WP Senior Dev skeptic):
		// путь к temp-директории может резолвиться по-разному в веб- и cron-процессе
		// (`sys_get_temp_dir()` под PrivateTmp, open_basedir, сетевое хранилище), и тогда
		// cron удалял бы запись о файле, который для веб-запроса жив и скачивается.
		$file_backed_threshold = time() - (int) apply_filters(
			'plathix/infrastructure/job_result_file_backed_max_age',
			(int) apply_filters( 'plathix/infrastructure/temp_file_max_age', HOUR_IN_SECONDS * 2 )
				+ (int) apply_filters( 'plathix/infrastructure/temp_file_grace_period', 1800 )
				+ self::CLEANUP_INTERVAL
		);

		$option_names = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off cron scan of a bounded job_result option set, same pattern as ImportCheckpointCleanupJobRunner.
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( 'plathix_job_result_' ) . '%'
			)
		);

		foreach ( (array) $option_names as $option_name ) {
			$value = get_option( (string) $option_name );
			if ( ! is_array( $value ) ) {
				continue;
			}

			// Записи с `file_path` (ZIP) живут по файловому порогу, остальные (Import пишет
			// adapter/moved без пути — ImportManager::handle_job_import()) — по суточному.
			$limit = '' !== (string) ( $value['file_path'] ?? '' ) ? $file_backed_threshold : $threshold;

			$created_at = $value['_created_at'] ?? null;
			if ( null !== $created_at && (int) $created_at >= $limit ) {
				continue;
			}

			delete_option( (string) $option_name );
		}
	}
}
