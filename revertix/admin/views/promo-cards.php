<?php
/**
 * "Support the plugin" promo strip shown at the bottom of the free Revertix
 * dashboard: a review prompt, a support pointer, and a donate ask. Hidden for
 * premium users (the calling template already gates on revertix_is_premium()).
 *
 * @package Revertix
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- variables here are local to this template/scope (included within a method), not true globals.

$revertix_reviews_url = 'https://wordpress.org/support/plugin/revertix/reviews/?filter=5';
$revertix_support_url = 'https://wordpress.org/support/plugin/revertix/';
$revertix_donate_url  = 'https://nitramix.com/donate/';
?>
<section class="revertix-promo" aria-label="<?php esc_attr_e( 'Support Revertix', 'revertix' ); ?>">

	<article class="revertix-promo__card">
		<span class="revertix-promo__icon revertix-promo__icon--star dashicons dashicons-star-filled"></span>
		<h3 class="revertix-promo__title"><?php esc_html_e( 'Help us keep Revertix free &amp; maintained', 'revertix' ); ?></h3>
		<p class="revertix-promo__text">
			<?php esc_html_e( 'Your review means a lot! It helps others discover that Revertix is free, safe and well maintained. Thank you for taking a moment.', 'revertix' ); ?>
		</p>

		<?php
		/**
		 * Premium builds inject an inline star-rating form here (Revertix PRO).
		 * It posts to the Pro review endpoint; the wordpress.org button below
		 * stays as the review for the free plugin.
		 */
		do_action( 'revertix_promo_review_form' );
		?>

		<a class="revertix-btn revertix-btn--upgrade revertix-promo__cta" href="<?php echo esc_url( $revertix_reviews_url ); ?>" target="_blank" rel="noopener">
			<?php esc_html_e( 'Leave a review', 'revertix' ); ?>
		</a>
	</article>

	<article class="revertix-promo__card">
		<span class="revertix-promo__icon revertix-promo__icon--help dashicons dashicons-sos"></span>
		<h3 class="revertix-promo__title"><?php esc_html_e( 'Something not working? We can help', 'revertix' ); ?></h3>
		<p class="revertix-promo__text">
			<?php if ( revertix_is_premium() ) : ?>
				<?php esc_html_e( 'As a Pro customer you get priority help. Use the community forum, or reach us directly for a fast reply.', 'revertix' ); ?>
			<?php else : ?>
				<?php esc_html_e( 'We are here for you. Open a topic in our support forum any time - or unlock direct, priority help with Pro.', 'revertix' ); ?>
			<?php endif; ?>
		</p>

		<div class="revertix-promo__ctas">
			<a class="revertix-btn revertix-btn--ghost revertix-promo__cta" href="<?php echo esc_url( $revertix_support_url ); ?>" target="_blank" rel="noopener">
				<?php esc_html_e( 'Get help now', 'revertix' ); ?>
			</a>

			<?php if ( revertix_is_premium() ) : ?>
				<a class="revertix-btn revertix-btn--upgrade revertix-promo__cta" href="<?php echo esc_url( 'https://nitramix.com/contact' ); ?>" target="_blank" rel="noopener">
					<span class="dashicons dashicons-sos"></span>
					<?php esc_html_e( 'Priority support', 'revertix' ); ?>
				</a>
			<?php else : ?>
				<button type="button" class="revertix-btn revertix-btn--upgrade revertix-promo__cta revertix-open-pricing">
					<span class="dashicons dashicons-lock"></span>
					<?php esc_html_e( 'Priority support', 'revertix' ); ?>
				</button>
			<?php endif; ?>
		</div>
	</article>

	<article class="revertix-promo__card">
		<span class="revertix-promo__icon revertix-promo__icon--heart dashicons dashicons-heart"></span>
		<h3 class="revertix-promo__title"><?php esc_html_e( 'Your support is invaluable 🌟', 'revertix' ); ?></h3>
		<p class="revertix-promo__text">
			<?php esc_html_e( 'Donating to Revertix invests in its future and keeps it reliable for everyone. Every contribution, big or small, helps us keep going.', 'revertix' ); ?>
		</p>
		<a class="revertix-btn revertix-btn--ghost revertix-promo__cta" href="<?php echo esc_url( $revertix_donate_url ); ?>" target="_blank" rel="noopener">
			<?php esc_html_e( 'Donate', 'revertix' ); ?>
		</a>
	</article>

</section>
