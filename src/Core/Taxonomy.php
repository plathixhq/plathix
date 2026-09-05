<?php

declare(strict_types=1);

namespace Plathix\Core;

use Plathix\Loader;

final class Taxonomy
{
	public function __construct(
		private readonly Loader $loader
	) {
		$this->loader->add_action('init', $this, 'register');
	}

	public function register(): void {
		self::register_all();
		self::ensure_system_terms();

		// [internal]: штатный init-цикл — доказательство, что причина прошлого lazy-recovery
		// (если он был) уже не актуальна. delete_option не проходит через SettingsPage::
		// guard_option_updates (тот подписан только на pre_update_option), поэтому сброс не
		// требует allowlist-исключений. Проверка "уже выставлен" — чтобы не писать в БД на
		// каждом обычном запросе, только когда реально есть что сбрасывать.
		if ( 1 === (int) get_option( 'plathix_boot_recovered_lazily', 0 ) ) {
			delete_option( 'plathix_boot_recovered_lazily' );
		}
	}

	/**
	 * Флаг «системы готовы в этом процессе»: guard, чтобы lazy self-heal (ensure_ready) не
	 * повторял регистрацию/вставку на каждом чтении дерева. Сбрасывается вместе с runtime-кэшем.
	 */
	private static bool $ready = false;

	/**
	 * [internal] (Слой 2): ленивое самовосстановление системного состояния в точке
	 * ЧТЕНИЯ дерева, независимое от выживания init-очереди.
	 *
	 * ЗАЧЕМ: в зоопарке плагинов чужой фатал на `init` может оборвать очередь ДО нашего
	 * `Taxonomy::register` (доказано на проде клиента A: двойной Elementor фаталит init@10 →
	 * наш колбэк не вызывается → таксономия не зарегистрирована, trash-term не создан →
	 * корзины нет). init-хук `register()` остаётся fast-path, но перестаёт быть ЕДИНСТВЕННЫМ
	 * путём: get_all() зовёт ensure_ready() перед чтением, и системы поднимаются лениво даже
	 * если init оборвался и activation не переигрывалась (копи-деплой).
	 *
	 * Идемпотентно и дёшево: после первого успешного прогона в процессе — no-op по $ready-флагу.
	 * Гарантирует ПОРЯДОК: сначала register_taxonomy (иначе wp_insert_term в незарегистрированную
	 * таксономию = silent fail), потом системные термы, потом сигнал модулям (Trash) дособрать своё.
	 *
	 * @param bool $lazy_recovery true если вызвано с пути чтения (не с init) — для наблюдаемости.
	 */
	public static function ensure_ready(bool $lazy_recovery = false): void {
		if ( self::$ready ) {
			return;
		}

		// 1. Таксономии зарегистрированы? Если init оборвался — их может не быть.
		$needed_registration = false;
		foreach ( self::get_enabled_taxonomies() as $taxonomy ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				$needed_registration = true;
				break;
			}
		}

		if ( $needed_registration ) {
			self::register_all();
		}

		// 2. Системные термы (uncategorized — ядро).
		self::ensure_system_terms();

		// 3. Сигнал владельцам системных папок-надстроек (Trash и т.п.) дособрать свои термы —
		//    source-agnostic: FolderRepository не знает про Trash, модуль сам подписан на хук.
		do_action( 'plathix/taxonomy/ensure_system_terms' );

		self::$ready = true;

		if ( $lazy_recovery && $needed_registration ) {
			// Наблюдаемость (Слой 3): init был оборван сторонним плагином — фиксируем след,
			// чтобы админ видел причину, а не чинил вслепую. Одна запись на процесс ($ready).
			\Plathix\Infrastructure\Logger::warning(
				'system_terms_recovered_lazily',
				[ 'reason' => 'init_hook_did_not_run', 'hint' => 'third-party plugin likely fataled on init before Plathix' ]
			);
			update_option( 'plathix_boot_recovered_lazily', 1, false );
		}
	}

	/** Сброс guard-флага (для тестов и после инвалидации системных термов). */
	public static function reset_ready_flag(): void {
		self::$ready = false;
	}

	public static function register_all(): void {
		foreach ( self::get_enabled_post_types() as $post_type ) {
			$taxonomy = self::taxonomy_for_post_type($post_type);
			if ( ! self::is_valid_taxonomy_slug($taxonomy) ) {
				continue;
			}

			register_taxonomy(
				$taxonomy,
				[ $post_type ],
				[
					'labels' => [
						'name' => __('Folders', 'plathix'),
						'singular_name' => __('Folder', 'plathix'),
					],
					'public' => false,
					'show_ui' => false,
					'show_in_menu' => false,
					'show_in_nav_menus' => false,
					'show_tagcloud' => false,
					'hierarchical' => true,
					'rewrite' => false,
					'query_var' => false,
					'show_in_rest' => false,
				]
			);
		}
	}

	public static function ensure_system_terms(): void {
		foreach ( self::get_enabled_taxonomies() as $taxonomy ) {
			FolderRepository::ensure_system_terms($taxonomy);
		}
	}

	/**
	 * @return array<string>
	 *
	 * Сигнатура сохранена: метод публичный, его зовут register_all()/get_enabled_taxonomies()
	 * здесь и PRO FolderMetaBox (обновляется в PRO-ветке пакета).
	 */
	public static function get_enabled_post_types(): array {
		// CTAN-103 ([internal]): Free attachment-native — понятие «список
		// типов» из Free удалено; таксономии других типов регистрирует ТОЛЬКО PRO
		// (ProTaxonomyRegistrar), self-heal чужих — событие plathix/taxonomy/ensure_missing.
		return [ 'attachment' ];
	}

	/** @return array<int, string> */
	public static function get_enabled_taxonomies(): array {
		return array_values(
			array_filter(
				array_map([ self::class, 'taxonomy_for_post_type' ], self::get_enabled_post_types()),
				[ self::class, 'is_valid_taxonomy_slug' ]
			)
		);
	}

	public static function taxonomy_for_post_type(string $post_type): string {
		return TaxonomyResolver::fromPostType(sanitize_key($post_type));
	}

	public static function post_type_for_taxonomy(string $taxonomy): string {
		$taxonomy = sanitize_key($taxonomy);
		if ( PLATHIX_TAXONOMY === $taxonomy ) {
			return 'attachment';
		}

		if ( str_starts_with($taxonomy, PLATHIX_TAX_PREFIX) ) {
			return sanitize_key(substr($taxonomy, strlen(PLATHIX_TAX_PREFIX)));
		}

		return 'attachment';
	}

	private static function is_valid_taxonomy_slug(string $taxonomy): bool {
		if ( strlen($taxonomy) <= 32 ) {
			return true;
		}

		if ( defined('WP_DEBUG') && WP_DEBUG ) {
			// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.PHP.DevelopmentFunctions.error_log_trigger_error -- trigger_error is a debug log gated behind WP_DEBUG, not user-facing output and not shipped debug code
			trigger_error(
				sprintf(
					'plathix: taxonomy slug "%s" (%d chars) exceeds WP limit of 32 and will be skipped at runtime.',
					$taxonomy,
					strlen($taxonomy)
				),
				E_USER_WARNING
			);
			// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.PHP.DevelopmentFunctions.error_log_trigger_error
		}

		return false;
	}
}
