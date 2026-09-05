<?php

declare(strict_types=1);

namespace Plathix\User;

enum AccessLevel: string
{
	case Full = 'full';
	case View = 'view';
	case Upload = 'upload';
	case None = 'none';

	public function can_edit(): bool {
		return $this === self::Full;
	}

	public function can_upload(): bool {
		return match ( $this ) {
			self::Full, self::Upload => true,
			default => false,
		};
	}

	/**
	 * [internal]: единый ranking-компаратор — раньше `AjaxGuard::require()` и
	 * `RestController::level_satisfies()` независимо переизобретали одно и то же сравнение
	 * (`match` vs ручная rank-таблица), с риском рассинхрона при добавлении нового уровня.
	 *
	 * Ranking: None(0) < View(1) < Upload(2) < Full(3).
	 */
	public function satisfies(self $required): bool {
		$rank = [
			self::None->value => 0,
			self::View->value => 1,
			self::Upload->value => 2,
			self::Full->value => 3,
		];

		return ( $rank[ $this->value ] ?? 0 ) >= ( $rank[ $required->value ] ?? 0 );
	}

	/**
	 * [internal]: единый резолвер AccessLevel→WP capability по post_type — раньше
	 * `RestController::$cap_map`/`get_cap_entry()` и `AjaxGuard::require()` независимо
	 * реализовывали одну и ту же таблицу, с рассинхроном для non-attachment CPT: REST давал
	 * голый `read` для `View`, AJAX — `edit_posts`-типа. WP core (`wp-admin/menu.php`) гейтит
	 * "All Items" list-screen ЛЮБОГО non-attachment post_type на `cap->edit_posts` — `read`
	 * шире, чем сам WP когда-либо даёт для этого экрана. Canonical — `edit_posts`-типа.
	 *
	 * attachment/'' — read-only view-tier: `View`→`read` (медиабиблиотека, [internal]),
	 * всё остальное (`Upload`/`Full`, и `None` как edge-case, воспроизводящий старое поведение
	 * обоих потребителей до унификации) →`upload_files`. Non-attachment CPT: `View`/`Upload`→
	 * `edit_posts`-типа, `Full`→`publish_posts`-типа (через `get_post_type_object()`, фолбэк на
	 * голый cap-string, если объект типа не резолвится).
	 */
	public function resolve_cap(string $post_type): string {
		if ( '' === $post_type || 'attachment' === $post_type ) {
			return $this === self::View ? 'read' : 'upload_files';
		}

		$obj = get_post_type_object( $post_type );

		return match ( $this ) {
			self::Full => $obj?->cap->publish_posts ?? 'publish_posts',
			default    => $obj?->cap->edit_posts ?? 'edit_posts',
		};
	}
}
