<?php

declare(strict_types=1);

namespace Plathix\Modules\Import;

use Plathix\Http\AjaxGuard;
use Plathix\Infrastructure\JobDispatcher;
use Plathix\Infrastructure\RateLimiter;
use Plathix\Infrastructure\Cache;
use Plathix\User\AccessLevel;

/**
 * AJAX-обработчик `plathix_import`.
 *
 * Перенесён из AjaxRouter::import_data() ([internal]) в модуль Import.
 * Контракт сохранён 1:1: di/nonce/posttype-gate идентичны оригиналу.
 *
 * guard-логика (nonce + posttype-gate + capability) делегирована в {@see AjaxGuard::require()}
 * — единую точку правды транспортной авторизации ([internal]); [internal] убрал здесь ручную
 * копию той же последовательности.
 */
final class ImportAjaxHandler
{
	public function __construct(
		private readonly ?JobDispatcher $jobs = null,
		private readonly ?RateLimiter $rate_limiter = null,
	) {
	}

	/** Регистрирует wp_ajax_plathix_import и wp_ajax_plathix_import_status. */
	public function register(): void
	{
		add_action( 'wp_ajax_plathix_import', [ $this, 'handle' ] );
		add_action( 'wp_ajax_plathix_import_status', [ $this, 'handle_status' ] );
	}

	/** Обработчик AJAX-запроса импорта. */
	public function handle(): void
	{
		$this->guard();

		$adapter = sanitize_key( (string) wp_unslash( $_POST['adapter'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() above calls Nonce::verify_or_die()
		$restart = 'true' === sanitize_key( (string) wp_unslash( $_POST['restart'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() above calls Nonce::verify_or_die()
		$post_type = $this->request_post_type();

		if ( $restart ) {
			// "Начать заново" ([internal]): явный откат частично созданного дерева перед
			// новым запуском, чтобы resume не подхватил отвергнутый пользователем прогресс.
			// [internal] ([internal]): rollback_partial теперь может отказать локом
			// (бегущая import-джоба того же адаптера). Продолжить в start_import при отказе
			// значило бы молча превратить "начать заново" в resume отвергнутого прогресса —
			// честный отказ 409 вместо этого.
			if ( 'locked' === ( new ImportManager() )->rollback_partial( $adapter ) ) {
				wp_send_json_error(
					[
						'code'    => 'import_running',
						'message' => __( 'Import is already running for this adapter — cannot restart yet.', 'plathix' ),
					],
					409
				);
			}
		}
		$dispatch_reason = $this->rate_limiter()->can_dispatch_heavy_job( JobDispatcher::JOB_IMPORT, [ 'adapter' => $adapter, 'post_type' => $post_type ], get_current_user_id() );

		if ( $dispatch_reason === 'per_user' ) {
			wp_send_json_error( [ 'code' => 'job_already_queued', 'message' => __( 'Import job already queued for this user.', 'plathix' ) ], 429 );
		}

		if ( $dispatch_reason === 'server_cap' ) {
			status_header( 429 );
			header( 'Retry-After: 30' );
			wp_send_json_error( [ 'code' => 'server_busy', 'message' => __( 'Import queue is busy.', 'plathix' ) ], 429 );
		}

		// [internal]: payload+dispatch сборка унифицирована в ImportManager::start_import()
		// — этот транспорт теперь тонкий адаптер, не независимая копия.
		$result = ( new ImportManager() )->start_import( $adapter, $post_type, get_current_user_id() );

		// BOUNDDTO-002 ([internal]): читаем свойства ImportJobDTO, не ключи массива.
		// Внешняя JSON-форма не меняется — набор, порядок и значения ключей те же,
		// это заморожено в ImportStartResponseGoldenMasterTest.
		wp_send_json_success(
			[
				'queued'   => $result->isQueued(),
				'jobId'    => $result->jobId,
				'status'   => $result->status,
				'adapter'  => $result->adapter,
				'postType' => $result->postType,
			]
		);
	}

	/**
	 * [internal] ([internal], четвёртая причина переоткрытия): опрос статуса job'а
	 * тем же транспортом, что и диспатч (admin-ajax.php). REST-роут `jobs/{id}`, который
	 * ранее опрашивал клиент, никогда не существовал в этом плагине — реальный диспатч
	 * импорта всегда шёл через wp_ajax_plathix_import, не через REST.
	 */
	public function handle_status(): void
	{
		$this->guard();

		$job_id = absint( wp_unslash( $_POST['job_id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() above calls Nonce::verify_or_die()

		if ( $job_id <= 0 ) {
			wp_send_json_error( [ 'message' => __( 'Invalid job id.', 'plathix' ) ], 400 );
		}

		wp_send_json_success( $this->jobs()->get_status( $job_id ) );
	}

	/**
	 * Guard: nonce + posttype-gate + capability — делегировано в {@see AjaxGuard::require()}
	 * ([internal], единая точка правды, [internal]).
	 */
	private function guard(): void
	{
		AjaxGuard::require( AccessLevel::Full, 'manage_options', $this->request_post_type() );
	}

	/** @return string post_type из POST/REQUEST (fallback attachment). */
	private function request_post_type(): string
	{
		return sanitize_key( (string) wp_unslash( $_POST['post_type'] ?? $_REQUEST['post_type'] ?? 'attachment' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- read while evaluating the AjaxGuard::require() argument, i.e. BEFORE the nonce check inside it (PHP evaluates arguments first). Safe because the value is only sanitize_key()'d and used to resolve which capability to demand; it causes no side effect and no data access before AjaxGuard::require() verifies nonce+capability. Do not add side effects here.
	}

	private function jobs(): JobDispatcher
	{
		return $this->jobs ?? new JobDispatcher();
	}

	private function rate_limiter(): RateLimiter
	{
		return $this->rate_limiter ?? new RateLimiter( Cache::make() );
	}
}
