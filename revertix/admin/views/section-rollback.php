<?php
/**
 * Rollback tab: roll plugins and themes back to an earlier version.
 *
 * Master-detail layout: the installed plugins & themes load on the left;
 * clicking one shows its preview + every available wordpress.org version on
 * the right (plus snapshots, when the separate Revertix PRO add-on provides
 * them). Versions are fetched lazily per item, so the page renders instantly.
 *
 * @package Revertix
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- variables here are local to this template/scope (included within a method), not true globals.

$is_premium = revertix_is_premium();
$plugins    = Revertix_Rollback::plugins();
$themes     = Revertix_Rollback::themes();

/**
 * Render one list row.
 *
 * @param array  $item        Normalised rollback item.
 * @param string $fallback_ic Dashicon class for the placeholder.
 */
$render_row = static function ( $item, $fallback_ic ) {
	?>
	<button type="button" class="revertix-rb-row"
		data-type="<?php echo esc_attr( $item['type'] ); ?>"
		data-id="<?php echo esc_attr( $item['id'] ); ?>"
		data-slug="<?php echo esc_attr( $item['slug'] ); ?>"
		data-version="<?php echo esc_attr( $item['version'] ); ?>"
		data-name="<?php echo esc_attr( $item['name'] ); ?>"
		data-author="<?php echo esc_attr( $item['author'] ); ?>"
		data-active="<?php echo ! empty( $item['active'] ) ? '1' : '0'; ?>"
		data-icon="<?php echo esc_url( $item['icon'] ); ?>"
		data-fallback="<?php echo esc_attr( $fallback_ic ); ?>">

		<span class="revertix-rb-row__media<?php echo empty( $item['icon'] ) ? ' is-broken' : ''; ?>">
			<?php if ( ! empty( $item['icon'] ) ) : ?>
				<img class="revertix-rb-row__icon" src="<?php echo esc_url( $item['icon'] ); ?>" alt="" loading="lazy" />
			<?php endif; ?>
			<span class="revertix-rb-row__fallback dashicons <?php echo esc_attr( $fallback_ic ); ?>"></span>
		</span>

		<span class="revertix-rb-row__body">
			<span class="revertix-rb-row__name"><?php echo esc_html( $item['name'] ); ?></span>
			<span class="revertix-rb-row__meta">
				<span class="revertix-rb-row__ver"><?php echo esc_html( 'v' . $item['version'] ); ?></span>
				<?php if ( ! empty( $item['active'] ) ) : ?>
					<span class="revertix-rb-row__active"><?php esc_html_e( 'Active', 'revertix' ); ?></span>
				<?php endif; ?>
			</span>
		</span>

		<span class="dashicons dashicons-arrow-right-alt2 revertix-rb-row__chev"></span>
	</button>
	<?php
};
?>
<div class="revertix-rollback" id="revertix-rollback" data-premium="<?php echo $is_premium ? '1' : '0'; ?>">

	<?php if ( ! $is_premium ) : ?>
		<p class="revertix-hint">
			<span class="dashicons dashicons-info"></span>
			<?php esc_html_e( 'Roll any plugin or theme back to any earlier WordPress.org version. Snapshots of premium/custom plugins & themes (which have no public version history) are available in the separate Revertix PRO add-on.', 'revertix' ); ?>
		</p>
	<?php endif; ?>

	<div class="revertix-rb-split">

		<aside class="revertix-rb-list">
			<div class="revertix-rb-tabs" role="tablist">
				<button type="button" class="revertix-rb-tab is-active" data-group="plugins">
					<span class="dashicons dashicons-admin-plugins"></span>
					<?php esc_html_e( 'Plugins', 'revertix' ); ?>
					<span class="revertix-badge"><?php echo esc_html( (string) count( $plugins ) ); ?></span>
				</button>
				<button type="button" class="revertix-rb-tab" data-group="themes">
					<span class="dashicons dashicons-admin-appearance"></span>
					<?php esc_html_e( 'Themes', 'revertix' ); ?>
					<span class="revertix-badge"><?php echo esc_html( (string) count( $themes ) ); ?></span>
				</button>
			</div>

			<label class="revertix-rb-search">
				<span class="dashicons dashicons-search"></span>
				<input type="search" id="revertix-rb-filter" placeholder="<?php esc_attr_e( 'Search…', 'revertix' ); ?>" />
			</label>

			<div class="revertix-rb-list__scroll">
				<div class="revertix-rb-list__group" data-group="plugins">
					<?php foreach ( $plugins as $item ) { $render_row( $item, 'dashicons-admin-plugins' ); } ?>
				</div>

				<div class="revertix-rb-list__group" data-group="themes" hidden>
					<?php foreach ( $themes as $item ) { $render_row( $item, 'dashicons-admin-appearance' ); } ?>
				</div>
			</div>
		</aside>

		<section class="revertix-rb-detail" id="revertix-rb-detail">
			<div class="revertix-rb-detail__empty">
				<span class="dashicons dashicons-image-rotate"></span>
				<p><?php esc_html_e( 'Select a plugin or theme on the left to see its versions.', 'revertix' ); ?></p>
			</div>
		</section>

	</div>
</div>
