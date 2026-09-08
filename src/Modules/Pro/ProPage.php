<?php

declare(strict_types=1);

namespace Plathix\Modules\Pro;

use Plathix\Core\AdminLayout;
use Plathix\Admin\ExternalLink;
use Plathix\Edition;

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
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have sufficient permissions.', 'plathix' ) );
			}
			?>
			<div class="plathix-page">
				<?php

				if ( Edition::isPro() ) {
					$this->renderProVersion();
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
		$this->renderLicenseCard();
	}

	private function renderCompareLink(): void {
		?>
		<p class="plathix-pro-compare-link">
			<a href="<?php echo esc_url( ExternalLink::marketing( '/pricing/', 'pro_full_compare' ) ); ?>" target="_blank" rel="noreferrer noopener" class="plathix-pro-compare-link__cta">
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

	private function renderProVersion(): void {
		$this->renderStatusCard();
		$this->renderLicenseCard();
		$this->renderManifestDisclosure();
		$this->renderUnlocked();
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
				<a href="<?php echo esc_url( ExternalLink::marketing( '/pro/', 'pro_hero_cta' ) ); ?>" target="_blank" rel="noreferrer noopener"
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
		$sections = $this->featureSections();
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
									<td class="plathix-pro-compare__cell"><?php echo $this->cell( $free ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- cell() returns literal <span> markup; its only dynamic branch wraps the value in esc_html() ?></td>
									<td class="plathix-pro-compare__cell"><?php echo $this->cell( $pro ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- cell() returns literal <span> markup; its only dynamic branch wraps the value in esc_html() ?></td>
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

	private function cell(bool|string $value): string {
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

	private function featureSections(): array {
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
					<a href="<?php echo esc_url( ExternalLink::marketing( '/pro/', 'pro_plan_cta' ) ); ?>" target="_blank" rel="noreferrer noopener"
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

	private function renderStatusCard(): void {
		?>
		<div class="plathix-pro-hero plathix-pro-hero--status">
			<div class="plathix-pro-hero__badge">✦ <?php esc_html_e( 'Plathix PRO — active', 'plathix' ); ?></div>
			<h1 class="plathix-pro-hero__title plathix-pro-hero__title--compact">
				<?php esc_html_e( 'You\'re on PRO. Everything is unlocked.', 'plathix' ); ?>
			</h1>
			<p class="plathix-pro-hero__sub">
				<?php esc_html_e( 'Manage your license below. Need help? Our docs and support are a click away.', 'plathix' ); ?>
			</p>
			<?php $this->renderExpiryLine(); ?>
			<div class="plathix-pro-hero__actions">
				<a href="<?php echo esc_url( ExternalLink::marketing( '/docs/', 'pro_status_docs' ) ); ?>" target="_blank" rel="noreferrer noopener" class="plathix-btn plathix-btn--lg plathix-pro-hero__btn--ghost">
					<?php esc_html_e( 'Documentation', 'plathix' ); ?>
				</a>
				<a href="<?php echo esc_url( ExternalLink::marketing( '/support/', 'pro_status_support' ) ); ?>" target="_blank" rel="noreferrer noopener" class="plathix-btn plathix-btn--lg plathix-pro-hero__btn--ghost">
					<?php esc_html_e( 'Priority support', 'plathix' ); ?>
				</a>
			</div>
		</div>
		<?php
	}

	private function renderExpiryLine(): void {
		$expiry = (string) get_option( Edition::EXPIRES_OPTION, '' );
		$days   = LicensePolicy::daysUntilExpiry( $expiry );
		$state  = LicensePolicy::expiryState( $days );

		if ( 'lifetime' === $state ) {
			return;

		}

		$expiry_ts = strtotime( $expiry );
		$date_str  = false !== $expiry_ts ? (string) wp_date( (string) get_option( 'date_format', 'Y-m-d' ), $expiry_ts ) : '';
		$renew_url = ExternalLink::marketing( '/pro/', 'pro_renew' );

		echo '<p class="plathix-pro-hero__sub plathix-pro-hero__sub--expiry">';

		if ( 'ok' === $state ) {
			printf(
				/* translators: %s — subscription expiry date. */
				esc_html__( 'Subscription active until %s.', 'plathix' ),
				esc_html( $date_str )
			);
		} elseif ( 'soon' === $state ) {
			printf(
				/* translators: 1: days left, 2: renew link (opening <a>), 3: closing </a>. */
				esc_html__( 'Subscription expires in %1$d days. %2$sRenew%3$s to keep PRO active.', 'plathix' ),
				(int) $days,
				'<a href="' . esc_url( $renew_url ) . '" target="_blank" rel="noreferrer noopener" class="plathix-pro-hero__renew-link">',
				'</a>'
			);
		} else { // expired
			printf(
				/* translators: 1: expiry date, 2: renew link (opening <a>), 3: closing </a>. */
				esc_html__( 'Subscription expired on %1$s. %2$sRenew%3$s to restore PRO.', 'plathix' ),
				esc_html( $date_str ),
				'<a href="' . esc_url( $renew_url ) . '" target="_blank" rel="noreferrer noopener" class="plathix-pro-hero__renew-link">',
				'</a>'
			);
		}

		echo '</p>';
	}

	private function renderUnlocked(): void {
		$sections = $this->featureSections();
		?>
		<div class="plathix-card">
			<div class="plathix-card__head">
				<span class="plathix-card__title"><?php esc_html_e( 'What you unlocked with PRO', 'plathix' ); ?></span>
			</div>
			<div class="plathix-card__body plathix-pro-unlocked">
				<?php foreach ( $sections as $section_title => $rows ) : ?>
					<?php
					$pro_rows = array_values( array_filter( $rows, static fn(array $row): bool => true === $row[2] && true !== $row[1] ) );
					if ( empty( $pro_rows ) ) {
						continue;
					}
					?>
					<div>
						<div class="plathix-pro-unlocked__section-title"><?php echo esc_html( (string) $section_title ); ?></div>
						<ul class="plathix-pro-unlocked__list">
							<?php foreach ( $pro_rows as $row ) : ?>
							<li class="plathix-pro-unlocked__item">
								<span class="plathix-pro-unlocked__item-mark">✓</span>
								<?php echo esc_html( (string) $row[0] ); ?>
							</li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endforeach; ?>
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

	private function renderLicenseCard(): void {
		$license_key    = get_option( Edition::KEY_OPTION, '' );
		$license_status = get_option( Edition::STATUS_OPTION, '' );
		$notice         = sanitize_key( (string) ( $_GET['plathix_license'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice flag from the license redirect; sanitize_key()'d and only used to pick which admin notice to render, no form processing and no DB write
		?>
		<div class="plathix-card">
			<div class="plathix-card__head">
				<span class="plathix-card__title"><?php esc_html_e( 'License Key', 'plathix' ); ?></span>
				<?php if ( \Plathix\Edition::STATUS_ACTIVE === $license_status ) : ?>
					<span class="plathix-badge plathix-badge--ok"><?php esc_html_e( 'Active', 'plathix' ); ?></span>
				<?php elseif ( \Plathix\Edition::STATUS_STALE === $license_status ) : ?>
					<?php
?>
					<?php
?>
					<span class="plathix-badge plathix-badge--warn"><?php esc_html_e( 'Awaiting re-check', 'plathix' ); ?></span>
				<?php elseif ( $license_key ) : ?>
					<span class="plathix-badge plathix-badge--err"><?php esc_html_e( 'Invalid', 'plathix' ); ?></span>
				<?php endif; ?>
			</div>
			<div class="plathix-card__body">
				<?php if ( 'activated' === $notice ) : ?>
					<div class="plathix-notice plathix-notice--ok plathix-pro-license__notice">
						<?php esc_html_e( 'License activated successfully. Enjoy Plathix PRO!', 'plathix' ); ?>
					</div>
				<?php elseif ( 'deactivated' === $notice ) : ?>
					<div class="plathix-notice plathix-notice--warn plathix-pro-license__notice">
						<?php esc_html_e( 'License deactivated.', 'plathix' ); ?>
					</div>
				<?php elseif ( 'error' === $notice ) : ?>
					<div class="plathix-notice plathix-notice--err plathix-pro-license__notice">
						<?php esc_html_e( 'License key not found or could not be verified. Please check and try again.', 'plathix' ); ?>
					</div>
				<?php endif; ?>

				<p class="plathix-field__desc plathix-pro-license__desc">
					<?php esc_html_e( 'Enter your license key to activate PRO features on this site.', 'plathix' ); ?>
				</p>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="plathix-pro-license__form">
					<?php wp_nonce_field( 'plathix_activate_license' ); ?>
					<input type="hidden" name="action" value="plathix_activate_license">
					<input type="text"
						   name="plathix_license_key"
						   class="plathix-input plathix-pro-license__input"
						   placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"
						   value="<?php echo esc_attr( (string) $license_key ); ?>"
						   autocomplete="off"
						   spellcheck="false">
					<button type="submit" class="plathix-btn plathix-btn--primary">
						<?php echo $license_key ? esc_html__( 'Reactivate', 'plathix' ) : esc_html__( 'Activate', 'plathix' ); ?>
					</button>
				</form>
				<?php if ( $license_key ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="plathix-pro-license__deactivate-form">
						<?php wp_nonce_field( 'plathix_deactivate_license' ); ?>
						<input type="hidden" name="action" value="plathix_deactivate_license">
						<button type="submit" class="plathix-btn plathix-btn--danger">
							<?php esc_html_e( 'Deactivate', 'plathix' ); ?>
						</button>
					</form>
				<?php endif; ?>

				<?php

				$last_check = (int) get_option( Edition::LAST_CHECK_OPTION, 0 );
				?>
				<?php if ( $license_key && $last_check > 0 ) : ?>
					<p class="plathix-pro-license__meta">
						<?php
						printf(
							/* translators: %s: human-readable time difference, e.g. "2 days". */
							esc_html__( 'Last confirmed by the license server: %s ago.', 'plathix' ),
							esc_html( human_time_diff( $last_check, time() ) )
						);
						?>
					</p>
				<?php endif; ?>

				<p class="plathix-pro-license__meta">
					<?php
					echo wp_kses(
						__( 'Your key is in the purchase confirmation email you received after buying Plathix PRO. It looks like <code>xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx</code>.', 'plathix' ),
						[ 'code' => [] ]
					);
					?>
				</p>
			</div>
		</div>
		<?php
	}

	private function renderManifestDisclosure(): void {
		?>
		<p class="plathix-pro-license__meta">
			<?php esc_html_e( 'License checks run over a standard HTTPS connection to our license server; the response is not cryptographically signed, so this check protects against typos and expired keys, not against a compromised network path.', 'plathix' ); ?>
		</p>
		<?php
	}
}
