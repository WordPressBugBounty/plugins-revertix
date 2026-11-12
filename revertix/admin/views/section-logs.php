<?php
/**
 * System Info & Logs section.
 *
 * Shows a full system-information report plus Revertix's persistent diagnostic
 * log, and lets the user download everything in one text file to send when
 * reporting a problem.
 *
 * @package Revertix
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- variables here are local to this template/scope (included within a method), not true globals.

$log_contents = Revertix_Log::read();
$has_entries  = '' !== trim( $log_contents );
$entries      = $has_entries ? array_reverse( Revertix_Log::parse( $log_contents ) ) : array();
$log_settings = Revertix_Log::settings();
$retention    = Revertix_Log::retention_choices();
$sysinfo      = Revertix_System_Info::sections();
$download_url = wp_nonce_url( admin_url( 'admin-post.php?action=revertix_download_log' ), 'revertix_download_log' );
?>
<div class="revertix-logs">

	<section class="revertix-card">
		<div class="revertix-card__head">
			<h2 class="revertix-card__title"><?php esc_html_e( 'System info', 'revertix' ); ?></h2>
			<span class="revertix-badge"><?php esc_html_e( 'Free', 'revertix' ); ?></span>
		</div>

		<p class="revertix-card__desc">
			<?php esc_html_e( 'A snapshot of your site, server and Revertix’s state. When reporting a problem, download the full report (this info + the diagnostic log) and send it to support - it’s everything needed to diagnose the issue.', 'revertix' ); ?>
		</p>

		<div class="revertix-log-toolbar">
			<a class="revertix-btn revertix-btn--primary" href="<?php echo esc_url( $download_url ); ?>">
				<span class="dashicons dashicons-download"></span>
				<?php esc_html_e( 'Download full report', 'revertix' ); ?>
			</a>
		</div>

<p class="revertix-hint"><?php esc_html_e( 'Click a section to expand it.', 'revertix' ); ?></p>

		<div class="revertix-sysinfo">
			<?php foreach ( $sysinfo as $section ) : ?>
				<details class="revertix-sysinfo__section">
					<summary class="revertix-sysinfo__title">
						<?php echo esc_html( $section['title'] ); ?>
						<span class="revertix-sysinfo__count"><?php echo esc_html( count( $section['rows'] ) ); ?></span>
					</summary>
					<dl class="revertix-sysinfo__rows">
						<?php foreach ( $section['rows'] as $row ) : ?>
							<div>
								<dt><?php echo esc_html( $row[0] ); ?></dt>
								<dd title="<?php echo esc_attr( $row[1] ); ?>"><?php echo esc_html( '' !== $row[1] ? $row[1] : '-' ); ?></dd>
							</div>
						<?php endforeach; ?>
					</dl>
				</details>
			<?php endforeach; ?>
		</div>
	</section>

	<section class="revertix-card">
		<div class="revertix-card__head">
			<h2 class="revertix-card__title"><?php esc_html_e( 'Diagnostic log', 'revertix' ); ?></h2>
		</div>

		<p class="revertix-card__desc">
			<?php esc_html_e( 'Revertix records errors and key steps from exports, imports, staging and backups here.', 'revertix' ); ?>
		</p>

		<div class="revertix-log-toolbar">
			<button type="button" class="revertix-btn revertix-btn--ghost" id="revertix-log-copy" <?php disabled( ! $has_entries ); ?>>
				<span class="dashicons dashicons-clipboard"></span>
				<?php esc_html_e( 'Copy', 'revertix' ); ?>
			</button>

			<button type="button" class="revertix-btn revertix-btn--danger" id="revertix-log-clear" <?php disabled( ! $has_entries ); ?>>
				<span class="dashicons dashicons-trash"></span>
				<?php esc_html_e( 'Clear', 'revertix' ); ?>
			</button>
		</div>

		<?php if ( $has_entries ) : ?>
			<p class="revertix-hint"><?php esc_html_e( 'Most recent entries first. Times are in UTC.', 'revertix' ); ?></p>

			<ol class="revertix-log">
				<?php foreach ( $entries as $entry ) : ?>
					<?php $level = strtolower( $entry['level'] ); ?>
					<li class="revertix-log-entry<?php echo $level ? ' revertix-log-entry--' . esc_attr( $level ) : ''; ?>">
						<div class="revertix-log-entry__head">
							<?php if ( '' !== $entry['level'] ) : ?>
								<span class="revertix-log-level revertix-log-level--<?php echo esc_attr( $level ); ?>"><?php echo esc_html( $entry['level'] ); ?></span>
							<?php endif; ?>
							<?php if ( '' !== $entry['time'] ) : ?>
								<time class="revertix-log-time"><?php echo esc_html( $entry['time'] ); ?></time>
							<?php endif; ?>
						</div>

						<div class="revertix-log-entry__msg"><?php echo esc_html( $entry['message'] ); ?></div>

						<?php if ( ! empty( $entry['context'] ) ) : ?>
							<ul class="revertix-log-entry__ctx">
								<?php foreach ( $entry['context'] as $ctx ) : ?>
									<li><?php echo esc_html( $ctx ); ?></li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ol>

			<?php // Hidden raw copy used by the Copy button. ?>
			<textarea class="revertix-log-raw" id="revertix-log-view" readonly hidden aria-hidden="true"><?php echo esc_textarea( $log_contents ); ?></textarea>
		<?php else : ?>
			<p class="revertix-hint" id="revertix-log-empty"><?php esc_html_e( 'The log is empty - nothing has been recorded yet.', 'revertix' ); ?></p>
		<?php endif; ?>
	</section>

	<section class="revertix-card">
		<div class="revertix-card__head">
			<h2 class="revertix-card__title"><?php esc_html_e( 'Log retention', 'revertix' ); ?></h2>
		</div>

		<p class="revertix-card__desc">
			<?php esc_html_e( 'Automatically delete entries older than the chosen period. Turn this off to keep the full history indefinitely.', 'revertix' ); ?>
		</p>

		<form class="revertix-log-settings" id="revertix-log-settings">
			<label class="revertix-toggle">
				<input type="checkbox" id="revertix-log-autoprune" <?php checked( $log_settings['auto_prune'] ); ?> />
				<span><?php esc_html_e( 'Automatically delete old entries', 'revertix' ); ?></span>
			</label>

			<label class="revertix-field">
				<span class="revertix-field__label"><?php esc_html_e( 'Keep entries for', 'revertix' ); ?></span>
				<select id="revertix-log-retention" <?php disabled( ! $log_settings['auto_prune'] ); ?>>
					<?php foreach ( $retention as $days => $label ) : ?>
						<option value="<?php echo esc_attr( $days ); ?>" <?php selected( $log_settings['retention_days'], $days ); ?>>
							<?php echo esc_html( $label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</label>

			<button type="submit" class="revertix-btn revertix-btn--primary" id="revertix-log-save">
				<?php esc_html_e( 'Save', 'revertix' ); ?>
			</button>
		</form>
	</section>
</div>
