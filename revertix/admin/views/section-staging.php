<?php
/**
 * Staging section.
 *
 * Create as many staging environments as needed. The "push" (staging ⇄ live
 * sync) capability is provided by the separate Revertix PRO add-on and is
 * shown here through the feature-preview component.
 *
 * @package Revertix
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- variables here are local to this template/scope (included within a method), not true globals.

$zip_ok      = Revertix_Requirements::has_zip_archive();
$sites       = Revertix_Staging::all();
$max_allowed = Revertix_Staging::max_allowed();
$can_create  = Revertix_Staging::can_create();
$date_fmt    = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
?>
<section class="revertix-card revertix-staging-card">
		<div class="revertix-card__head">
			<h2 class="revertix-card__title"><?php esc_html_e( 'Staging environment', 'revertix' ); ?></h2>
			<span class="revertix-badge">
				<?php
				if ( -1 === $max_allowed ) {
					esc_html_e( 'Unlimited', 'revertix' );
				} else {
					echo esc_html(
						sprintf(
							/* translators: 1: used count, 2: allowed count. */
							__( '%1$d of %2$d used', 'revertix' ),
							count( $sites ),
							$max_allowed
						)
					);
				}
				?>
			</span>
		</div>
		<p class="revertix-card__desc">
			<?php esc_html_e( 'A staging site is a full, separate copy of your live site - its own files, its own database, and its own admin. Test plugins, updates and changes safely: nothing you do on staging ever touches the live site. It opens at its own web address, is hidden from search engines, and you log in with your usual account.', 'revertix' ); ?>
		</p>

		<div class="revertix-staging" id="revertix-staging">
<div class="revertix-staging__create">
<input type="text" id="revertix-staging-name" class="revertix-input" placeholder="<?php esc_attr_e( 'Name, e.g. staging (optional)', 'revertix' ); ?>" <?php disabled( ! $can_create || ! $zip_ok ); ?>>
				<button type="button" class="revertix-btn revertix-btn--primary" id="revertix-staging-start" <?php disabled( ! $can_create || ! $zip_ok ); ?>>
					<span class="dashicons dashicons-admin-page"></span>
					<?php esc_html_e( 'Create staging', 'revertix' ); ?>
				</button>
			</div>
			<p class="revertix-hint">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: example staging URL. */
						__( 'The name becomes the address, e.g. %s. Leave empty for “staging”, then staging1, staging2…', 'revertix' ),
						trailingslashit( home_url( '/staging' ) )
					)
				);
				?>
			</p>

			<div class="revertix-progress" id="revertix-staging-progress" hidden>
				<div class="revertix-progress__bar">
					<div class="revertix-progress__fill" id="revertix-staging-fill" style="width:0%"></div>
				</div>
				<div class="revertix-progress__meta">
					<span class="revertix-progress__label" id="revertix-staging-label"></span>
					<span class="revertix-progress__pct" id="revertix-staging-pct">0%</span>
				</div>
			</div>

			<div class="revertix-staging__list" id="revertix-staging-list">
				<p class="revertix-packages__empty" id="revertix-staging-empty" <?php echo empty( $sites ) ? '' : 'hidden'; ?>>
					<?php esc_html_e( 'No staging sites yet. Create one above.', 'revertix' ); ?>
				</p>

				<?php
				foreach ( $sites as $site ) :
					$admin_url = trailingslashit( $site['url'] ) . 'wp-admin/';
					$ready     = empty( $site['status'] ) || 'ready' === $site['status'];
					?>
					<div class="revertix-package revertix-staging-item<?php echo $ready ? '' : ' is-building'; ?>" data-slug="<?php echo esc_attr( $site['slug'] ); ?>">
						<div class="revertix-staging-item__top">
							<span class="revertix-staging-item__badge"><?php echo $ready ? esc_html__( 'STAGING', 'revertix' ) : esc_html__( 'BUILDING', 'revertix' ); ?></span>
							<div class="revertix-package__info">
								<strong class="revertix-package__name"><?php echo esc_html( $site['name'] ); ?></strong>
								<span class="revertix-package__meta">
									<a href="<?php echo esc_url( $site['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $site['url'] ); ?></a>
									<?php echo esc_html( ' · ' . date_i18n( $date_fmt, (int) $site['created'] ) ); ?>
								</span>
							</div>
							<div class="revertix-package__actions">
								<?php if ( $ready ) : ?>
									<a class="revertix-btn revertix-btn--sm revertix-staging-open" href="<?php echo esc_url( $site['url'] ); ?>" target="_blank" rel="noopener">
										<span class="dashicons dashicons-external"></span>
										<?php esc_html_e( 'Open', 'revertix' ); ?>
									</a>
									<?php if ( revertix_is_premium() ) : ?>
									<button type="button" class="revertix-btn revertix-btn--sm revertix-staging-sync" data-slug="<?php echo esc_attr( $site['slug'] ); ?>">
										<span class="dashicons dashicons-randomize"></span>
										<?php esc_html_e( 'Sync ⇄ Live', 'revertix' ); ?>
									</button>
									<?php else : ?>
									<button type="button" class="revertix-btn revertix-btn--sm revertix-staging-locked" data-revertix-locked title="<?php esc_attr_e( 'Syncing changes between staging and live is available in the separate Revertix PRO add-on.', 'revertix' ); ?>">
										<span class="dashicons dashicons-lock"></span>
										<?php esc_html_e( 'Sync ⇄ Live', 'revertix' ); ?>
										<span class="revertix-upgrade-tag"><?php esc_html_e( 'Pro', 'revertix' ); ?></span>
									</button>
									<?php endif; ?>
									<button type="button" class="revertix-btn revertix-btn--sm revertix-staging-reset">
										<span class="dashicons dashicons-image-rotate"></span>
										<?php esc_html_e( 'Reset', 'revertix' ); ?>
									</button>
								<?php else : ?>
									<span class="revertix-hint"><?php esc_html_e( 'Unfinished - delete and create again.', 'revertix' ); ?></span>
								<?php endif; ?>
								<button type="button" class="revertix-btn revertix-btn--sm revertix-btn--danger revertix-staging-delete">
									<span class="dashicons dashicons-trash"></span>
									<?php esc_html_e( 'Delete', 'revertix' ); ?>
								</button>
							</div>
						</div>

						<dl class="revertix-staging-item__details">
							<div>
								<dt><?php esc_html_e( 'Database', 'revertix' ); ?></dt>
								<dd><?php echo esc_html( ! empty( $site['db_name'] ) ? $site['db_name'] : '-' ); ?></dd>
							</div>
							<div>
								<dt><?php esc_html_e( 'Table prefix', 'revertix' ); ?></dt>
								<dd><?php echo esc_html( ! empty( $site['prefix'] ) ? $site['prefix'] : '-' ); ?></dd>
							</div>
							<div>
								<dt><?php esc_html_e( 'URL', 'revertix' ); ?></dt>
								<dd><a href="<?php echo esc_url( $site['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $site['url'] ); ?></a></dd>
							</div>
							<div>
								<dt><?php esc_html_e( 'Created by', 'revertix' ); ?></dt>
								<dd><?php echo esc_html( ! empty( $site['created_by'] ) ? $site['created_by'] : '-' ); ?></dd>
							</div>
							<div class="revertix-staging-item__path">
								<dt><?php esc_html_e( 'Path', 'revertix' ); ?></dt>
								<dd title="<?php echo esc_attr( ! empty( $site['path'] ) ? $site['path'] : '' ); ?>"><?php echo esc_html( ! empty( $site['path'] ) ? $site['path'] : '-' ); ?></dd>
							</div>
						</dl>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
	</section>

<div class="revertix-grid">
	<?php
	Revertix_Locked_Feature::render(
		array(
			'feature' => 'push',
			'title'   => __( 'Push changes (staging ⇄ live)', 'revertix' ),
			'desc'    => __( 'Push from staging to live or pull live into staging. Choose exactly what to move - files and/or selected tables. A safety backup of the live site is taken automatically before every push to live.', 'revertix' ),
		)
	);
	?>
</div>
