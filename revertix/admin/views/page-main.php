<?php
/**
 * Main dashboard wrapper with the dark panel and tab navigation.
 *
 * Expects: $tabs (array slug=>label), $active (string slug).
 *
 * @package Revertix
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- variables here are local to this template/scope (included within a method), not true globals.

/** @var array<string,string> $tabs */
/** @var string $active */
?>
<div class="wrap revertix-wrap">

	<?php
	/*
	 * WordPress moves every admin notice after the page's first h1/h2 (or the
	 * .wp-header-end marker) via common.js. Without this marker the first
	 * heading is a card title deep inside the app panel, so notices from other
	 * plugins/themes land mid-layout and break it. The marker pins them here,
	 * above the app. The screen-reader h1 also gives the page a proper title.
	 */
	?>
	<h1 class="screen-reader-text"><?php esc_html_e( 'Revertix', 'revertix' ); ?></h1>
	<hr class="wp-header-end">

	<div class="revertix-app" data-active-tab="<?php echo esc_attr( $active ); ?>">

		<header class="revertix-topbar">
			<div class="revertix-brand">
				<img class="revertix-brand__logo" src="<?php echo esc_url( REVERTIX_PLUGIN_URL . 'assets/img/revertix-logo.png' ); ?>" alt="" width="42" height="42" />
				<span class="revertix-brand__name"><?php esc_html_e( 'Revertix', 'revertix' ); ?></span>
				<?php if ( revertix_is_premium() ) : ?>
					<span class="revertix-badge revertix-badge--pro"><?php esc_html_e( 'PRO', 'revertix' ); ?></span>
				<?php endif; ?>
			</div>

			<?php if ( ! revertix_is_premium() ) : ?>
				<button type="button" class="revertix-btn revertix-btn--upgrade revertix-open-pricing">
					<span class="dashicons dashicons-lock"></span>
					<?php esc_html_e( 'Upgrade to PRO', 'revertix' ); ?>
				</button>
			<?php endif; ?>
		</header>

		<?php Revertix_Requirements::maybe_admin_notice(); ?>

		<nav class="revertix-tabs" role="tablist">
			<?php foreach ( $tabs as $slug => $label ) : ?>
				<a
					href="<?php echo esc_url( Revertix_Admin::tab_url( $slug ) ); ?>"
					class="revertix-tab <?php echo $slug === $active ? 'is-active' : ''; ?>"
					role="tab"
					aria-selected="<?php echo $slug === $active ? 'true' : 'false'; ?>"
				><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</nav>

		<main class="revertix-content">
			<?php
			$section = REVERTIX_PLUGIN_DIR . 'admin/views/section-' . $active . '.php';
			if ( file_exists( $section ) ) {
				include $section;
			}

			// Support-the-plugin promo strip. Shown for everyone; the cards adapt
			// for premium (an inline Pro review form + a direct contact button).
			include REVERTIX_PLUGIN_DIR . 'admin/views/promo-cards.php';
			?>
		</main>
	</div>

	<?php include REVERTIX_PLUGIN_DIR . 'admin/views/modal-pricing.php'; ?>
</div>
