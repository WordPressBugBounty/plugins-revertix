<?php
/**
 * Backups section.
 *
 * Create unlimited manual backups; download, restore or delete any of them
 * (the most recent one is kept as a safety net). Restore reuses the import
 * engine and is preceded by an automatic safety backup. Scheduled automatic
 * backups are provided by the separate Revertix PRO add-on.
 *
 * @package Revertix
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- variables here are local to this template/scope (included within a method), not true globals.

$zip_ok    = Revertix_Requirements::has_zip_archive();
$backups   = Revertix_Backup::all();
$latest_id = Revertix_Backup::latest_id();
$date_fmt  = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
?>
<section class="revertix-card revertix-backups-card">
	<div class="revertix-card__head">
		<h2 class="revertix-card__title"><?php esc_html_e( 'Backups', 'revertix' ); ?></h2>
		<span class="revertix-badge"><?php esc_html_e( 'Free', 'revertix' ); ?></span>
	</div>

	<p class="revertix-card__desc">
		<?php esc_html_e( 'A backup is a single zip of your site (files + database) kept in a protected folder. Make one any time, keep as many as you like, then download or restore any of them.', 'revertix' ); ?>
	</p>

<div class="revertix-backups" id="revertix-backups">

		<div class="revertix-backups__section">
			<h3 class="revertix-backups__subhead"><span class="dashicons dashicons-plus-alt"></span><?php esc_html_e( 'Create a backup', 'revertix' ); ?></h3>

			<input type="text" id="revertix-backup-name" class="revertix-input revertix-backup-name" placeholder="<?php esc_attr_e( 'Backup name (optional)', 'revertix' ); ?>" <?php disabled( ! $zip_ok ); ?>>

<div class="revertix-backup-options">
			<span class="revertix-field__label"><?php esc_html_e( 'What to include', 'revertix' ); ?></span>

			<div class="revertix-opts">
				<label class="revertix-opt">
					<input type="checkbox" id="revertix-backup-uploads" checked>
					<span class="dashicons dashicons-format-image"></span>
					<span class="revertix-opt__label"><?php esc_html_e( 'Media library (uploads)', 'revertix' ); ?></span>
				</label>
				<label class="revertix-opt">
					<input type="checkbox" id="revertix-backup-themes" checked>
					<span class="dashicons dashicons-admin-appearance"></span>
					<span class="revertix-opt__label"><?php esc_html_e( 'Themes', 'revertix' ); ?></span>
				</label>
				<label class="revertix-opt">
					<input type="checkbox" id="revertix-backup-plugins" checked>
					<span class="dashicons dashicons-admin-plugins"></span>
					<span class="revertix-opt__label"><?php esc_html_e( 'Plugins', 'revertix' ); ?></span>
				</label>
				<label class="revertix-opt">
					<input type="checkbox" id="revertix-backup-mu" checked>
					<span class="dashicons dashicons-admin-generic"></span>
					<span class="revertix-opt__label"><?php esc_html_e( 'Must-use plugins', 'revertix' ); ?></span>
				</label>
				<label class="revertix-opt">
					<input type="checkbox" id="revertix-backup-wpcontent" checked>
					<span class="dashicons dashicons-media-default"></span>
					<span class="revertix-opt__label"><?php esc_html_e( 'Other files in wp-content', 'revertix' ); ?></span>
				</label>

				<?php if ( array_key_exists( 'root', Revertix_Filesystem::file_areas( 'backup' ) ) ) : ?>
					<label class="revertix-opt">
						<input type="checkbox" id="revertix-backup-root">
						<span class="dashicons dashicons-wordpress"></span>
						<span class="revertix-opt__label"><?php esc_html_e( 'Other WordPress root folders', 'revertix' ); ?></span>
					</label>
				<?php else : ?>
					<?php
					/*
					 * The whole-root backup area is provided by the separate
					 * Revertix PRO add-on. Presentational teaser only: the
					 * checkbox is decorative (disabled, no name - it can never
					 * submit); clicking the row opens the pricing modal.
					 */
					?>
					<span class="revertix-opt revertix-opt--locked" role="button" tabindex="0" data-revertix-locked="backup_root">
						<input type="checkbox" disabled aria-hidden="true" tabindex="-1">
						<span class="dashicons dashicons-wordpress"></span>
						<span class="revertix-opt__label"><?php esc_html_e( 'Other WordPress root folders', 'revertix' ); ?></span>
						<span class="revertix-badge revertix-badge--pro"><?php esc_html_e( 'PRO', 'revertix' ); ?></span>
						<span class="dashicons dashicons-lock revertix-opt__lock" aria-hidden="true"></span>
					</span>
				<?php endif; ?>

<label class="revertix-opt revertix-opt--db">
					<input type="checkbox" id="revertix-backup-db" checked>
					<span class="dashicons dashicons-database"></span>
					<span class="revertix-opt__label"><?php esc_html_e( 'Database', 'revertix' ); ?></span>
				</label>
			</div>
		</div>

		<div class="revertix-storage-providers">
			<span class="revertix-field__label"><?php esc_html_e( 'Storage', 'revertix' ); ?></span>
			<div class="revertix-storage-grid">
				<div class="revertix-storage-provider is-active" title="<?php esc_attr_e( 'Backups are always saved on this server first.', 'revertix' ); ?>">
					<span class="dashicons dashicons-database"></span>
					<span class="revertix-storage-provider__label"><?php esc_html_e( 'Local storage', 'revertix' ); ?></span>
					<span class="dashicons dashicons-yes-alt revertix-storage-check"></span>
				</div>

				<?php if ( Revertix_Locked_Feature::is_unlocked( 'remote_storage' ) && has_action( 'revertix_pro_render_storage_tiles' ) ) : ?>
					<?php
					/**
					 * Render the live remote-storage tiles (Revertix PRO).
					 */
					do_action( 'revertix_pro_render_storage_tiles' );
					?>
				<?php else : ?>
					<?php
					/*
					 * Remote storage is provided by the separate Revertix PRO
					 * add-on. Presentational teasers only: clicking opens the
					 * pricing modal; nothing here talks to an external service.
					 */
					?>
					<div class="revertix-storage-provider revertix-storage-provider--locked" role="button" tabindex="0" data-revertix-locked="remote_storage">
						<span class="dashicons dashicons-cloud"></span>
						<span class="revertix-storage-provider__label"><?php esc_html_e( 'Google Drive', 'revertix' ); ?></span>
						<span class="revertix-badge revertix-badge--pro"><?php esc_html_e( 'PRO', 'revertix' ); ?></span>
						<span class="dashicons dashicons-lock revertix-storage-lock" aria-hidden="true"></span>
					</div>
					<div class="revertix-storage-provider revertix-storage-provider--locked" role="button" tabindex="0" data-revertix-locked="remote_storage">
						<span class="dashicons dashicons-networking"></span>
						<span class="revertix-storage-provider__label"><?php esc_html_e( 'FTP / SFTP server', 'revertix' ); ?></span>
						<span class="revertix-badge revertix-badge--pro"><?php esc_html_e( 'PRO', 'revertix' ); ?></span>
						<span class="dashicons dashicons-lock revertix-storage-lock" aria-hidden="true"></span>
					</div>
				<?php endif; ?>
			</div>
		</div>

		<div class="revertix-staging__create">
			<button type="button" class="revertix-btn revertix-btn--primary" id="revertix-backup-start" <?php disabled( ! $zip_ok ); ?>>
				<span class="dashicons dashicons-backup"></span>
				<?php esc_html_e( 'Create backup', 'revertix' ); ?>
			</button>
		</div>

			<?php if ( ! $zip_ok ) : ?>
				<p class="revertix-hint revertix-hint--error">
					<?php esc_html_e( 'Backups are unavailable because the PHP ZipArchive extension is missing.', 'revertix' ); ?>
				</p>
			<?php endif; ?>
		</div><!-- /.revertix-backups__section (create) -->

		<div class="revertix-backups__section">
			<h3 class="revertix-backups__subhead"><span class="dashicons dashicons-backup"></span><?php esc_html_e( 'Your backups', 'revertix' ); ?></h3>

			<p class="revertix-hint revertix-hint--info">
				<span class="dashicons dashicons-shield"></span>
				<?php esc_html_e( 'Restoring replaces this site’s files and database with a backup. Revertix always takes a fresh safety backup first, so you can roll back. wp-config.php and Revertix itself are never touched.', 'revertix' ); ?>
			</p>

			<div class="revertix-staging__list" id="revertix-backups-list">
			<p class="revertix-packages__empty" id="revertix-backups-empty" <?php echo empty( $backups ) ? '' : 'hidden'; ?>>
				<?php esc_html_e( 'No backups yet. Create one above.', 'revertix' ); ?>
			</p>

			<?php
			foreach ( $backups as $backup ) :
				$is_latest  = ( $backup['id'] === $latest_id );
				$can_delete = Revertix_Backup::can_delete( $backup['id'] );
				$is_safety    = ! empty( $backup['safety'] );
				$parts        = array();
				if ( ! empty( $backup['parts']['database'] ) ) {
					$parts[] = __( 'Database', 'revertix' );
				}
				if ( ! empty( $backup['parts']['files'] ) ) {
					$parts[] = __( 'Files', 'revertix' );
				}
				?>
				<div class="revertix-package revertix-backup-item" data-id="<?php echo esc_attr( $backup['id'] ); ?>">
					<span class="revertix-staging-item__badge revertix-backup-item__badge<?php echo $is_safety ? ' is-safety' : ''; ?>">
						<?php echo $is_safety ? esc_html__( 'SAFETY', 'revertix' ) : esc_html__( 'BACKUP', 'revertix' ); ?>
					</span>
					<div class="revertix-package__info">
						<strong class="revertix-package__name">
							<?php echo esc_html( $backup['name'] ); ?>
							<?php if ( $is_latest ) : ?>
								<span class="revertix-tag-latest"><?php esc_html_e( 'latest', 'revertix' ); ?></span>
							<?php endif; ?>
						</strong>
						<span class="revertix-package__meta">
							<?php
							echo esc_html(
								implode(
									' · ',
									array_filter(
										array(
											revertix_size( (int) $backup['size'] ),
											implode( ' + ', $parts ),
											date_i18n( $date_fmt, (int) $backup['created'] ),
										)
									)
								)
							);
							?>
							<?php
							/**
							 * Extra status for one backup row (e.g. remote copies, Revertix PRO).
							 *
							 * @param array $backup Backup entry.
							 */
							do_action( 'revertix_backup_item_status', $backup );
							?>
						</span>
					</div>
					<div class="revertix-package__actions">
<a class="revertix-btn revertix-btn--sm" href="<?php echo esc_url( Revertix_Backup::download_url( $backup['id'] ) ); ?>">
							<span class="dashicons dashicons-download"></span>
							<?php esc_html_e( 'Download', 'revertix' ); ?>
						</a>

						<button type="button" class="revertix-btn revertix-btn--sm revertix-btn--primary revertix-backup-restore">
							<span class="dashicons dashicons-image-rotate"></span>
							<?php esc_html_e( 'Restore', 'revertix' ); ?>
						</button>

<?php if ( $can_delete ) : ?>
							<button type="button" class="revertix-btn revertix-btn--sm revertix-btn--danger revertix-backup-delete">
								<span class="dashicons dashicons-trash"></span>
								<?php esc_html_e( 'Delete', 'revertix' ); ?>
							</button>
<?php else : ?>
							<button type="button" class="revertix-btn revertix-btn--sm revertix-backup-delete-locked" title="<?php esc_attr_e( 'Your most recent backup is kept for safety.', 'revertix' ); ?>">
								<span class="dashicons dashicons-shield"></span>
								<?php esc_html_e( 'Delete', 'revertix' ); ?>
							</button>
						<?php endif; ?>
					</div>
				</div>
			<?php endforeach; ?>
		</div>

		<?php
		/**
		 * Fires after the list of local backups (e.g. backups kept in remote
		 * storage, Revertix PRO).
		 */
		do_action( 'revertix_backups_after_list' );
		?>
		</div><!-- /.revertix-backups__section (your backups) -->
	</div>
</section>

<div class="revertix-grid">
	<?php
	Revertix_Locked_Feature::render(
		array(
			'feature' => 'scheduled_backups',
			'title'   => __( 'Scheduled automatic backups', 'revertix' ),
			'desc'    => __( 'Let Revertix back up your site on a schedule via WP-Cron. Provided by the separate Revertix PRO add-on.', 'revertix' ),
			'preview' => 'revertix_render_schedule_preview',
		)
	);

	Revertix_Locked_Feature::render(
		array(
			'feature' => 'remote_storage',
			'title'   => __( 'Remote storage', 'revertix' ),
			'desc'    => __( 'Copy every backup automatically to your own Google Drive or FTP/SFTP server, so a copy survives even if this server does not. Provided by the separate Revertix PRO add-on.', 'revertix' ),
			'preview' => 'revertix_render_remote_preview',
		)
	);
	?>
</div>
<?php
/**
 * Locked-state preview of the schedule frequency options.
 */
function revertix_render_schedule_preview() {
	$labels = revertix_backup_frequency_labels();
	echo '<ul class="revertix-preview-list">';
	foreach ( $labels as $label ) {
		echo '<li><span class="dashicons dashicons-clock"></span>' . esc_html( $label ) . '</li>';
	}
	echo '</ul>';
}

/**
 * Locked-state preview of the remote storage destinations.
 */
function revertix_render_remote_preview() {
	$items = array(
		'dashicons-cloud'      => __( 'Google Drive - connect your account in one click', 'revertix' ),
		'dashicons-networking' => __( 'FTP, FTPS or SFTP - your own server', 'revertix' ),
		'dashicons-trash'      => __( 'Keep the last N copies, optionally remove the local one', 'revertix' ),
		'dashicons-image-rotate' => __( 'Restore straight from the cloud', 'revertix' ),
	);
	echo '<ul class="revertix-preview-list">';
	foreach ( $items as $icon => $label ) {
		echo '<li><span class="dashicons ' . esc_attr( $icon ) . '"></span>' . esc_html( $label ) . '</li>';
	}
	echo '</ul>';
}
