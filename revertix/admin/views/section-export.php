<?php
/**
 * Export / Import section.
 *
 * Export is fully functional (resumable AJAX) with a simple one-click export and
 * an Advanced panel to choose database tables and file areas. Finished packages
 * are listed below and persist across reloads (download / delete). Import is
 * part of the next build step.
 *
 * @package Revertix
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- variables here are local to this template/scope (included within a method), not true globals.

$zip_ok    = Revertix_Requirements::has_zip_archive();
$tables    = Revertix_Database::site_tables();
$areas     = Revertix_Filesystem::file_areas( 'export' );
$packages  = Revertix_Package_Registry::all();
$date_fmt  = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
$plugins   = Revertix_Filesystem::list_area_items( 'plugins' );
$themes    = Revertix_Filesystem::list_area_items( 'themes' );
?>
<div class="revertix-grid">

	<section class="revertix-card">
		<div class="revertix-card__head">
			<h2 class="revertix-card__title"><?php esc_html_e( 'Export site', 'revertix' ); ?></h2>
		</div>
		<p class="revertix-card__desc">
			<?php esc_html_e( 'Package your site - database and files - into a single downloadable zip. The export runs in small chunks, so even large sites won’t hit a PHP timeout.', 'revertix' ); ?>
		</p>

		<div class="revertix-export" id="revertix-export">
			<div class="revertix-export__start">
				<button type="button" class="revertix-btn revertix-btn--primary" id="revertix-export-start" <?php disabled( ! $zip_ok ); ?>>
					<span class="dashicons dashicons-download"></span>
					<?php esc_html_e( 'Export everything', 'revertix' ); ?>
				</button>
				<button type="button" class="revertix-btn revertix-btn--ghost" id="revertix-export-advanced-toggle" aria-expanded="false" <?php disabled( ! $zip_ok ); ?>>
					<span class="dashicons dashicons-admin-generic"></span>
					<?php esc_html_e( 'Advanced export', 'revertix' ); ?>
				</button>
				<?php if ( ! $zip_ok ) : ?>
					<p class="revertix-hint revertix-hint--error">
						<?php esc_html_e( 'Export is unavailable because the PHP ZipArchive extension is missing.', 'revertix' ); ?>
					</p>
				<?php endif; ?>
			</div>

			<!-- Advanced options panel -->
			<div class="revertix-advanced" id="revertix-advanced" hidden>
				<div class="revertix-advanced__cols">
					<fieldset class="revertix-fieldset">
						<label class="revertix-check revertix-check--lead">
							<input type="checkbox" id="revertix-include-db" checked>
							<span><?php esc_html_e( 'Database', 'revertix' ); ?></span>
						</label>

						<div class="revertix-subopt" data-db-options>
							<label class="revertix-radio">
								<input type="radio" name="revertix_db_mode" value="all" checked>
								<span><?php esc_html_e( 'All tables', 'revertix' ); ?></span>
							</label>
							<label class="revertix-radio">
								<input type="radio" name="revertix_db_mode" value="selected">
								<span><?php esc_html_e( 'Selected tables', 'revertix' ); ?></span>
							</label>

							<div class="revertix-tables" id="revertix-tables" hidden>
								<?php foreach ( $tables as $table ) : ?>
									<label class="revertix-check revertix-check--sm">
										<input type="checkbox" class="revertix-table" value="<?php echo esc_attr( $table ); ?>" checked>
										<span><?php echo esc_html( $table ); ?></span>
									</label>
								<?php endforeach; ?>
							</div>
						</div>
					</fieldset>

					<fieldset class="revertix-fieldset">
						<label class="revertix-check revertix-check--lead">
							<input type="checkbox" id="revertix-include-files" checked>
							<span><?php esc_html_e( 'Files', 'revertix' ); ?></span>
						</label>

			<div class="revertix-subopt" data-file-options>
							<?php foreach ( $areas as $key => $area ) : ?>
								<label class="revertix-check revertix-check--sm">
									<input type="checkbox" class="revertix-area" value="<?php echo esc_attr( $key ); ?>" data-area="<?php echo esc_attr( $key ); ?>" checked>
									<span><?php echo esc_html( $area['label'] ); ?></span>
								</label>

<?php if ( 'plugins' === $key && ! empty( $plugins ) ) : ?>
									<?php revertix_render_item_picker( 'plugins', $plugins, __( 'Choose plugins to export', 'revertix' ) ); ?>
								<?php elseif ( 'themes' === $key && ! empty( $themes ) ) : ?>
									<?php revertix_render_item_picker( 'themes', $themes, __( 'Choose themes to export', 'revertix' ) ); ?>
								<?php endif; ?>
							<?php endforeach; ?>

				<div class="revertix-exclude">
								<span class="revertix-field__label">
									<?php esc_html_e( 'Always skipped (dev folders, anywhere in the site)', 'revertix' ); ?>
								</span>
								<div class="revertix-chips">
									<?php foreach ( Revertix_Filesystem::excluded_names() as $name ) : ?>
										<span class="revertix-chip"><?php echo esc_html( $name ); ?></span>
									<?php endforeach; ?>
								</div>
								<p class="revertix-hint"><?php esc_html_e( 'These version-control and dependency folders are never exported or restored - they are dev artifacts and can break a restore. This list is fixed.', 'revertix' ); ?></p>
							</div>
						</div>
					</fieldset>
				</div>

				<button type="button" class="revertix-btn revertix-btn--primary" id="revertix-export-start-advanced">
					<span class="dashicons dashicons-download"></span>
					<?php esc_html_e( 'Start advanced export', 'revertix' ); ?>
				</button>
			</div>

			<?php include REVERTIX_PLUGIN_DIR . 'admin/views/partial-progress.php'; ?>

			<div class="revertix-export__done" id="revertix-export-done" hidden>
				<div class="revertix-result">
					<span class="dashicons dashicons-yes-alt"></span>
					<div>
						<strong><?php esc_html_e( 'Export complete', 'revertix' ); ?></strong>
						<span class="revertix-result__meta" id="revertix-export-meta"></span>
					</div>
				</div>
			</div>
		</div>
	</section>

	<section class="revertix-card">
		<div class="revertix-card__head">
			<h2 class="revertix-card__title"><?php esc_html_e( 'Import package', 'revertix' ); ?></h2>
		</div>
		<p class="revertix-card__desc">
			<?php esc_html_e( 'Restore a Revertix package onto this installation. URLs and paths are rewritten with serialize-safe search-and-replace, so serialized data stays intact.', 'revertix' ); ?>
		</p>

		<div class="revertix-import" id="revertix-import">
			<p class="revertix-hint revertix-hint--warn">
				<span class="dashicons dashicons-warning"></span>
				<?php esc_html_e( 'Importing overwrites this site’s database and files (wp-config.php and Revertix itself are kept). Back up first.', 'revertix' ); ?>
			</p>

			<div class="revertix-import__start">
				<input type="file" id="revertix-import-file" accept=".zip,application/zip" <?php disabled( ! $zip_ok ); ?>>
				<button type="button" class="revertix-btn revertix-btn--primary" id="revertix-import-start" <?php disabled( ! $zip_ok ); ?>>
					<span class="dashicons dashicons-upload"></span>
					<?php esc_html_e( 'Upload & import', 'revertix' ); ?>
				</button>
			</div>

			<div class="revertix-progress" id="revertix-import-progress" hidden>
				<div class="revertix-progress__bar">
					<div class="revertix-progress__fill" id="revertix-import-fill" style="width:0%"></div>
				</div>
				<div class="revertix-progress__meta">
					<span class="revertix-progress__label" id="revertix-import-label"></span>
					<span class="revertix-progress__pct" id="revertix-import-pct">0%</span>
				</div>
			</div>

			<div class="revertix-export__done" id="revertix-import-done" hidden>
				<div class="revertix-result">
					<span class="dashicons dashicons-yes-alt"></span>
					<div>
						<strong><?php esc_html_e( 'Import complete', 'revertix' ); ?></strong>
						<span class="revertix-result__meta" id="revertix-import-meta"></span>
					</div>
				</div>
			</div>
		</div>
	</section>
</div>

<!-- Saved exports -->
<section class="revertix-card revertix-card--full">
	<div class="revertix-card__head">
		<h2 class="revertix-card__title"><?php esc_html_e( 'Saved exports', 'revertix' ); ?></h2>
	</div>

	<div class="revertix-packages" id="revertix-packages">
		<p class="revertix-packages__empty" id="revertix-packages-empty" <?php echo empty( $packages ) ? '' : 'hidden'; ?>>
			<?php esc_html_e( 'No exports yet. Create one above and it will appear here.', 'revertix' ); ?>
		</p>

		<div class="revertix-packages__list" id="revertix-packages-list">
			<?php
			foreach ( $packages as $pkg ) :
				$parts = array();
				if ( ! empty( $pkg['parts']['database'] ) ) {
					$parts[] = __( 'Database', 'revertix' );
				}
				if ( ! empty( $pkg['parts']['files'] ) ) {
					$parts[] = __( 'Files', 'revertix' );
				}
				?>
				<div class="revertix-package" data-id="<?php echo esc_attr( $pkg['id'] ); ?>">
					<span class="dashicons dashicons-media-archive"></span>
					<div class="revertix-package__info">
						<strong class="revertix-package__name"><?php echo esc_html( $pkg['name'] ); ?></strong>
						<span class="revertix-package__meta">
							<?php
							echo esc_html(
								implode(
									' · ',
									array_filter(
										array(
											revertix_size( (int) $pkg['size'] ),
											implode( ' + ', $parts ),
											date_i18n( $date_fmt, (int) $pkg['created'] ),
										)
									)
								)
							);
							?>
						</span>
					</div>
					<div class="revertix-package__actions">
						<a class="revertix-btn revertix-btn--sm" href="<?php echo esc_url( Revertix_Package_Registry::download_url( $pkg['id'] ) ); ?>">
							<span class="dashicons dashicons-download"></span>
							<?php esc_html_e( 'Download', 'revertix' ); ?>
						</a>
						<?php
						$revertix_share = Revertix_Share::get( $pkg['id'] );
						// A link made before this version - or on a site with no
						// OpenSSL - is still valid, it just cannot be read back.
						$revertix_link = $revertix_share ? Revertix_Share::link( $pkg['id'] ) : null;
						?>
						<button type="button" class="revertix-btn revertix-btn--sm revertix-package-share" <?php echo $revertix_share ? 'hidden' : ''; ?>>
							<span class="dashicons dashicons-admin-links"></span>
							<?php esc_html_e( 'Get share link', 'revertix' ); ?>
						</button>
						<button type="button" class="revertix-btn revertix-btn--sm revertix-package-showshare" <?php echo $revertix_link ? '' : 'hidden'; ?>>
							<span class="dashicons dashicons-visibility"></span>
							<?php esc_html_e( 'Show link', 'revertix' ); ?>
						</button>
						<button type="button" class="revertix-btn revertix-btn--sm revertix-package-unshare" <?php echo $revertix_share ? '' : 'hidden'; ?>>
							<span class="dashicons dashicons-hidden"></span>
							<?php esc_html_e( 'Revoke link', 'revertix' ); ?>
						</button>
						<button type="button" class="revertix-btn revertix-btn--sm revertix-btn--danger revertix-package-delete">
							<span class="dashicons dashicons-trash"></span>
							<?php esc_html_e( 'Delete', 'revertix' ); ?>
						</button>
					</div>

				</div>
<?php endforeach; ?>

			<?php // The share dialog's body. The real controls belong to the PRO
			// add-on; without it this shows what they are, plainly out of reach. ?>
			<template id="revertix-share-form-template">
				<div class="revertix-share-form">
					<?php
					ob_start();
					/**
					 * Render the share-link controls (expiry, download cap, password).
					 *
					 * Nothing is attached in the free plugin, which is what makes the
					 * locked preview below show instead.
					 */
					do_action( 'revertix_share_form_fields' );
					$revertix_share_fields = ob_get_clean();

					if ( '' !== trim( (string) $revertix_share_fields ) ) {
						echo $revertix_share_fields; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the add-on that rendered it.
					} else {
						require REVERTIX_PLUGIN_DIR . 'admin/views/partial-share-locked.php';
					}
					?>

					<p class="revertix-hint revertix-hint--warn">
						<span class="dashicons dashicons-warning"></span>
						<?php esc_html_e( 'Anyone holding the link can download the package, and it contains your whole site.', 'revertix' ); ?>
					</p>
				</div>
			</template>
		</div>
	</div>
</section>
<?php
/**
 * Render a collapsible per-item picker (plugins or themes) inside the Advanced
 * export panel. Each item is a checkbox; all start checked (export everything).
 *
 * @param string                $area  'plugins' or 'themes'.
 * @param array<string,string> $items slug => label.
 * @param string                $title Picker heading.
 */
function revertix_render_item_picker( $area, array $items, $title ) {
	?>
	<details class="revertix-picker" data-picker="<?php echo esc_attr( $area ); ?>">
		<summary class="revertix-picker__summary">
			<?php echo esc_html( $title ); ?>
			<span class="revertix-picker__count" data-picker-count><?php echo esc_html( count( $items ) ); ?></span>
		</summary>

		<div class="revertix-picker__actions">
			<button type="button" class="revertix-link" data-picker-all><?php esc_html_e( 'Select all', 'revertix' ); ?></button>
			<button type="button" class="revertix-link" data-picker-none><?php esc_html_e( 'Select none', 'revertix' ); ?></button>
		</div>

		<div class="revertix-picker__list">
			<?php foreach ( $items as $slug => $label ) : ?>
				<label class="revertix-check revertix-check--sm">
					<input type="checkbox" class="revertix-item revertix-item--<?php echo esc_attr( $area ); ?>" value="<?php echo esc_attr( $slug ); ?>" checked>
					<span><?php echo esc_html( $label ); ?></span>
				</label>
			<?php endforeach; ?>
		</div>
	</details>
	<?php
}
