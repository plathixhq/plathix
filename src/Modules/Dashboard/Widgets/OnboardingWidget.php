<?php

declare(strict_types=1);

namespace Plathix\Modules\Dashboard\Widgets;

/**
 * Setup-блок «Finish setup» на дашборде.
 *
 * Владелец — Dashboard ([internal]): виджет переехал сюда из Modules\Onboarding вместе с
 * подпиской на точку расширения `plathix/dashboard/render_onboarding`. Это CROSS-PACKAGE
 * OVERRIDE решения [internal] (там виджет оставался в Onboarding): онбординг-визард
 * уезжает в PRO целиком, а «Finish setup» — Free dashboard-фича, её данные (onboarding_cards/
 * show_onboarding) собирает HomeDashboardData, поэтому и рендерер принадлежит Dashboard.
 */
class OnboardingWidget
{
	/**
	 * @param array<string, mixed> $data
	 */
	public function render(array $data): void {
		if ( ! $data['show_onboarding'] ) {
			return;
		}

		$cards = $data['onboarding_cards'];
		?>
		<div class="plathix-onboarding" id="plathix-onboarding-block">
			<div class="plathix-onboarding__header">
				<h2 class="plathix-onboarding__title">
					<?php
					// Без фейк-прогресса «0 of N» ([internal]/B1): счётчик $done был захардкожен
					// в 0 и никогда не рос — реального трекинга шагов нет, а «0 из 1» создавал
					// ложное ощущение недонастроенного сайта (медиатека уже работает). Оставляем
					// спокойный опциональный заголовок без прогресс-цифр.
					esc_html_e( 'Set up Plathix', 'plathix' );
					?>
				</h2>
			</div>
			<div class="plathix-onboarding__cards">
				<?php foreach ( $cards as $card ) : ?>
					<div class="plathix-onboarding__card" data-card-id="<?php echo esc_attr( $card['id'] ); ?>">
						<div class="plathix-onboarding__card-header">
							<p class="plathix-onboarding__card-title"><?php echo esc_html( $card['title'] ); ?></p>
							<button
								type="button"
								class="plathix-onboarding__dismiss plathix-onboarding__card-dismiss"
								aria-label="<?php esc_attr_e( 'Dismiss this card', 'plathix' ); ?>"
								title="<?php esc_attr_e( 'Dismiss', 'plathix' ); ?>"
							>
								<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
									<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
								</svg>
							</button>
						</div>
						<p class="plathix-onboarding__card-desc"><?php echo esc_html( $card['desc'] ); ?></p>
						<a href="<?php echo esc_url( $card['url'] ); ?>" class="plathix-onboarding__card-link">
							<?php esc_html_e( 'Configure →', 'plathix' ); ?>
						</a>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}
}
