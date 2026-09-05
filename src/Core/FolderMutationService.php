<?php

declare(strict_types=1);

namespace Plathix\Core;

/**
 * Единое применение изменений папки (rename/move/order/color) — общее ядро для
 * REST-путей update_folder (одиночная правка) и batch_update_folders (пакетная).
 *
 * Раньше эта последовательность жила в двух местах: FolderController::update_folder
 * (из request-параметров) и FolderController::apply_batch_folder_updates (из array-item),
 * расходясь лишь источником данных и аудитом. Здесь она сведена к одному месту
 * ([internal] #94): контроллеры собирают $changes и вызывают apply_changes,
 * а аудит-эмиссию (folder_renamed/folder_moved) делают сами — сервис аудит НЕ трогает
 * (batch не должен эмитить per-item, update — должен, различие остаётся в контроллерах).
 *
 * Порядок применения и коды ошибок — 1:1 с прежним apply_batch_folder_updates. Первая
 * WP_Error прерывает и возвращается; успех → null. Условие каждого шага — наличие ключа
 * в $changes (array_key_exists), поэтому вызывающий кладёт только реально меняемые поля.
 */
final class FolderMutationService
{
	public function __construct(
		private readonly FolderTreeService $tree,
		private readonly FolderCountService $folders
	) {
	}

	/**
	 * Применить набор изменений к папке $id. Ключи $changes: name, parent_id, position, color
	 * (любое подмножество). Порядок: rename → move → set_order → color+invalidate.
	 *
	 * Если шаг N (N > 1) падает после того, как предыдущие шаги уже закоммитились, они НЕ
	 * откатываются ([internal] — откат многошаговой WP-term мутации добавил бы компенсирующий
	 * код вокруг wp_update_term/recursive-count chain, не давая настоящей атомарности, т.к.
	 * сами шаги не транзакционны на уровне WP core). Вместо отката возвращаемый WP_Error несёт
	 * в data['applied'] список ключей $changes, что успели примениться до сбоя — так вызывающий
	 * (батч-путь) может сообщить клиенту частичное состояние вместо "ничего не произошло".
	 *
	 * @param array<string, mixed> $changes
	 */
	public function apply_changes(int $id, array $changes, string $taxonomy): ?\WP_Error {
		$applied = [];

		if ( array_key_exists( 'name', $changes ) ) {
			$error = $this->tree->rename( $id, sanitize_text_field( (string) $changes['name'] ), $taxonomy );
			if ( is_wp_error( $error ) ) {
				/** @var \WP_Error $error Narrowed inside is_wp_error() guard (namespaced test stub lacks narrowing; see [internal] [internal]). */
				return $this->with_applied( $error, $applied );
			}
			$applied[] = 'name';
		}

		if ( array_key_exists( 'parent_id', $changes ) ) {
			$error = $this->tree->move( $id, absint( $changes['parent_id'] ), $taxonomy );
			if ( is_wp_error( $error ) ) {
				/** @var \WP_Error $error Narrowed inside is_wp_error() guard. */
				return $this->with_applied( $error, $applied );
			}
			$applied[] = 'parent_id';
		}

		if ( array_key_exists( 'position', $changes ) ) {
			$error = $this->tree->set_order( $id, absint( $changes['position'] ), $taxonomy );
			if ( is_wp_error( $error ) ) {
				/** @var \WP_Error $error Narrowed inside is_wp_error() guard. */
				return $this->with_applied( $error, $applied );
			}
			$applied[] = 'position';
		}

		if ( array_key_exists( 'color', $changes ) ) {
			update_term_meta( $id, PLATHIX_TERM_COLOR, sanitize_hex_color( (string) $changes['color'] ) ?? '' );
			$this->folders->invalidate( $taxonomy );
		}

		return null;
	}

	/**
	 * Прикрепляет already-applied шаги к $error, сохраняя остальной error_data (например
	 * ['status' => 409], который читает RestControllerHelpers::error_response()). Возвращает
	 * НОВЫЙ WP_Error — проектный stub (tests/bootstrap.php) и реальный WP_Error не имеют общего
	 * мутирующего add_data() в паттерне, используемом этим кодом (везде конструктор с data
	 * третьим параметром, см. FolderTreeService), поэтому data собирается заново, а не мутируется.
	 * Пустой $applied (сбой на первом шаге) не добавляет ключ — форма ошибки не меняется для
	 * уже покрытого тестами случая "ничего не успело примениться".
	 *
	 * @param list<string> $applied
	 */
	private function with_applied(\WP_Error $error, array $applied): \WP_Error {
		if ( [] === $applied ) {
			return $error;
		}

		$data = $error->get_error_data();
		$data = is_array( $data ) ? $data : [];
		$data['applied'] = $applied;

		return new \WP_Error( $error->get_error_code(), $error->get_error_message(), $data );
	}
}
