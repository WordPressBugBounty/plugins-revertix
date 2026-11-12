<?php
/**
 * Pricing modal shown when a locked feature is clicked.
 *
 * Three yearly plans (updates included), differing by the number of licensed
 * sites. New purchases link to REVERTIX_BUY_URL; upgrades for customers who
 * already own a plan link to REVERTIX_LICENSES_URL.
 *
 * @package Revertix
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- variables here are local to this template/scope (included within a method), not true globals.

// Display-only pricing data for the separate Revertix PRO add-on. Purely
// marketing copy - nothing in this plugin reads plans or tiers for behaviour.
$plans = array(
	'personal' => array(
		'label' => __( 'Personal', 'revertix' ),
		'price' => 99,
	),
	'business' => array(
		'label' => __( 'Business', 'revertix' ),
		'price' => 199,
	),
	'agency'   => array(
		'label' => __( 'Agency', 'revertix' ),
		'price' => 299,
	),
);

// The visitor's current plan (if any), so we can show upgrade-by-difference.
// revertix_get_plan_tier() is defined by the Pro add-on when it is active.
$current_tier  = ( revertix_is_premium() && function_exists( 'revertix_get_plan_tier' ) ) ? revertix_get_plan_tier() : '';
$current_caps  = ( $current_tier && isset( $plans[ $current_tier ] ) ) ? $plans[ $current_tier ] : array();
$current_price = isset( $current_caps['price'] ) ? (int) $current_caps['price'] : 0;

// Licensed sites per plan (what the key covers).
$sites = array(
	'personal' => __( '1 site', 'revertix' ),
	'business' => __( '3 sites', 'revertix' ),
	'agency'   => __( 'Unlimited sites', 'revertix' ),
);

// Per-plan highlight bullets (presentation only). A leading "Everything in …"
// item (flagged with a leading +) makes the upgrade path clear.
$highlights = array(
	'personal' => array(
		__( '1 licensed site', 'revertix' ),
		__( 'One-click full sync, staging ⇄ live', 'revertix' ),
		__( 'Auto safety backup before pushing to live', 'revertix' ),
		__( 'Scheduled automatic backups: monthly & weekly', 'revertix' ),
		__( 'Snapshots for premium/custom plugins & themes', 'revertix' ),
		__( 'Export download links: set the expiry, a limit & a password', 'revertix' ),
		__( 'Restrict staging access by user & role', 'revertix' ),
		__( 'Custom staging admin-bar colour', 'revertix' ),
		__( 'Email support', 'revertix' ),
	),
	'business' => array(
		__( '+Everything in Personal, plus:', 'revertix' ),
		__( '3 licensed sites', 'revertix' ),
		__( 'Detailed sync: review every file & line, apply only what you pick', 'revertix' ),
		__( 'Scheduled backups: monthly, every 2 weeks, weekly & daily', 'revertix' ),
		__( 'Priority email support', 'revertix' ),
	),
	'agency'   => array(
		__( '+Everything in Business, plus:', 'revertix' ),
		__( 'Unlimited licensed sites', 'revertix' ),
		__( 'Scheduled backups at any frequency, incl. a custom hour or interval', 'revertix' ),
		__( 'Priority email support', 'revertix' ),
	),
);

$featured = 'business';
?>
<div class="revertix-modal" id="revertix-pricing-modal" hidden aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="revertix-pricing-title">
	<div class="revertix-modal__overlay revertix-close-pricing"></div>

	<div class="revertix-modal__dialog" role="document">
		<button type="button" class="revertix-modal__close revertix-close-pricing" aria-label="<?php esc_attr_e( 'Close', 'revertix' ); ?>">
			<span class="dashicons dashicons-no-alt"></span>
		</button>

		<div class="revertix-modal__header">
			<h2 id="revertix-pricing-title"><?php esc_html_e( 'Upgrade to Revertix PRO', 'revertix' ); ?></h2>
			<?php if ( $current_tier ) : ?>
				<p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: current plan name. */
							__( 'You are on the %s plan. Move up and pay only the difference.', 'revertix' ),
							isset( $current_caps['label'] ) ? $current_caps['label'] : ucfirst( $current_tier )
						)
					);
					?>
				</p>
			<?php else : ?>
				<p><?php esc_html_e( 'Yearly plans. Software updates always included.', 'revertix' ); ?></p>
			<?php endif; ?>
		</div>

		<div class="revertix-plans">
			<?php foreach ( $plans as $slug => $plan ) : ?>
				<?php
				$plan_price = (int) $plan['price'];
				$is_current = ( $current_tier === $slug );
				$is_owned   = ( $current_tier && $plan_price <= $current_price && ! $is_current );
				$diff       = $current_tier ? max( 0, $plan_price - $current_price ) : $plan_price;
				?>
				<div class="revertix-plan <?php echo $slug === $featured ? 'is-featured' : ''; ?><?php echo $is_current ? ' is-current' : ''; ?>">
					<?php if ( $is_current ) : ?>
						<span class="revertix-plan__tag revertix-plan__tag--current"><?php esc_html_e( 'Your plan', 'revertix' ); ?></span>
					<?php elseif ( $slug === $featured ) : ?>
						<span class="revertix-plan__tag"><?php esc_html_e( 'Most popular', 'revertix' ); ?></span>
					<?php endif; ?>

					<h3 class="revertix-plan__name"><?php echo esc_html( $plan['label'] ); ?></h3>

					<div class="revertix-plan__sites">
						<span class="dashicons dashicons-admin-site-alt3"></span>
						<?php echo esc_html( isset( $sites[ $slug ] ) ? $sites[ $slug ] : '' ); ?>
					</div>

					<div class="revertix-plan__price">
						<span class="revertix-plan__currency">$</span>
						<span class="revertix-plan__amount"><?php echo esc_html( $plan['price'] ); ?></span>
						<span class="revertix-plan__per"><?php esc_html_e( '/year', 'revertix' ); ?></span>
					</div>
					<p class="revertix-plan__terms"><?php esc_html_e( 'updates included', 'revertix' ); ?></p>

					<ul class="revertix-plan__features">
						<?php
						foreach ( $highlights[ $slug ] as $line ) :
							$is_inherit = ( '' !== $line && '+' === $line[0] );
							$text       = $is_inherit ? ltrim( substr( $line, 1 ) ) : $line;
							?>
							<li class="<?php echo $is_inherit ? 'revertix-plan__inherit' : ''; ?>">
								<span class="dashicons <?php echo $is_inherit ? 'dashicons-arrow-up-alt' : 'dashicons-yes'; ?>"></span>
								<?php echo esc_html( $text ); ?>
							</li>
						<?php endforeach; ?>
					</ul>

					<?php if ( $is_current ) : ?>
						<span class="revertix-btn revertix-plan__cta revertix-plan__cta--current"><?php esc_html_e( 'Your current plan', 'revertix' ); ?></span>
					<?php elseif ( $is_owned ) : ?>
						<span class="revertix-btn revertix-plan__cta revertix-plan__cta--owned"><?php esc_html_e( 'Included in your plan', 'revertix' ); ?></span>
					<?php else : ?>
						<a
							href="<?php echo esc_url( $current_tier ? REVERTIX_LICENSES_URL : REVERTIX_BUY_URL ); ?>"
							class="revertix-btn revertix-btn--upgrade revertix-plan__cta"
							target="_blank"
							rel="noopener noreferrer"
						>
							<?php
							if ( $current_tier ) {
								/* translators: %d: price difference in dollars. */
								printf( esc_html__( 'Upgrade for $%d', 'revertix' ), (int) $diff );
							} else {
								/* translators: %s: plan name. */
								printf( esc_html__( 'Get %s', 'revertix' ), esc_html( $plan['label'] ) );
							}
							?>
						</a>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>

		<p class="revertix-modal__footnote">
			<?php
			if ( $current_tier ) {
				esc_html_e( 'When upgrading you only pay the difference between your plan and the new one. 7-day money-back guarantee applies to first payments only (new customers).', 'revertix' );
			} else {
				esc_html_e( '7-day money-back guarantee, first payment only, for new customers. Pro is a separate add-on that works alongside this free plugin.', 'revertix' );
			}
			?>
		</p>
	</div>
</div>
