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
$core       = array(
	'type'    => 'core',
	'id'      => 'wordpress',
	'slug'    => 'wordpress',
	'name'    => 'WordPress',
	'author'  => 'WordPress.org',
	'version' => Revertix_Core_Rollback::installed_version(),
	'icon'    => '',
	'active'  => false,
);

// One-line status under the WordPress card.
$core_status = '';
$core_tone   = '';
if ( '' !== Revertix_Core_Rollback::pinned() && Revertix_Core_Rollback::pinned() === $core['version'] ) {
	$core_status = __( 'Automatic updates on hold', 'revertix' );
	$core_tone   = 'warn';
} else {
	$core_updates = get_site_transient( 'update_core' );
	if ( is_object( $core_updates ) && ! empty( $core_updates->updates ) ) {
		$core_newest = '';
		foreach ( (array) $core_updates->updates as $core_offer ) {
			if ( isset( $core_offer->response, $core_offer->current ) && 'upgrade' === $core_offer->response && version_compare( $core_offer->current, $core_newest, '>' ) ) {
				$core_newest = $core_offer->current;
			}
		}
		if ( '' !== $core_newest && version_compare( $core_newest, $core['version'], '>' ) ) {
			/* translators: %s: WordPress version. */
			$core_status = sprintf( __( 'Update available: %s', 'revertix' ), $core_newest );
		} else {
			$core_status = __( 'Up to date', 'revertix' );
		}
	}
}

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
			<?php esc_html_e( 'Roll any plugin, theme or WordPress itself back to any earlier WordPress.org version. Snapshots of premium/custom plugins & themes (which have no public version history) are available in the separate Revertix PRO add-on.', 'revertix' ); ?>
		</p>
	<?php endif; ?>

	<section class="revertix-rb-corebox" id="revertix-rb-corebox" data-version="<?php echo esc_attr( $core['version'] ); ?>">
		<div class="revertix-rb-corebox__head" role="button" tabindex="0" aria-expanded="false" aria-controls="revertix-rb-corebox-body">
			<span class="revertix-rb-corebox__logo dashicons dashicons-wordpress"></span>
			<div class="revertix-rb-corebox__title">
				<h3><?php esc_html_e( 'WordPress', 'revertix' ); ?></h3>
				<div class="revertix-rb-corebox__meta">
					<span class="revertix-rb-corebox__ver"><?php echo esc_html( 'v' . $core['version'] ); ?></span>
					<?php if ( '' !== $core_status ) : ?>
						<span class="revertix-rb-corebox__status<?php echo 'warn' === $core_tone ? ' is-warn' : ''; ?>"><?php echo esc_html( $core_status ); ?></span>
					<?php endif; ?>
				</div>
			</div>
			<span class="revertix-rb-corebox__action">
				<span class="revertix-rb-corebox__label" data-open="<?php esc_attr_e( 'Hide versions', 'revertix' ); ?>" data-closed="<?php esc_attr_e( 'Change version', 'revertix' ); ?>"><?php esc_html_e( 'Change version', 'revertix' ); ?></span>
				<span class="revertix-rb-corebox__chev dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
			</span>
		</div>
		<div class="revertix-rb-corebox__body" id="revertix-rb-corebox-body" hidden></div>
	</section>

	<h3 class="revertix-rb-heading"><?php esc_html_e( 'Plugins & themes', 'revertix' ); ?></h3>

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
