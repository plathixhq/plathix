<?php

declare(strict_types=1);

namespace Plathix;

use Plathix\Infrastructure\JobDispatcher;

class Deactivator
{
	// [internal] ([internal]): $network_wide — параметр, который WP core реально
	// передаёт register_deactivation_hook-callback'у. is_network_admin() проверяет контекст
	// ТЕКУЩЕГО HTTP-запроса, а не факт network-wide деактивации — симметрично Activator::run()
	// ([internal]/#209).
	public static function run(bool $network_wide = false): void {
		if ( is_multisite() && $network_wide ) {
			$site_ids = get_sites(
				[
					'fields' => 'ids',
					'number' => 0,
				]
			);

			foreach ( $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::run_for_site( (int) $site_id );
				restore_current_blog();
			}

			return;
		}

		self::run_for_site( get_current_blog_id() );
	}

	private static function run_for_site(int $blog_id): void {
		// Cron журнала (plathix_audit_cleanup) — собственность PRO ([internal]);
		// его расписание регистрирует/снимает PlathixPro\Modules\Audit. Free при деактивации
		// журнальный hook не трогает (без PRO его и нет).
		$group = JobDispatcher::group_for_blog( $blog_id );

		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			// [internal]: as_unschedule_all_actions($hook, [], $group) с обоими непустыми
			// $hook и $group не берёт быстрый cancel_actions_by_hook/group путь (Action
			// Scheduler functions.php) и падает в поиск по точному args — тот же shape,
			// что JobDispatcher::dispatch_recurring() реально положил в очередь
			// ([['blog_id' => $blog_id]]), иначе lookup не находит запись и ничего не снимает.
			$recurring_args = [ [ 'blog_id' => $blog_id ] ];
			as_unschedule_all_actions( JobDispatcher::JOB_CLEANUP_TEMP, $recurring_args, $group );
			as_unschedule_all_actions( JobDispatcher::JOB_ORPHAN_CLEANUP, $recurring_args, $group );
			// JOB_ZIP_GENERATE unschedule уехал в PRO ([internal]): PRO-модуль снимает
			// своё zip-расписание в register_deactivation_hook. Free zip-cron не знает.
			// JOB_IMPORT/JOB_REORDER никогда не диспатчатся как recurring (только одноразовый
			// dispatch()) — args=[] здесь корректен, менять не нужно ([internal]).
			as_unschedule_all_actions( JobDispatcher::JOB_IMPORT, [], $group );
			as_unschedule_all_actions( JobDispatcher::JOB_REORDER, [], $group );
			as_unschedule_all_actions( JobDispatcher::JOB_IMPORT_CHECKPOINT_CLEANUP, $recurring_args, $group );
			// [internal]: снятие reconcile-джобы (тот же recurring-shape, что три выше).
			as_unschedule_all_actions( JobDispatcher::JOB_FOLDER_COUNT_RECONCILE, $recurring_args, $group );
		}

		// [internal]: generic extension point, симметричный plathix/jobs/register_handlers
		// (JobDispatcher::announce_extensible_handlers()) — Free-собственные модули (не
		// перечисленные явно выше как platform JOB_* константы) подписываются здесь своим
		// unschedule-колбэком вместо жёсткого перечисления в этом файле. Пример подписчика:
		// Trash\Module::unschedule_retention_job().
		do_action( 'plathix/jobs/unschedule', $blog_id );

		global $wpdb;

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- cleanup write (DELETE transient job-result options) at deactivation; runs once, caching N/A for writes
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( 'plathix_job_result_' ) . '%'
			)
		);
	}
}
