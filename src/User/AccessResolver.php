<?php

declare(strict_types=1);

namespace Plathix\User;

final class AccessResolver
{
	public function __construct(
		private readonly int $user_id = 0
	) {
	}

	public static function for_current_user(): AccessLevel {
		return ( new self( get_current_user_id() ) )->resolve();
	}

	/**
	 * [internal]: единый источник правды для гейта "полный админ Plathix" — раньше 8 мест
	 * в Free и PRO независимо повторяли `current_user_can('manage_options') &&
	 * self::for_current_user() === AccessLevel::Full` вручную. Обе проверки намеренно
	 * объединены (WP core capability И Plathix policy-фильтр, не дублирование одной и той
	 * же информации — see resolve()/filter_level()).
	 */
	public static function currentUserIsFullAdmin(): bool {
		return current_user_can( 'manage_options' ) && self::for_current_user() === AccessLevel::Full;
	}

	/**
	 * Грубый Free-дефолт уровня доступа из нативных WP-capabilities.
	 *
	 * ГРАНИЦА Free/PRO ([internal], уточнено [internal]
	 * [internal]): движок отдаёт ТОЛЬКО cap-дефолт (manage_options→Full, upload_files→Upload,
	 * иначе None). Тонкая политика — per-user override (`plathix_user_access`) и per-role карта
	 * (`plathix_role_access`, включая её ДЕФОЛТНОЕ содержимое —
	 * `PlathixPro\Modules\Access\RolePolicy::default_role_access()`) — применяется ПОВЕРХ через
	 * фильтр `plathix/user/access_level` подписчиком `RolePolicy` (приоритет 5), целиком в PRO.
	 * Free не содержит ни сева, ни дефолтного содержимого per-role карты — не знает, какой
	 * уровень доступа у какой роли по умолчанию, это чистое PRO-policy знание. Без PRO
	 * подписчика нет → возвращается грубый cap-дефолт, без фаталов.
	 */
	public function resolve(): AccessLevel {
		if ( $this->user_id <= 0 ) {
			return $this->filter_level( AccessLevel::None );
		}

		if ( user_can( $this->user_id, 'manage_options' ) ) {
			return $this->filter_level( AccessLevel::Full );
		}

		if ( user_can( $this->user_id, 'upload_files' ) ) {
			return $this->filter_level( AccessLevel::Upload );
		}

		return $this->filter_level( AccessLevel::None );
	}

	private function filter_level(AccessLevel $level): AccessLevel {
		$value = apply_filters( 'plathix/user/access_level', $level->value, $this->user_id );

		return AccessLevel::tryFrom( (string) $value ) ?? $level;
	}
}
