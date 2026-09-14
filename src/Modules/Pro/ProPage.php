<?php

declare(strict_types=1);

namespace Plathix\Modules\Pro;

use Plathix\Core\AdminLayout;
use Plathix\Admin\ExternalLink;
use Plathix\Edition;
use Plathix\User\AccessResolver;

class ProPage
{
	public const PAGE_SLUG = 'plathix-pro';

	private ?CommerceData $commerceData = null;

	/**
	 * @return array<int, array{key:string,name:string,price:string,sites:string,most_popular:bool}>
	 */
	private function pricing(): array {
		$commerce = $this->commerce();
		$plans    = [];

		foreach ( $commerce->plans() as $plan ) {
			/* translators: %d — number of sites included in the plan. */
			$sites = sprintf( _n( '%d site', '%d sites', $plan['sitesCount'], 'plathix' ), $plan['sitesCount'] );

			$plans[] = [
				'key'          => $plan['key'],
				'name'         => sprintf( '%s · %s', $plan['line'], $sites ),
				'price'        => $commerce->currency() . $plan['price'],
				'sites'        => $sites,
				'most_popular' => $plan['mostPopular'],
			];
		}

		return $plans;
	}

	private function startingPrice(): string {
		return $this->commerce()->currency() . $this->commerce()->startingPrice();
	}

	private function commerce(): CommerceData {
		return $this->commerceData ??= new CommerceData();
	}

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'addPage' ], 20 );
		add_filter( 'plathix/admin/menu_pages', static function (array $pages): array {
			$isPro = Edition::isPro();
			$pages[] = [
				'slug'            => self::PAGE_SLUG,
				'label'           => $isPro ? __( 'License', 'plathix' ) : __( 'Upgrade', 'plathix' ),
				'isPlathixPage' => true,
				'section'         => 'main',
				'order'           => 60,
				'is_ui_page'      => true,
				'isPro'          => true,
				'badge'           => $isPro ? null : 'PRO',
				'icon'            => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>',
			];
			return $pages;
		} );
	}

	public function addPage(): void {
		$isPro     = Edition::isPro();
		$page_title = $isPro ? __( 'Plathix License', 'plathix' ) : __( 'Plathix PRO', 'plathix' );
		$menu_title = $isPro
			? __( 'License', 'plathix' )
			: '<span class="plathix-submenu__pro">PRO</span>';
		add_submenu_page(
			(string) apply_filters( 'plathix/admin/rootSlug', 'plathix' ),
			$page_title,
			$menu_title,
			'manage_options',
			self::PAGE_SLUG,
			[ $this, 'render' ]
		);
	}

	public function render(): void {
		AdminLayout::renderPage( self::PAGE_SLUG, function (): void {
			if ( ! AccessResolver::currentUserIsFullAdmin() ) {
				wp_die( esc_html__( 'You do not have sufficient permissions.', 'plathix' ) );
			}
			?>
			<div class="plathix-page">
				<?php
				if ( Edition::isPro() ) {
					do_action( 'plathix/pro_page/render_pro' );
				} else {
					$this->renderFreeVersion();
				}
				?>
			</div>
			<?php
		} );
	}

	private function renderFreeVersion(): void {
		$this->renderHero();
		$this->renderPlans();
		?>
		<div id="plathix-pro-compare"></div>
		<?php
		$this->renderFeatureTable();
		$this->renderCompareLink();
		$this->renderFaq();
		$this->renderTrustStrip();
	}

	private function renderCompareLink(): void {
		?>
		<p class="plathix-pro-compare-link">
			<a href="<?php echo esc_url( ExternalLink::destination( '/pricing/' ) ); ?>" target="_blank" rel="noreferrer noopener" class="plathix-pro-compare-link__cta">
				<?php esc_html_e( 'See the full feature comparison →', 'plathix' ); ?>
			</a>
		</p>
		<?php
	}

	private function renderTrustStrip(): void {
		?>
		<div class="plathix-card plathix-pro-trust-strip">
			<div class="plathix-card__body plathix-pro-trust-strip__body">
				<span>✓ <?php
					/* translators: %d — refund window in days. */
					printf( esc_html__( '%d-day money-back guarantee', 'plathix' ), (int) $this->commerce()->refundDays() );
				?></span>
				<span>✓ <?php esc_html_e( 'Secure checkout', 'plathix' ); ?></span>
				<span>✓ <?php esc_html_e( 'Annual subscription — cancel anytime', 'plathix' ); ?></span>
			</div>
		</div>
		<?php
	}

	private function renderFaq(): void {
		$faqs = [
			[
				'q' => __( 'Can I upgrade from Free to PRO at any time?', 'plathix' ),
				'a' => __( 'Yes, upgrade is instant. Your existing folders and settings are preserved.', 'plathix' ),
			],
			[
				'q' => __( 'What happens to my folders if I cancel?', 'plathix' ),
				'a' => __( 'Your folders remain in the database. PRO-only features (access control, gallery, audit log, WP-CLI, etc.) are deactivated but your structure stays intact.', 'plathix' ),
			],
			[
				'q' => __( 'Is it a one-time payment or subscription?', 'plathix' ),
				'a' => __( 'Annual subscription. You can cancel anytime — no long-term commitment.', 'plathix' ),
			],
			[
				'q' => __( 'Do you offer refunds?', 'plathix' ),
				/* translators: 1: refund window in days, 2: same value repeated. */
				'a' => sprintf( __( '%1$d-day money-back guarantee, no questions asked. Contact support within %2$d days of purchase.', 'plathix' ), $this->commerce()->refundDays(), $this->commerce()->refundDays() ),
			],
		];
		?>
		<div class="plathix-card">
			<div class="plathix-card__head">
				<span class="plathix-card__title"><?php esc_html_e( 'FAQ', 'plathix' ); ?></span>
			</div>
			<div class="plathix-card__body plathix-pro-faq">
				<?php foreach ( $faqs as $faq ) : ?>
				<details class="plathix-pro-faq__item">
					<summary class="plathix-pro-faq__question">
						<?php echo esc_html( $faq['q'] ); ?>
						<span class="plathix-pro-faq__toggle">+</span>
					</summary>
					<p class="plathix-pro-faq__answer">
						<?php echo esc_html( $faq['a'] ); ?>
					</p>
				</details>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	private function renderHero(): void {
		?>
		<div class="plathix-pro-hero">
			<div class="plathix-pro-hero__badge">✦ <?php esc_html_e( 'Plathix PRO', 'plathix' ); ?></div>
			<h1 class="plathix-pro-hero__title">
				<?php esc_html_e( 'Your media library,', 'plathix' ); ?><br>
				<?php esc_html_e( 'finally under control', 'plathix' ); ?>
			</h1>
			<p class="plathix-pro-hero__sub">
				<?php esc_html_e( 'Free gives you folders. PRO adds per-role access control, a gallery builder, bulk ZIP downloads, an audit log and WP-CLI — for sites and teams that need more than organization.', 'plathix' ); ?>
			</p>
			<div class="plathix-pro-hero__actions">
				<a href="<?php echo esc_url( ExternalLink::destination( '/pro/' ) ); ?>" target="_blank" rel="noreferrer noopener"
				   class="plathix-btn plathix-btn--pro plathix-btn--lg">
					★ <?php
						/* translators: %s — starting price from the pricing map, already formatted with the currency symbol. */
						printf( esc_html__( 'Upgrade to PRO — from %s', 'plathix' ), esc_html( $this->startingPrice() ) );
					?>
				</a>
				<a href="#plathix-pro-compare"
				   class="plathix-btn plathix-btn--lg plathix-pro-hero__btn--ghost">
					<?php esc_html_e( 'See what\'s included ↓', 'plathix' ); ?>
				</a>
			</div>
			<div class="plathix-pro-hero__checks">
				<span class="plathix-pro-hero__check">
					<span class="plathix-pro-hero__check-mark">✓</span>
					<?php esc_html_e( 'Per-role folder access &amp; upload permissions', 'plathix' ); ?>
				</span>
				<span class="plathix-pro-hero__check">
					<span class="plathix-pro-hero__check-mark">✓</span>
					<?php esc_html_e( 'Gallery shortcode &amp; bulk ZIP downloads', 'plathix' ); ?>
				</span>
				<span class="plathix-pro-hero__check">
					<span class="plathix-pro-hero__check-mark">✓</span>
					<?php esc_html_e( 'Audit log &amp; WP-CLI commands', 'plathix' ); ?>
				</span>
				<span class="plathix-pro-hero__check">
					<span class="plathix-pro-hero__check-mark">✓</span>
					<?php
						/* translators: %d — refund window in days. */
						printf( esc_html__( '%d-day money-back guarantee', 'plathix' ), (int) $this->commerce()->refundDays() );
					?>
				</span>
			</div>
		</div>
		<?php
	}

	/**
	 * @return void
	 */
	private function renderFeatureTable(): void {
		$sections = self::featureSections();
		?>
		<div class="plathix-card">
			<div class="plathix-card__head">
				<span class="plathix-card__title"><?php esc_html_e( 'Free vs PRO', 'plathix' ); ?></span>
			</div>
			<div class="plathix-table-wrap plathix-pro-compare-wrap">
				<table class="plathix-table plathix-pro-compare">
					<thead>
						<tr>
							<th class="plathix-pro-compare__col--feature"><?php esc_html_e( 'Feature', 'plathix' ); ?></th>
							<th class="plathix-pro-compare__col--free"><?php esc_html_e( 'Free', 'plathix' ); ?></th>
							<th class="plathix-pro-compare__col--pro"><?php esc_html_e( 'PRO', 'plathix' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $sections as $section_title => $rows ) : ?>
							<tr class="plathix-pro-compare__section">
								<td colspan="3"><?php echo esc_html( $section_title ); ?></td>
							</tr>
							<?php foreach ( $rows as [ $feature, $free, $pro ] ) : ?>
								<tr<?php echo ( false === $free ) ? ' class="plathix-pro-compare__row--highlight"' : ''; ?>>
									<td><?php echo esc_html( $feature ); ?></td>
									<td class="plathix-pro-compare__cell"><?php echo self::cell( $free ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- cell() returns literal <span> markup; its only dynamic branch wraps the value in esc_html() ?></td>
									<td class="plathix-pro-compare__cell"><?php echo self::cell( $pro ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- cell() returns literal <span> markup; its only dynamic branch wraps the value in esc_html() ?></td>
								</tr>
							<?php endforeach; ?>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}

	/**
	 * @param bool|string $value
	 */
	public static function cell(bool|string $value): string {
		if ( true === $value ) {
			return '<span class="plathix-pro-compare__mark plathix-pro-compare__mark--yes">✓</span>';
		}
		if ( false === $value ) {
			return '<span class="plathix-pro-compare__mark plathix-pro-compare__mark--no">—</span>';
		}
		return '<span class="plathix-pro-compare__mark plathix-pro-compare__mark--text">' . esc_html( $value ) . '</span>';
	}

	/**
	 * @return array<string, array<array{0:string,1:bool|string,2:bool|string}>>
	 */
	public static function featureSections(): array {
		return [
			__( 'Folders', 'plathix' ) => [
				[ __( 'Unlimited folders', 'plathix' ),                     true,               true ],
				[ __( 'Nested sub-folders (unlimited depth)', 'plathix' ),  true,               true ],
				[ __( 'Folder colors', 'plathix' ),                         true,               true ],
				[ __( 'Favorite folders', 'plathix' ),                      true,               true ],
				[ __( 'Ready folder sets for common site types', 'plathix' ), true,             true ],
			],
			__( 'Media tools', 'plathix' ) => [
				[ __( 'Bulk move to folder', 'plathix' ),                   true,               true ],
				[ __( 'Bulk trash / restore', 'plathix' ),                  true,               true ],
				[ __( 'Replace media (keeps attachment ID)', 'plathix' ),   true,               true ],
				[ __( 'SVG upload with sanitization &amp; role controls', 'plathix' ), true,     true ],
				[ __( 'Export / import folder structure', 'plathix' ),      true,               true ],
				[ __( 'Gallery shortcode &amp; layouts', 'plathix' ),       false,              true ],
				[ __( 'Bulk download as ZIP', 'plathix' ),                  false,              true ],
			],
			__( 'Access & roles', 'plathix' ) => [
				[ __( 'Per-role folder access', 'plathix' ),                false,              true ],
				[ __( 'Per-role upload permission', 'plathix' ),            false,              true ],
				[ __( 'REST API service token authentication', 'plathix' ), false,              true ],
			],
			__( 'Developer tools', 'plathix' ) => [
				[ __( 'WP-CLI commands', 'plathix' ),                       false,              true ],
				[ __( 'Audit log', 'plathix' ),                             false,              true ],
			],
			__( 'Support', 'plathix' ) => [
				[ __( 'Community support', 'plathix' ),                     true,               true ],
				[ __( 'Priority email support', 'plathix' ),                false,              true ],
				[ __( 'One-click site migration', 'plathix' ),              false,              true ],
			],
		];
	}

	private function renderPlans(): void {
		$period = __( '/ year', 'plathix' );
		?>
		<div class="plathix-pro-plans">
			<?php foreach ( $this->pricing() as $plan ) : ?>
				<div class="plathix-pro-plan<?php echo $plan['most_popular'] ? ' plathix-pro-plan--featured' : ''; ?>">
					<?php if ( $plan['most_popular'] ) : ?>
						<div class="plathix-pro-plan__badge"><?php esc_html_e( 'Most popular', 'plathix' ); ?></div>
					<?php endif; ?>
					<div class="plathix-pro-plan__name"><?php echo esc_html( $plan['name'] ); ?></div>
					<div class="plathix-pro-plan__price">
						<?php echo esc_html( $plan['price'] ); ?>
						<span class="plathix-pro-plan__period"><?php echo esc_html( $period ); ?></span>
					</div>
					<div class="plathix-pro-plan__sites"><?php echo esc_html( $plan['sites'] ); ?></div>
					<a href="<?php echo esc_url( ExternalLink::destination( '/pro/' ) ); ?>" target="_blank" rel="noreferrer noopener"
					   class="plathix-btn plathix-pro-plan__cta<?php echo $plan['most_popular'] ? ' plathix-btn--primary' : ''; ?>">
						<?php esc_html_e( 'Get started', 'plathix' ); ?>
					</a>
				</div>
			<?php endforeach; ?>
		</div>
		<p class="plathix-pro-plans__disclaimer">
			<?php
				/* translators: %d — refund window in days. */
				printf( esc_html__( '%d-day money-back guarantee. All plans include all PRO features.', 'plathix' ), (int) $this->commerce()->refundDays() );
			?>
		</p>
		<?php
	}
}
