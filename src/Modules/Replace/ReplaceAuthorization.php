<?php

declare(strict_types=1);

namespace Plathix\Modules\Replace;

use Plathix\User\AccessResolver;

/**
 * Авторизационная политика замены вложения ([internal], [internal]).
 *
 * Вынесено из {@see AttachmentReplaceService}: авторизация — сквозная политика с автономной
 * причиной меняться (модель прав — AccessResolver, роли, cap `edit_post`, actor-mode), которая
 * эволюционирует независимо от транзакции замены файла. Держать её приватными методами
 * доменного ядра смешивало две причины меняться в одном классе.
 *
 * Класс несёт actor-модель ЦЕЛИКОМ, включая ветку `mode === 'system_cli'` (CLI-replace без
 * залогиненного WP-юзера) — её нельзя подменять cap-only проверкой `current_user_can`, иначе
 * CLI-путь сломается. Опирается на движок {@see AccessResolver} (живёт во Free); per-user
 * override, уезжающий в PRO, здесь напрямую не читается — вынос Free/PRO-границу не пересекает.
 *
 * @see AttachmentReplaceService::replace()
 */
final class ReplaceAuthorization
{
	/**
	 * Нормализует сырой actor-context в стабильную форму.
	 *
	 * @param mixed $context Произвольный вход (ожидается массив с mode/user_id).
	 * @return array{mode:string,user_id:int}
	 */
	public function normalize(mixed $context): array {
		$context = is_array( $context ) ? $context : [];
		$mode = sanitize_key( (string) ( $context['mode'] ?? 'wp_user' ) );

		return [
			'mode'    => $mode !== '' ? $mode : 'wp_user',
			'user_id' => max( 0, (int) ( $context['user_id'] ?? 0 ) ),
		];
	}

	/**
	 * Решает, вправе ли актор заменить данное вложение.
	 *
	 * Порядок: system_cli (доверенный процесс) → true; иначе требуется идентифицированный
	 * юзер с уровнем can_upload И cap `edit_post` на это вложение.
	 *
	 * @param array{mode:string,user_id:int} $actor_context
	 */
	public function can_replace(array $actor_context, int $attachment_id): bool {
		if ( $actor_context['mode'] === 'system_cli' ) {
			return true;
		}

		$user_id = $actor_context['user_id'];
		if ( $user_id <= 0 ) {
			return false;
		}

		$level = ( new AccessResolver( $user_id ) )->resolve();

		return $level->can_upload() && user_can( $user_id, 'edit_post', $attachment_id );
	}
}
