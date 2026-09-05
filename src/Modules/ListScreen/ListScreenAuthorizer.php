<?php

declare(strict_types=1);

namespace Plathix\Modules\ListScreen;

use Plathix\Http\AjaxGuard;
use Plathix\Http\Nonce;
use Plathix\User\AccessLevel;

/**
 * Авторизация AJAX-запроса живой перерисовки списка ([internal], [internal]).
 *
 * Вынесено из {@see ListScreenFragmentsController}: авторизация — сквозная политика с автономной
 * причиной меняться (allowlist экранов + делегирование cap/access-гейта), которая эволюционирует
 * независимо от рендера фрагментов.
 *
 * Cap/access-проверка (post_type-гейт → AccessLevel → cap-резолв) делегирована в
 * {@see AjaxGuard::require()} — единую точку правды транспортной авторизации ([internal],
 * [internal]); [internal] убрал здесь ручную копию той же последовательности.
 * screen_base-allowlist специфична для этого контроллера и в AjaxGuard не входит — остаётся тут.
 *
 * При отказе метод завершает запрос через `wp_send_json_error` (в проде — die; в тестах —
 * JsonHalt).
 *
 * @see ListScreenFragmentsController::handle()
 */
final class ListScreenAuthorizer
{
	// CTAN-201: Free attachment-native — fragments-канал обслуживает только медиатеку;
	// экраны списков записей рендерит PRO-контроллер со своим action.
	private const ALLOWED_SCREEN_BASES = [ 'upload' ];

	/**
	 * Проверяет право на рендер запрошенного list-screen фрагмента. При отказе завершает запрос.
	 *
	 * Порядок гейта: nonce → allowlist экрана → (AjaxGuard::require) post_type-гейт → access-level →
	 * cap. Для attachment — `read` (рендер = read-операция, view-only роль видит грид без
	 * upload_files); для CPT — `edit_posts` типа — см. AjaxGuard::require().
	 *
	 * @param array<string, mixed> $request Разобранный навигационный запрос (см. контроллер).
	 */
	public function authorize(array $request): void {
		Nonce::verify_or_die();

		if ( ! in_array( $request['screen_base'], self::ALLOWED_SCREEN_BASES, true ) ) {
			$this->json_error( 'Invalid screen_base.', 400 );
			return;
		}

		AjaxGuard::require( AccessLevel::View, null, (string) $request['post_type'] );
	}

	private function json_error(string $message, int $status = 400): void {
		wp_send_json_error( [ 'message' => $message ], $status );
	}
}
