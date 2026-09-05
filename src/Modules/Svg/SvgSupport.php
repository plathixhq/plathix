<?php

declare(strict_types=1);

namespace Plathix\Modules\Svg;

use Plathix\Infrastructure\Features;
use Plathix\Infrastructure\Keys;
use Plathix\Infrastructure\Logger;
use Plathix\Loader;
use Plathix\Svg\Sanitizer\Sanitizer;
use Plathix\Svg\SvgUploadPolicy;
use Plathix\User\AccessResolver;

class SvgSupport
{
	private readonly SvgUploadPolicy $svg_upload_policy;

	public function __construct(
		private readonly Sanitizer $sanitizer = new Sanitizer(),
		private readonly ?Loader $loader = null,
		?SvgUploadPolicy $svg_upload_policy = null
	) {
		// SvgUploadPolicy делит тот же Sanitizer, что инжектирован в модуль — одна точка
		// подмены санитайзера в тестах; markup-политика едина с Replace-flow ([internal]).
		$this->svg_upload_policy = $svg_upload_policy ?? new SvgUploadPolicy( $this->sanitizer );

		if ( $this->loader ) {
			$this->loader->add_filter( 'upload_mimes', $this, 'allow_svg_mime' );
			$this->loader->add_filter( 'wp_check_filetype_and_ext', $this, 'fix_svg_filetype', 10, 4 );
			$this->loader->add_filter( 'wp_handle_upload_prefilter', $this, 'sanitize_svg_upload' );
			$this->loader->add_filter( 'wp_get_attachment_image_src', $this, 'svg_image_src_fallback', 10, 4 );
		}
	}

	public function register(): void {
		if ( $this->loader ) {
			return;
		}
		add_filter( 'upload_mimes', [ $this, 'allow_svg_mime' ] );
		add_filter( 'wp_check_filetype_and_ext', [ $this, 'fix_svg_filetype' ], 10, 4 );
		add_filter( 'wp_handle_upload_prefilter', [ $this, 'sanitize_svg_upload' ] );
		add_filter( 'wp_get_attachment_image_src', [ $this, 'svg_image_src_fallback' ], 10, 4 );
	}

	/**
	 * @param array<string,string> $mimes
	 * @return array<string,string>
	 */
	public function allow_svg_mime(array $mimes): array {
		if ( ! $this->is_enabled() || ! current_user_can( 'upload_files' ) || ! AccessResolver::for_current_user()->can_upload() || ! $this->current_user_allowed() ) {
			return $mimes;
		}

		$mimes['svg'] = 'image/svg+xml';
		unset( $mimes['svgz'] );

		return $mimes;
	}

	/**
	 * @param array<string,mixed> $data
	 * @param array<string,string>|null $mimes
	 * @return array<string,mixed>
	 */
	public function fix_svg_filetype(array $data, string $file, string $filename, ?array $mimes): array {
		if ( ! $this->is_enabled() ) {
			return $data;
		}

		$extension = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
		if ( 'svgz' === $extension ) {
			$data['ext']  = false;
			$data['type'] = false;
			return $data;
		}

		if ( 'svg' !== $extension ) {
			return $data;
		}

		if ( ! $this->is_valid_svg_file( $file ) ) {
			$data['ext']  = false;
			$data['type'] = false;
			return $data;
		}

		$data['ext']  = 'svg';
		$data['type'] = 'image/svg+xml';

		return $data;
	}

	/**
	 * @param array<string,mixed> $file
	 * @return array<string,mixed>
	 */
	public function sanitize_svg_upload(array $file): array {
		if ( ! $this->is_enabled() ) {
			return $file;
		}

		$filename = (string) ( $file['name'] ?? '' );
		$tmp_name = (string) ( $file['tmp_name'] ?? '' );
		$extension = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );

		// Never interfere with non-SVG uploads in the global upload prefilter.
		if ( 'svg' !== $extension && 'svgz' !== $extension ) {
			return $file;
		}

		if ( ! current_user_can( 'upload_files' ) || ! AccessResolver::for_current_user()->can_upload() || ! $this->current_user_allowed() ) {
			$file['error'] = __( 'You are not allowed to upload SVG files.', 'plathix' );
			return $file;
		}

		if ( 'svgz' === $extension ) {
			$file['error'] = __( 'Compressed SVGZ files are not supported.', 'plathix' );
			return $file;
		}

		if ( ! is_readable( $tmp_name ) ) {
			return $file;
		}

		$max_svg_bytes = (int) apply_filters( 'plathix/svg/max_upload_bytes', 2 * 1024 * 1024 ); // 2 MB default
		if ( filesize( $tmp_name ) > $max_svg_bytes ) {
			$file['error'] = __( 'SVG file exceeds the maximum allowed size.', 'plathix' );
			return $file;
		}

		$contents = file_get_contents( $tmp_name ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads the WP-managed upload-prefilter tmp_name (local, size-checked above) to sanitize the SVG; not a remote URL.
		if ( false === $contents ) {
			$file['error'] = __( 'Unable to read SVG file.', 'plathix' );
			return $file;
		}

		// Markup-политика (санитайзинг + safe-mode reject) вынесена в единый core-владелец
		// SvgUploadPolicy ([internal]) — тот же источник, что использует Replace-flow, вместо
		// второй копии правила здесь. Модуль сохраняет свою обвязку: notice-фильтр и
		// rate-limited логирование при reject. Reject-набор теперь строгий (объединённый) —
		// усиление к прежнему (добавились <foreignobject>/<use https:> ветки), см. [internal].
		$sanitized = $this->svg_upload_policy->sanitizeMarkup( $contents, $this->is_safe_mode() );
		if ( is_wp_error( $sanitized ) ) {
			$this->log_sanitize_failure( basename( $filename ), (int) ( $file['size'] ?? 0 ), 'markup_policy_rejection' );
			$file['error'] = (string) apply_filters( 'plathix/svg/blocked_notice', $sanitized->get_error_message(), basename( $filename ) );
			return $file;
		}

		if ( false === file_put_contents( $tmp_name, $sanitized ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writes sanitized markup back over the same WP-managed upload-prefilter tmp_name before WP moves it into uploads; local temp path.
			Logger::error( 'SVG sanitize failed', [ 'file' => basename( $filename ), 'reason' => 'cannot_write_sanitized_content' ] );
			$file['error'] = __( 'Unable to process SVG file.', 'plathix' );
			return $file;
		}

		return $file;
	}

	public function svg_image_src_fallback(mixed $image, mixed $attachment_id, mixed $size, bool $icon): mixed {
		if ( false !== $image ) {
			return $image;
		}

		// WP не гарантирует int в этом аргументе фильтра — Elementor вызывает
		// wp_get_attachment_image_src('', 'full') при пустом логотипе ([internal]).
		$attachment_id = is_numeric( $attachment_id ) ? (int) $attachment_id : 0;
		if ( $attachment_id <= 0 ) {
			return $image;
		}

		$mime = (string) get_post_mime_type( $attachment_id );
		if ( 'image/svg+xml' !== $mime ) {
			return $image;
		}

		$url = wp_get_attachment_url( $attachment_id );
		if ( ! $url ) {
			return $image;
		}

		return [
			(string) $url,
			300,
			150,
			false,
		];
	}

	private function is_enabled(): bool {
		return Features::is_enabled( 'svg' );
	}

	private function is_safe_mode(): bool {
		$default = is_multisite();
		return (bool) get_option( 'plathix_svg_safe_mode', $default );
	}

	private function current_user_allowed(): bool {
		$allowed_roles = $this->allowed_roles();
		$user = wp_get_current_user();
		if ( ! $user || empty( $user->roles ) ) {
			return false;
		}

		foreach ( $user->roles as $role ) {
			if ( in_array( $role, $allowed_roles, true ) ) {
				return true;
			}
		}

		// [internal] ([internal]/#222): service-token (PRO) подменяет $current_user на
		// impersonated admin — per-user override не должен читаться в этом контексте,
		// иначе machine-запрос наследует чужой персональный override.
		if ( apply_filters( 'plathix/infrastructure/service_token_active', false ) ) {
			return false;
		}

		// Individual override from profile with full/upload levels.
		// [internal] ([internal]): Free БОЛЬШЕ НЕ читает мету `plathix_user_access` напрямую.
		// Раньше здесь стоял `get_user_meta(..., 'plathix_user_access', ...)` — Free жёстко знал
		// имя и формат значений PRO-настройки, хотя писать её может только PRO
		// (PlathixPro\Modules\Access\UserProfileOverride). Эта cross-repo связь шла через голую
		// строку в БД и не покрывалась ни одним гейтом (HookRegistry каталогизирует только хуки,
		// PublicContractRegistry — только классы), поэтому смена формата ключа на одной стороне
		// тихо ломала другую при рассинхроне версий Free/PRO — второй экземпляр [internal].
		//
		// Теперь Free объявляет extension point, а имя/формат/blog-скоуп ключа целиком приватны
		// для PRO. Слот задекларирован в HookRegistry::SLOTS и покрыт двусторонним гейтом
		// (HookRegistrySlotCoverageTest во Free, HookRegistrySubscriptionTest в PRO).
		//
		// Дефолт `false` — fail-closed: без подписчика (PRO не установлен / лицензия невалидна)
		// override не действует, остаётся только ветка ролей выше. НЕ через
		// AccessResolver::resolve()->can_upload(): cap-дефолт открыл бы SVG editor'у без роли и
		// без override = регресс безопасности (сохранено от [internal]).
		return (bool) apply_filters( 'plathix/svg/user_override_allows_upload', false, get_current_user_id() );
	}

	private function is_valid_svg_file(string $file): bool {
		if ( '' === $file || ! is_readable( $file ) ) {
			return false;
		}

		$contents = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads the local upload-prefilter tmp_name (is_readable-checked above) to validate SVG markup; not a remote URL.
		if ( false === $contents || '' === trim( $contents ) ) {
			return false;
		}

		return preg_match( '/<svg\b/i', $contents ) === 1;
	}

	/**
	 * @return array<int,string>
	 */
	private function allowed_roles(): array {
		$roles = get_option( 'plathix_svg_support', [ 'administrator', 'editor' ] );
		if ( ! is_array( $roles ) ) {
			return [ 'administrator', 'editor' ];
		}

		// string-callable в array_map резолвится ТОЛЬКО через глобальный namespace (PHP-механика,
		// не WP-специфика). sanitize_key сейчас безусловно глобальный (tests/bootstrap.php), но тот
		// же файл уже условно убирает другие функции из этого списка — closure убирает зависимость
		// от global-vs-namespace резолва целиком ([internal], hardening).
		return array_values( array_filter( array_map( static fn ($role) => sanitize_key( $role ), $roles ) ) );
	}

	private function log_sanitize_failure(string $filename, int $size, string $reason): void {
		$user_id   = get_current_user_id();
		$ip_hash   = md5( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- REMOTE_ADDR is a server variable; md5() output is used only as a cache key
		// Keys::transient сам добавляет blog_id-префикс — имя передаём без него.
		$rate_key  = Keys::transient( 'svg_log_' . $ip_hash . '_' . $user_id );
		$log_count = (int) get_transient( $rate_key );

		if ( $log_count >= 10 ) {
			return;
		}

		Logger::error(
			'SVG sanitize failed',
			[
				'file'   => $filename,
				'reason' => $reason,
			]
		);
		Logger::debug(
			'SVG rejected',
			[
				'file' => $filename,
				'size' => $size,
			]
		);
		set_transient( $rate_key, $log_count + 1, MINUTE_IN_SECONDS );
	}
}
