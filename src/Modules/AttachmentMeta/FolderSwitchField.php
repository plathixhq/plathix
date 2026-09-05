<?php

declare(strict_types=1);

namespace Plathix\Modules\AttachmentMeta;

use Plathix\Core\FolderRepository;
use Plathix\Core\TaxonomyResolver;

/**
 * Разметка split-control поля "Папка" — переход в текущую папку (ссылка) + кнопка
 * "Сменить" (открывает popover-дерево, JS resources/js/folder-switch/folder-switch-ui.js).
 *
 * Общий рендерер для обоих мест: медиа-модалка (AttachmentDetails::add_folder_field)
 * и страница вложения (AttachmentSideMetaBox::render) — одна точка правды для разметки,
 * чтобы стилизация и JS-хуки не расходились между местами.
 *
 * В системе не бывает файлов "без папки": отсутствие term_relationship в plathix_folder
 * (Upload.php/FolderCountCalculator.php) трактуется везде как виртуальная принадлежность
 * к "Несортированные" — здесь резолвится тот же термин, а не отдельный placeholder-текст.
 */
final class FolderSwitchField
{
	public static function render(int $attachment_id): string {
		$taxonomy = TaxonomyResolver::fromPostType( 'attachment' );
		$terms    = get_the_terms( $attachment_id, $taxonomy );
		$term     = ( ! empty( $terms ) && ! is_wp_error( $terms ) ) ? $terms[0] : null;

		if ( ! $term instanceof \WP_Term ) {
			$repository          = new FolderRepository();
			$uncategorized_id    = $repository->get_uncategorized_term_id( $taxonomy );
			$uncategorized       = $uncategorized_id > 0 ? get_term( $uncategorized_id, $taxonomy ) : null;
			$term                = $uncategorized instanceof \WP_Term ? $uncategorized : null;
		}

		$left = $term instanceof \WP_Term
			? sprintf(
				'<a href="%s" class="plathix-folder-switch__goto" target="_top">' .
				'<span class="plathix-folder-switch__icon" aria-hidden="true"></span>' .
				'<span class="plathix-folder-switch__name">%s</span>' .
				'<span class="plathix-folder-switch__extlink" aria-hidden="true"></span>' .
				'</a>',
				esc_url( self::folder_url( (int) $term->term_id ) ),
				esc_html( self::build_breadcrumb( $term, $taxonomy ) )
			)
			: '<span class="plathix-folder-switch__empty">' . esc_html__( '— No folder —', 'plathix' ) . '</span>';

		return sprintf(
			'<span class="plathix-folder-switch__field" data-attachment-id="%1$d" data-taxonomy="%2$s" data-current-folder-id="%3$d">' .
			'<span class="plathix-folder-switch__bar">' .
			'%4$s' .
			'<span class="plathix-folder-switch__divider" aria-hidden="true"></span>' .
			'<button type="button" class="plathix-folder-switch__trigger" aria-expanded="false">' .
			'<span class="plathix-folder-switch__trigger-label">%5$s</span>' .
			'<span class="plathix-folder-switch__chevron" aria-hidden="true"></span>' .
			'</button>' .
			'</span>' .
			'</span>',
			$attachment_id,
			esc_attr( $taxonomy ),
			$term instanceof \WP_Term ? (int) $term->term_id : 0,
			$left,
			esc_html__( 'Change', 'plathix' )
		);
	}

	private static function folder_url(int $term_id): string {
		return add_query_arg(
			[
				'mode'           => 'grid',
				'plathix_folder' => $term_id,
			],
			admin_url( 'upload.php' )
		);
	}

	private static function build_breadcrumb(\WP_Term $term, string $taxonomy): string {
		$ancestors = array_reverse( get_ancestors( $term->term_id, $taxonomy, 'taxonomy' ) );
		$parts     = [];
		foreach ( $ancestors as $ancestor_id ) {
			$ancestor = get_term( $ancestor_id, $taxonomy );
			if ( $ancestor instanceof \WP_Term ) {
				$parts[] = self::display_name( $ancestor, $taxonomy );
			}
		}
		$parts[] = self::display_name( $term, $taxonomy );
		return implode( ' / ', $parts );
	}

	/**
	 * Системные папки (Несортированные/Корзина/Медиафайлы) хранят англ. имя термина
	 * в БД (см. Activator::ensure_uncategorized_terms) — тот же паттерн перевода "на
	 * лету", что уже применяет FolderCountService::get_all_cached() для REST-дерева,
	 * иначе split-control поле расходится языком с popover-деревом той же фичи.
	 */
	private static function display_name(\WP_Term $term, string $taxonomy): string {
		$repository = new FolderRepository();
		if ( $repository->is_uncategorized_folder( (int) $term->term_id, $taxonomy ) ) {
			return __( 'Uncategorized', 'plathix' );
		}
		return $term->name;
	}
}
