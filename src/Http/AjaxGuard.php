<?php

declare(strict_types=1);

namespace Plathix\Http;

use Plathix\User\AccessLevel;
use Plathix\User\AccessResolver;

/**
 * Shared-хелпер авторизации AJAX-роутов ([internal] #135).
 *
 * Единый владелец порядка проверок: nonce → (опц.) post-type-гейт → access-level → cap →
 * current_user_can. Раньше эта логика была скопирована в приватном AjaxRouter::guard и
 * прицельно продублирована в DataWipeAjax::assert_authorized (риск дрейфа). Оба потребителя
 * теперь делегируют сюда, сохраняя тонкие protected-обёртки как тест-швы.
 *
 * Слой: transport-boundary авторизация (перевод «кто ты» из AccessResolver в «пустить / 403»
 * на AJAX-канале), рядом с Nonce. Движок прав (AccessResolver/AccessLevel) остаётся в
 * Plathix\User — сюда не переезжает.
 */
final class AjaxGuard
{
	/**
	 * Авторизует текущий AJAX-запрос или завершает его 403 (wp_send_json_error → die).
	 *
	 * Порядок дословно из бывшего AjaxRouter::guard — НЕ переставлять:
	 * 1. Nonce первым (анти-оракул: post-type-гейт ПОСЛЕ nonce, чтобы ответ не различал
	 *    «нет nonce» vs «тип не поддержан»).
	 * 2. Post-type-гейт только при $post_type !== null (роутер передаёт request_post_type();
	 *    data-wipe передаёт null — его данные не привязаны к post_type, гейт не нужен).
	 * 3. AccessLevel-match по требуемому уровню.
	 * 4. Cap-резолв по post_type, только если cap не задан И $post_type !== null (симметрия REST).
	 * 5. Fail-closed guard ([internal]): $post_type===null И cap всё ещё пуст → 403 явно, ДО
	 *    current_user_can. Инвариант «post_type===null ⇒ cap обязан быть непустым» раньше держался
	 *    только этим докблоком (дисциплина caller'ов); теперь он в коде метода.
	 * 6. Итоговая проверка $allowed && current_user_can($cap).
	 *
	 * @param AccessLevel $min       Минимальный требуемый уровень доступа.
	 * @param string|null $cap       Явная capability; null/'' → резолв по post_type (если он задан).
	 * @param string|null $post_type Post-type запроса для гейта+cap-резолва; null → обе ветки
	 *                               пропущены (cap берётся как передан). Дефолт ОБЯЗАН быть null:
	 *                               иначе data-wipe получил бы enabled-гейт и cap-резолв (регресс).
	 *                               $post_type===null требует непустой $cap — иначе 403 (шаг 5).
	 */
	public static function require(AccessLevel $min, ?string $cap = null, ?string $post_type = null): void {
		Nonce::verify_or_die();

		// Гейт enabled-типов ([internal], паритет с REST RestController::check()). Неподдерживаемый
		// post_type ('post' при Free-enabled=[attachment]) иначе проходит cap-резолв по edit_posts и
		// через fallback-таксономию дотягивается до attachment-folder-state (privilege-скос на
		// read-пути). effective '' → 'attachment' (медиабиблиотека всегда в enabled). После nonce —
		// анти-оракул. Пропускается при $post_type === null (data-wipe: данные не привязаны к типу).
		if ( null !== $post_type ) {
			// CTAN-201: Free attachment-native — жёсткий type-gate без списка/фильтра
			// (Guideline 5: точки включения не существует). PRO-типы обслуживает PRO-канал.
			$effective_type = '' === $post_type ? 'attachment' : $post_type;
			if ( 'attachment' !== $effective_type ) {
				wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'plathix' ) ], 403 );
			}
		}

		$user_level = AccessResolver::for_current_user();
		$allowed    = $user_level->satisfies( $min );

		// Cap не задан → резолв из post_type через единый резолвер ([internal]), которым
		// также пользуется Authorization::cap_entry() (CTAN-101) — не дублируется здесь. Только при
		// заданном post_type: без него (data-wipe) cap приходит явным ('manage_options') и
		// резолв не нужен.
		if ( ( null === $cap || '' === $cap ) && null !== $post_type ) {
			$cap = $min->resolve_cap( $post_type );
		}

		// [internal]: fail-closed guard. Если post_type===null (гейт+резолв выше оба пропущены)
		// И cap всё ещё пуст — это caller, который не передал ни post_type, ни явный cap. Без этой
		// проверки следующая строка выполнила бы current_user_can((string) null) = current_user_can(''),
		// что для administrator/super-admin в реальном WP core не гарантирует false (fail-open).
		// Сегодня недостижимо всеми существующими caller'ами (data-wipe передаёт явный cap;
		// остальные — непустой post_type), но контракт метода не должен полагаться на дисциплину
		// caller'ов для инварианта, который сам метод объявляет единственным владельцем.
		if ( null === $post_type && ( null === $cap || '' === $cap ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'plathix' ) ], 403 );
		}

		if ( ! $allowed || ! current_user_can( (string) $cap ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'plathix' ) ], 403 );
		}
	}

	/**
	 * [internal] (follow-up находка): только access-level+cap-проверка, БЕЗ nonce и БЕЗ
	 * post-type-гейта — для caller'ов с собственной per-feature nonce-схемой
	 * (`check_ajax_referer($ownAction)`), которую {@see self::require()} параметризовать не
	 * должен (её `Nonce::verify_or_die()` — единый плагинный nonce, смешивать с per-feature
	 * actions было бы размытием ответственности, WP Architecture skeptic review).
	 *
	 * Закрывает реальный access-паритет баг: голый `current_user_can($cap)` без
	 * {@see AccessResolver} не видит `plathix/user/access_level`-override (PRO
	 * `RolePolicy` понижает Full для конкретного юзера/роли) — caller, зовущий `current_user_can`
	 * напрямую, пропускает уже понижённого админа. Caller обязан вызвать свой
	 * `check_ajax_referer()`/`check_admin_referer()` ДО этого метода (анти-оракул порядок —
	 * тот же принцип, что и в {@see self::require()}, просто nonce не входит в этот метод).
	 *
	 * @param AccessLevel $min Минимальный требуемый уровень доступа.
	 * @param string      $cap Capability, проверяемая ПОСЛЕ access-level match (явная, без резолва).
	 */
	public static function require_cap(AccessLevel $min, string $cap): void {
		$allowed = AccessResolver::for_current_user()->satisfies( $min );

		if ( ! $allowed || ! current_user_can( $cap ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'plathix' ) ], 403 );
		}
	}
}
