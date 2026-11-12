<?php
/**
 * Settings tab.
 *
 * One form, saved over AJAX (revertix_save_settings). Settings belonging to
 * the separate Revertix PRO add-on (staging colour/access, snapshot
 * retention) only render when the add-on is active - it is what applies
 * them; without it a one-line pointer says they exist. Staging-auth +
 * bar-colour settings are read on the staging site itself, so they can also
 * be changed from a staging copy's own Settings tab.
 *
 * @package Revertix
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- variables here are local to this template/scope (included within a method), not true globals.

$s          = Revertix_Settings::all();
$is_premium = revertix_is_premium();

$safety_choices = array( 1, 2, 3, 5, 10 );

$all_roles = function_exists( 'wp_roles' ) ? wp_roles()->get_names() : array();
$sel_roles = is_array( $s['staging_access_roles'] ) ? $s['staging_access_roles'] : array();
?>
<div class="revertix-settings" id="revertix-settings">

	<form id="revertix-settings-form" class="revertix-settings-form">

		<!-- Backups -->
		<section class="revertix-card">
			<div class="revertix-card__head">
				<h2 class="revertix-card__title"><?php esc_html_e( 'Backups', 'revertix' ); ?></h2>
			</div>
			<p class="revertix-card__desc">
				<?php esc_html_e( 'Safety backups are taken automatically before a restore or a push to live. Choose how many of the most recent ones to keep - older safety backups are pruned automatically. Your manual backups are never touched.', 'revertix' ); ?>
			</p>

			<label class="revertix-field">
				<span class="revertix-field__label"><?php esc_html_e( 'Keep the last', 'revertix' ); ?></span>
				<select id="revertix-set-safety-keep" name="safety_keep">
					<?php foreach ( $safety_choices as $n ) : ?>
						<option value="<?php echo esc_attr( $n ); ?>" <?php selected( (int) $s['safety_keep'], $n ); ?>>
							<?php
							/* translators: %d: number of safety backups. */
							echo esc_html( sprintf( _n( '%d safety backup', '%d safety backups', $n, 'revertix' ), $n ) );
							?>
						</option>
					<?php endforeach; ?>
				</select>
			</label>
		</section>

		<!-- Staging -->
		<section class="revertix-card">
			<div class="revertix-card__head">
				<h2 class="revertix-card__title"><?php esc_html_e( 'Staging', 'revertix' ); ?></h2>
			</div>

			<?php if ( $is_premium ) : ?>
			<div class="revertix-set-row">
				<div class="revertix-set-row__label">
					<span class="revertix-field__label"><?php esc_html_e( 'Staging admin bar colour', 'revertix' ); ?></span>
					<p class="revertix-set-row__help"><?php esc_html_e( 'The colour of the admin bar on staging sites, so a staging copy is unmistakable.', 'revertix' ); ?></p>
				</div>
				<input
					type="color"
					id="revertix-set-bar-color"
					name="staging_bar_color"
					value="<?php echo esc_attr( $s['staging_bar_color'] ); ?>"
				/>
			</div>
			<?php else : ?>
			<?php
			/*
			 * Not premium: the same settings-row layout for visual clarity, but
			 * the right side is a decorative mock (aria-hidden, no form control)
			 * that advertises what the separate Revertix PRO add-on provides.
			 */
			?>
			<div class="revertix-set-row revertix-set-row--locked" role="button" tabindex="0" data-revertix-locked="staging_color">
				<div class="revertix-set-row__label">
					<span class="revertix-field__label">
						<?php esc_html_e( 'Staging admin bar colour', 'revertix' ); ?>
						<span class="revertix-badge revertix-badge--pro"><?php esc_html_e( 'PRO', 'revertix' ); ?></span>
					</span>
					<p class="revertix-set-row__help"><?php esc_html_e( 'The colour of the admin bar on staging sites, so a staging copy is unmistakable.', 'revertix' ); ?></p>
				</div>
				<span class="revertix-set-lockval" aria-hidden="true">
					<span class="revertix-set-mock__swatch"></span>
					<span class="dashicons dashicons-lock"></span>
				</span>
			</div>
			<?php endif; ?>

			<div class="revertix-set-row">
				<div class="revertix-set-row__label">
					<span class="revertix-field__label"><?php esc_html_e( 'Disable admin authorization', 'revertix' ); ?></span>
					<p class="revertix-set-row__help"><?php esc_html_e( 'Disable the additional login form on the staging site. Search engine indexing is always blocked via the “noindex” tag, regardless of this setting.', 'revertix' ); ?></p>
				</div>
				<label class="revertix-toggle">
					<input type="checkbox" id="revertix-set-disable-auth" name="staging_disable_auth" <?php checked( ! empty( $s['staging_disable_auth'] ) ); ?> />
					<span class="screen-reader-text"><?php esc_html_e( 'Disable admin authorization', 'revertix' ); ?></span>
				</label>
			</div>

			<?php if ( $is_premium ) : ?>
			<div class="revertix-set-row">
				<div class="revertix-set-row__label">
					<span class="revertix-field__label"><?php esc_html_e( 'Users with staging access', 'revertix' ); ?></span>
					<p class="revertix-set-row__help"><?php esc_html_e( 'Specify users who will have access to the staging site regardless of their role. Enter multiple usernames separated by a comma. Change this option on the staging site if you want to change the authentication behaviour there.', 'revertix' ); ?></p>
				</div>
				<input
					type="text"
					id="revertix-set-access-users"
					name="staging_access_users"
					class="revertix-input"
					placeholder="<?php esc_attr_e( 'e.g. jane, john', 'revertix' ); ?>"
					value="<?php echo esc_attr( $s['staging_access_users'] ); ?>"
				/>
			</div>

			<div class="revertix-set-row">
				<div class="revertix-set-row__label">
					<span class="revertix-field__label"><?php esc_html_e( 'Access permissions', 'revertix' ); ?></span>
					<p class="revertix-set-row__help"><?php esc_html_e( 'Select the user roles you want to give access to the staging site. You can pick more than one. Change this option on the staging site if you want to change the authentication behaviour there.', 'revertix' ); ?></p>
				</div>
				<?php
				$sel_labels = array();
				foreach ( $all_roles as $slug => $label ) {
					if ( in_array( $slug, $sel_roles, true ) ) {
						$sel_labels[] = $label;
					}
				}
				$placeholder = __( 'Select roles…', 'revertix' );
				?>
				<div class="revertix-msel" id="revertix-set-access-roles" data-placeholder="<?php echo esc_attr( $placeholder ); ?>">
					<button type="button" class="revertix-msel__toggle" aria-haspopup="true" aria-expanded="false">
						<span class="revertix-msel__label<?php echo empty( $sel_labels ) ? ' is-placeholder' : ''; ?>">
							<?php echo esc_html( empty( $sel_labels ) ? $placeholder : implode( ', ', $sel_labels ) ); ?>
						</span>
						<span class="dashicons dashicons-arrow-down-alt2"></span>
					</button>
					<div class="revertix-msel__panel" hidden>
						<?php foreach ( $all_roles as $slug => $label ) : ?>
							<label class="revertix-msel__opt">
								<input
									type="checkbox"
									class="revertix-role-cb"
									value="<?php echo esc_attr( $slug ); ?>"
									<?php checked( in_array( $slug, $sel_roles, true ) ); ?>
								/>
								<span><?php echo esc_html( $label ); ?></span>
							</label>
						<?php endforeach; ?>
					</div>
				</div>
			</div>
			<?php else : ?>
			<div class="revertix-set-row revertix-set-row--locked" role="button" tabindex="0" data-revertix-locked="staging_access">
				<div class="revertix-set-row__label">
					<span class="revertix-field__label">
						<?php esc_html_e( 'Users with staging access', 'revertix' ); ?>
						<span class="revertix-badge revertix-badge--pro"><?php esc_html_e( 'PRO', 'revertix' ); ?></span>
					</span>
					<p class="revertix-set-row__help"><?php esc_html_e( 'Grant staging access to specific users regardless of their role. Provided by the separate Revertix PRO add-on.', 'revertix' ); ?></p>
				</div>
				<span class="revertix-set-lockval" aria-hidden="true">
					<span class="revertix-set-mock__pill">jane, john</span>
					<span class="dashicons dashicons-lock"></span>
				</span>
			</div>

			<div class="revertix-set-row revertix-set-row--locked" role="button" tabindex="0" data-revertix-locked="staging_access">
				<div class="revertix-set-row__label">
					<span class="revertix-field__label">
						<?php esc_html_e( 'Access permissions', 'revertix' ); ?>
						<span class="revertix-badge revertix-badge--pro"><?php esc_html_e( 'PRO', 'revertix' ); ?></span>
					</span>
					<p class="revertix-set-row__help"><?php esc_html_e( 'Give one or more user roles access to the staging site. Provided by the separate Revertix PRO add-on.', 'revertix' ); ?></p>
				</div>
				<span class="revertix-set-lockval" aria-hidden="true">
					<span class="revertix-set-mock__pill"><?php esc_html_e( 'Administrator', 'revertix' ); ?></span>
					<span class="revertix-set-mock__pill"><?php esc_html_e( 'Editor', 'revertix' ); ?></span>
					<span class="dashicons dashicons-lock"></span>
				</span>
			</div>

			<p class="revertix-set-locked-cta">
				<button type="button" class="revertix-btn revertix-btn--sm revertix-btn--upgrade revertix-open-pricing">
					<span class="dashicons dashicons-lock"></span>
					<?php esc_html_e( 'Available in Revertix PRO', 'revertix' ); ?>
				</button>
			</p>
			<?php endif; ?>
		</section>

		<!-- Rollback (snapshots are provided by the Revertix PRO add-on) -->
		<section class="revertix-card">
			<div class="revertix-card__head">
				<h2 class="revertix-card__title"><?php esc_html_e( 'Rollback', 'revertix' ); ?></h2>
				<?php if ( ! $is_premium ) : ?>
					<span class="revertix-badge revertix-badge--pro"><?php esc_html_e( 'PRO', 'revertix' ); ?></span>
				<?php endif; ?>
			</div>
			<p class="revertix-card__desc">
				<?php esc_html_e( 'Premium plugins & themes (not on WordPress.org) are snapshotted automatically once a day when their version changes, plus whenever you snapshot one manually. Choose how many snapshots to keep for each item - older ones are pruned automatically.', 'revertix' ); ?>
			</p>

			<?php if ( $is_premium ) : ?>
			<div class="revertix-set-row">
				<div class="revertix-set-row__label">
					<span class="revertix-field__label"><?php esc_html_e( 'Snapshots to keep per item', 'revertix' ); ?></span>
					<p class="revertix-set-row__help"><?php esc_html_e( 'How many version snapshots to keep for each premium plugin/theme. The oldest are deleted once the limit is exceeded.', 'revertix' ); ?></p>
				</div>
				<?php $rb_keep = (int) $s['rollback_keep']; ?>
				<select id="revertix-set-rb-keep" name="rollback_keep">
					<option value="0" <?php selected( 0, $rb_keep ); ?>><?php esc_html_e( 'Keep all', 'revertix' ); ?></option>
					<?php foreach ( array( 1, 2, 3, 5, 10, 20 ) as $n ) : ?>
						<option value="<?php echo esc_attr( $n ); ?>" <?php selected( $n, $rb_keep ); ?>>
							<?php
							/* translators: %d: number of snapshots. */
							echo esc_html( sprintf( _n( 'Last %d snapshot', 'Last %d snapshots', $n, 'revertix' ), $n ) );
							?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>
			<?php else : ?>
			<div class="revertix-set-row revertix-set-row--locked" role="button" tabindex="0" data-revertix-locked="snapshot_retention">
				<div class="revertix-set-row__label">
					<span class="revertix-field__label"><?php esc_html_e( 'Snapshots to keep per item', 'revertix' ); ?></span>
					<p class="revertix-set-row__help"><?php esc_html_e( 'Snapshot premium/custom plugins & themes and choose how many to keep for each. Provided by the separate Revertix PRO add-on.', 'revertix' ); ?></p>
				</div>
				<span class="revertix-set-lockval" aria-hidden="true">
					<span class="revertix-set-mock__pill"><?php esc_html_e( 'Last 5 snapshots', 'revertix' ); ?></span>
					<span class="dashicons dashicons-lock"></span>
				</span>
			</div>

			<p class="revertix-set-locked-cta">
				<button type="button" class="revertix-btn revertix-btn--sm revertix-btn--upgrade revertix-open-pricing">
					<span class="dashicons dashicons-lock"></span>
					<?php esc_html_e( 'Available in Revertix PRO', 'revertix' ); ?>
				</button>
			</p>
			<?php endif; ?>
		</section>

		<!-- Notifications -->
		<section class="revertix-card">
			<div class="revertix-card__head">
				<h2 class="revertix-card__title"><?php esc_html_e( 'Notifications', 'revertix' ); ?></h2>
			</div>
			<p class="revertix-card__desc">
				<?php esc_html_e( 'Configure email alerts for backup and remote sync events.', 'revertix' ); ?>
			</p>

			<fieldset class="revertix-set-fieldset">
				<legend class="revertix-field__label"><?php esc_html_e( 'Email notifications', 'revertix' ); ?></legend>

				<label class="revertix-toggle">
					<input type="checkbox" id="revertix-set-notify-errors" name="notify_email_errors" <?php checked( ! empty( $s['notify_email_errors'] ) ); ?> />
					<span><?php esc_html_e( 'Errors', 'revertix' ); ?></span>
				</label>
				<label class="revertix-toggle">
					<input type="checkbox" id="revertix-set-notify-warnings" name="notify_email_warnings" <?php checked( ! empty( $s['notify_email_warnings'] ) ); ?> />
					<span><?php esc_html_e( 'Warnings', 'revertix' ); ?></span>
				</label>
				<label class="revertix-toggle">
					<input type="checkbox" id="revertix-set-notify-status" name="notify_email_status" <?php checked( ! empty( $s['notify_email_status'] ) ); ?> />
					<span><?php esc_html_e( 'General backup status', 'revertix' ); ?></span>
				</label>

				<label class="revertix-field">
					<span class="revertix-field__label"><?php esc_html_e( 'Email address', 'revertix' ); ?></span>
					<input
						type="email"
						id="revertix-set-notify-email"
						name="notify_email_address"
						class="revertix-input"
						placeholder="<?php echo esc_attr( (string) get_option( 'admin_email' ) ); ?>"
						value="<?php echo esc_attr( $s['notify_email_address'] ); ?>"
					/>
				</label>

				<label class="revertix-toggle">
					<input type="checkbox" id="revertix-set-notify-html" name="notify_email_html" <?php checked( ! empty( $s['notify_email_html'] ) ); ?> />
					<span><?php esc_html_e( 'Send emails as HTML', 'revertix' ); ?></span>
				</label>
			</fieldset>
		</section>

		<!-- Uninstall -->
		<section class="revertix-card">
			<div class="revertix-card__head">
				<h2 class="revertix-card__title"><?php esc_html_e( 'Remove data on uninstall?', 'revertix' ); ?></h2>
			</div>
			<p class="revertix-card__desc">
				<strong><?php esc_html_e( 'Caution.', 'revertix' ); ?></strong>
				<?php esc_html_e( 'Remove all Revertix settings and data on uninstall. Staging site data, backups, and related database tables will not be deleted unless empty.', 'revertix' ); ?>
			</p>

			<label class="revertix-toggle">
				<input type="checkbox" id="revertix-set-remove-data" name="remove_data_on_uninstall" <?php checked( ! empty( $s['remove_data_on_uninstall'] ) ); ?> />
				<span><?php esc_html_e( 'Remove all data when the plugin is deleted', 'revertix' ); ?></span>
			</label>
		</section>

		<div class="revertix-settings-actions">
			<button type="submit" class="revertix-btn revertix-btn--primary" id="revertix-settings-save">
				<?php esc_html_e( 'Save settings', 'revertix' ); ?>
			</button>
		</div>

	</form>
</div>
