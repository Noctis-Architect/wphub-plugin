<?php
defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are local to the admin method that requires this file.

$next         = wp_next_scheduled( WPHS_Aggregator::EVENT );
$last_run     = (int) get_option( 'wphs_last_run', 0 );
$spool        = WPHS_Spool::size();
$files        = WPHS_Spool::files();
$dashboard    = add_query_arg( array( 'page' => 'statwrite' ), admin_url( 'admin.php' ) );
$writer       = add_query_arg( array( 'page' => 'statwrite-writer' ), admin_url( 'admin.php' ) );
/* translators: %s: time difference, for example "5 minutes". */
$last_run_txt = $last_run > 0 ? sprintf( __( '%s ago', 'statwrite' ), human_time_diff( $last_run, time() ) ) : __( 'Not yet', 'statwrite' );
$next_txt     = $next && $next > time() ? wp_date( 'Y/m/d H:i', $next ) : __( 'Waiting to run', 'statwrite' );
$dir_ok       = is_dir( WPHS_Spool::dir() ) && wp_is_writable( WPHS_Spool::dir() );
// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Local template variable.
$mode         = WPHS_Settings::mode();
$cloud        = WPHS_Cloud::status();
$cloud_notice = get_transient( 'wphs_cloud_notice' );
$token        = (string) $settings['console_token'];
$masked       = '' !== $token ? substr( $token, 0, 9 ) . str_repeat( '•', 12 ) . substr( $token, -4 ) : '';
$ping_ok      = '' !== $token && (int) get_option( 'wphs_cloud_ping_ok', 0 ) && (int) get_option( 'wphs_cloud_ping_at', 0 ) > 0;

if ( $cloud_notice ) {
	delete_transient( 'wphs_cloud_notice' );
}

/* translators: %s: time difference, for example "5 minutes". */
$cloud_last_success = $cloud['last_success'] > 0 ? sprintf( __( '%s ago', 'statwrite' ), human_time_diff( $cloud['last_success'], time() ) ) : '—';
/* translators: %s: time difference until the next attempt. */
$cloud_next_txt     = $cloud['next_attempt'] > time() ? sprintf( __( 'in about %s', 'statwrite' ), human_time_diff( time(), $cloud['next_attempt'] ) ) : '—';
$cloud_cron_txt     = $cloud['cron_next'] > time() ? wp_date( 'Y/m/d H:i', $cloud['cron_next'] ) : __( 'Waiting to run', 'statwrite' );

$day_label   = static function ( $days ) {
	/* translators: %s: number of days. */
	return sprintf( _n( '%s day', '%s days', $days, 'statwrite' ), number_format_i18n( $days ) );
};
$month_label = static function ( $months ) {
	/* translators: %s: number of months. */
	return 0 === $months ? __( 'Keep forever', 'statwrite' ) : sprintf( _n( '%s month', '%s months', $months, 'statwrite' ), number_format_i18n( $months ) );
};
?>
<div class="wrap wphs" dir="<?php echo is_rtl() ? 'rtl' : 'ltr'; ?>">
	<hr class="wp-header-end">
	<header class="wphs-hero">
		<div class="wphs-hero-brand">
			<span class="wphs-logo"><img src="<?php echo esc_url( WPHS_URL . 'assets/logo.png' ); ?>" alt="" width="38" height="38"></span>
			<div>
				<h1 class="wphs-title"><?php esc_html_e( 'Statwrite Settings', 'statwrite' ); ?> <?php WPHS_Admin::byline(); ?></h1>
				<p class="wphs-sub"><?php esc_html_e( 'Console connection, privacy and data retention', 'statwrite' ); ?></p>
			</div>
		</div>
		<div class="wphs-hero-side">
			<a class="wphs-btn is-ghost" href="<?php echo esc_url( $dashboard ); ?>"><?php esc_html_e( 'Back to dashboard', 'statwrite' ); ?></a>
		</div>
	</header>

	<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display flag. ?>
	<?php if ( isset( $_GET['updated'] ) ) : ?>
		<div class="wphs-notice is-success"><?php esc_html_e( 'Settings saved.', 'statwrite' ); ?></div>
	<?php endif; ?>

	<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display flag. ?>
	<?php if ( isset( $_GET['flushed'] ) ) : ?>
		<div class="wphs-notice is-success"><?php esc_html_e( 'Queue processed; existing data was added to analytics.', 'statwrite' ); ?></div>
	<?php endif; ?>

	<?php if ( $cloud_notice ) : ?>
		<div class="wphs-notice<?php echo 'success' === $cloud_notice['type'] ? ' is-success' : ' is-error'; ?>"><?php echo esc_html( $cloud_notice['message'] ); ?></div>
	<?php endif; ?>

	<?php if ( 'cloud' === $mode && '' === $token ) : ?>
		<div class="wphs-notice is-error"><?php esc_html_e( 'Cloud mode needs the site key. Until you add it, nothing is sent to the console.', 'statwrite' ); ?></div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wphs-form">
		<input type="hidden" name="action" value="wphs_save_settings">
		<?php wp_nonce_field( 'wphs_save_settings' ); ?>

		<section class="wphs-card">
			<div class="wphs-card-head">
				<h2><?php WPHS_Admin::wphub_mark(); ?><?php esc_html_e( 'WP-Hub console', 'statwrite' ); ?></h2>
				<?php if ( '' === $token ) : ?>
					<span class="wphs-pill"><?php esc_html_e( 'Not connected', 'statwrite' ); ?></span>
				<?php elseif ( $ping_ok ) : ?>
					<span class="wphs-pill is-green"><?php esc_html_e( 'Connected', 'statwrite' ); ?></span>
				<?php else : ?>
					<span class="wphs-pill is-amber"><?php esc_html_e( 'Key saved — not tested', 'statwrite' ); ?></span>
				<?php endif; ?>
			</div>
			<div class="wphs-connect">
				<div>
					<p class="wphs-hint" style="margin-top:0"><?php esc_html_e( 'Optional. With a free site key the dashboard also shows speed, security and uptime reports, analytics can be stored in the console, and the AI Writer can write articles for you.', 'statwrite' ); ?></p>
					<?php if ( '' === $token ) : ?>
						<label for="wphs-console-token" class="screen-reader-text"><?php esc_html_e( 'Site key', 'statwrite' ); ?></label>
						<input type="text" id="wphs-console-token" name="console_token" value="" class="large-text" placeholder="wphs_..." dir="ltr" autocomplete="off" spellcheck="false">
					<?php else : ?>
						<code class="wphs-key-saved" dir="ltr"><?php echo esc_html( $masked ); ?></code>
					<?php endif; ?>
				</div>
				<div class="wphs-actions-row">
					<?php if ( '' === $token ) : ?>
						<a class="wphs-btn is-secondary" href="<?php echo esc_url( WPHS_Settings::console_url() ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Get a key', 'statwrite' ); ?> ↗</a>
					<?php else : ?>
						<button type="submit" class="wphs-btn is-secondary" form="wphs-test-form"><?php esc_html_e( 'Test connection', 'statwrite' ); ?></button>
						<button type="submit" class="wphs-btn is-danger" form="wphs-disconnect-form"><?php esc_html_e( 'Disconnect', 'statwrite' ); ?></button>
					<?php endif; ?>
				</div>
			</div>
		</section>

		<section class="wphs-card">
			<div class="wphs-card-head">
				<h2><?php esc_html_e( 'Where analytics are stored', 'statwrite' ); ?></h2>
			</div>
			<div class="wphs-mode-grid">
				<label class="wphs-mode-card">
					<input type="radio" name="storage_mode" value="local" <?php checked( $settings['storage_mode'], 'local' ); ?>>
					<span class="wphs-mode-title"><?php esc_html_e( 'On this site', 'statwrite' ); ?></span>
					<span class="wphs-mode-desc"><?php esc_html_e( 'Default. Visits are stored in this site\'s database and shown on the Statwrite dashboard. Nothing leaves your server.', 'statwrite' ); ?></span>
				</label>
				<label class="wphs-mode-card">
					<input type="radio" name="storage_mode" value="cloud" <?php checked( $settings['storage_mode'], 'cloud' ); ?>>
					<span class="wphs-mode-title"><?php esc_html_e( 'In the WP-Hub console', 'statwrite' ); ?></span>
					<span class="wphs-mode-desc"><?php esc_html_e( 'Keeps your database lean: visits wait in a small temporary file and are sent to the console every five minutes. Needs the site key.', 'statwrite' ); ?></span>
				</label>
			</div>
		</section>

		<div class="wphs-settings-grid">
			<section class="wphs-card">
				<div class="wphs-card-head">
					<h2><?php esc_html_e( 'Privacy and filtering', 'statwrite' ); ?></h2>
					<span class="wphs-muted"><?php esc_html_e( 'Cookie-free', 'statwrite' ); ?></span>
				</div>
				<ul class="wphs-check-list">
					<li>
						<label><input type="checkbox" name="respect_dnt" value="1" <?php checked( ! empty( $settings['respect_dnt'] ) ); ?>> <?php esc_html_e( 'Respect visitors\' DNT / GPC requests', 'statwrite' ); ?></label>
					</li>
					<li>
						<label><input type="checkbox" name="bot_js_check" value="1" <?php checked( ! empty( $settings['bot_js_check'] ) ); ?>> <?php esc_html_e( 'Check the browser to count a visit as a real visit', 'statwrite' ); ?></label>
						<p class="wphs-hint"><?php esc_html_e( 'Known bots are always filtered. This extra check separates real browsers from scripts.', 'statwrite' ); ?></p>
					</li>
					<li>
						<label><input type="checkbox" name="exclude_404" value="1" <?php checked( ! empty( $settings['exclude_404'] ) ); ?>> <?php esc_html_e( 'Do not track 404 pages', 'statwrite' ); ?></label>
					</li>
					<li>
						<label><input type="checkbox" name="track_logged_in" value="1" <?php checked( ! empty( $settings['track_logged_in'] ) ); ?>> <?php esc_html_e( 'Track logged-in users too', 'statwrite' ); ?></label>
						<p class="wphs-hint"><?php esc_html_e( 'Off by default so your own visits do not inflate the numbers.', 'statwrite' ); ?></p>
					</li>
				</ul>
			</section>

			<section class="wphs-card">
				<div class="wphs-card-head">
					<h2><?php esc_html_e( 'Data retention', 'statwrite' ); ?></h2>
					<span class="wphs-muted"><?php esc_html_e( 'On-site storage only', 'statwrite' ); ?></span>
				</div>
				<table class="wphs-field-table" role="presentation">
					<tr>
						<th scope="row"><label for="wphs-detail"><?php esc_html_e( 'Per-visit details', 'statwrite' ); ?></label></th>
						<td>
							<select id="wphs-detail" name="retention_detail_days">
								<?php foreach ( array( 7, 14, 30, 60, 90 ) as $days ) : ?>
									<option value="<?php echo esc_attr( $days ); ?>" <?php selected( (int) $settings['retention_detail_days'], $days ); ?>><?php echo esc_html( $day_label( $days ) ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wphs-daily"><?php esc_html_e( 'Daily reports', 'statwrite' ); ?></label></th>
						<td>
							<select id="wphs-daily" name="retention_daily_days">
								<?php foreach ( array( 30, 90, 180, 365 ) as $days ) : ?>
									<option value="<?php echo esc_attr( $days ); ?>" <?php selected( (int) $settings['retention_daily_days'], $days ); ?>><?php echo esc_html( $day_label( $days ) ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wphs-totals"><?php esc_html_e( 'Daily totals', 'statwrite' ); ?></label></th>
						<td>
							<select id="wphs-totals" name="retention_totals_months">
								<?php foreach ( array( 0, 6, 12, 24, 36, 60 ) as $months ) : ?>
									<option value="<?php echo esc_attr( $months ); ?>" <?php selected( (int) $settings['retention_totals_months'], $months ); ?>><?php echo esc_html( $month_label( $months ) ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				</table>
				<p class="wphs-hint"><?php esc_html_e( 'Older data is summarised step by step: per-visit details become daily reports, and daily reports become daily totals.', 'statwrite' ); ?></p>
			</section>
		</div>

		<details class="wphs-card wphs-details-card" style="margin-top:14px">
			<summary><h2><?php esc_html_e( 'Advanced', 'statwrite' ); ?></h2></summary>
			<ul class="wphs-check-list">
				<li>
					<label><input type="checkbox" name="delete_on_uninstall" value="1" <?php checked( ! empty( $settings['delete_on_uninstall'] ) ); ?>> <?php esc_html_e( 'Delete all Statwrite data when the plugin is deleted', 'statwrite' ); ?></label>
					<p class="wphs-hint"><?php esc_html_e( 'Off by default, so reinstalling keeps your history.', 'statwrite' ); ?></p>
				</li>
			</ul>
		</details>

		<div class="wphs-sticky-save">
			<button type="submit" class="wphs-btn is-primary"><?php esc_html_e( 'Save settings', 'statwrite' ); ?></button>
			<a class="wphs-btn is-secondary" href="<?php echo esc_url( $writer ); ?>"><?php esc_html_e( 'AI Writer', 'statwrite' ); ?></a>
		</div>
	</form>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="wphs-test-form">
		<input type="hidden" name="action" value="wphs_cloud_test">
		<?php wp_nonce_field( 'wphs_cloud_test' ); ?>
	</form>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="wphs-disconnect-form" data-wphs-confirm="<?php esc_attr_e( 'Remove the site key and disconnect from the console?', 'statwrite' ); ?>">
		<input type="hidden" name="action" value="wphs_disconnect">
		<?php wp_nonce_field( 'wphs_disconnect' ); ?>
	</form>

	<details class="wphs-card wphs-details-card">
		<summary><h2><?php esc_html_e( 'Diagnostics', 'statwrite' ); ?></h2></summary>
		<?php if ( 'local' === $mode ) : ?>
			<div class="wphs-status-grid">
				<div class="wphs-status-item">
					<div class="wphs-status-label"><?php esc_html_e( 'Scheduled processing', 'statwrite' ); ?></div>
					<div class="wphs-status-value <?php echo $next ? 'is-ok' : 'is-warn'; ?>">
						<?php echo $next ? esc_html__( 'Active — ', 'statwrite' ) . esc_html( $next_txt ) : esc_html__( 'Disabled', 'statwrite' ); ?>
					</div>
				</div>
				<div class="wphs-status-item">
					<div class="wphs-status-label"><?php esc_html_e( 'Last processed', 'statwrite' ); ?></div>
					<div class="wphs-status-value"><?php echo esc_html( $last_run_txt ); ?></div>
				</div>
				<div class="wphs-status-item">
					<div class="wphs-status-label"><?php esc_html_e( 'Current queue', 'statwrite' ); ?></div>
					<div class="wphs-status-value"><?php echo esc_html( size_format( $spool, 1 ) ); ?> <?php esc_html_e( 'in', 'statwrite' ); ?> <?php echo esc_html( number_format_i18n( count( $files ) ) ); ?> <?php esc_html_e( 'files', 'statwrite' ); ?></div>
				</div>
				<div class="wphs-status-item">
					<div class="wphs-status-label"><?php esc_html_e( 'Queue directory', 'statwrite' ); ?></div>
					<div class="wphs-status-value <?php echo $dir_ok ? 'is-ok' : 'is-warn'; ?>"><?php echo $dir_ok ? esc_html__( 'Writable', 'statwrite' ) : esc_html__( 'Not writable', 'statwrite' ); ?></div>
				</div>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wphs-actions-row" style="margin-top:14px">
				<input type="hidden" name="action" value="wphs_flush">
				<?php wp_nonce_field( 'wphs_flush' ); ?>
				<button type="submit" class="wphs-btn is-secondary"><?php esc_html_e( 'Process queue manually', 'statwrite' ); ?></button>
			</form>

			<p class="wphs-hint"><?php esc_html_e( 'If you use full-page caching, clear it once after activation so the tracker is included in cached pages.', 'statwrite' ); ?></p>
			<p class="wphs-hint"><?php esc_html_e( 'If DISABLE_WP_CRON is enabled, set a real server cron for wphs_aggregate or use the manual processing button.', 'statwrite' ); ?></p>
		<?php else : ?>
			<div class="wphs-status-grid">
				<div class="wphs-status-item">
					<div class="wphs-status-label"><?php esc_html_e( 'Queued events', 'statwrite' ); ?></div>
					<div class="wphs-status-value"><?php echo esc_html( number_format_i18n( $cloud['pending'] ) . ' — ' . size_format( $cloud['spool_size'], 1 ) ); ?></div>
				</div>
				<div class="wphs-status-item">
					<div class="wphs-status-label"><?php esc_html_e( 'Last successful sync', 'statwrite' ); ?></div>
					<div class="wphs-status-value <?php echo $cloud['last_success'] > 0 ? 'is-ok' : ''; ?>"><?php echo esc_html( $cloud_last_success ); ?></div>
				</div>
				<div class="wphs-status-item">
					<div class="wphs-status-label"><?php esc_html_e( 'Last error', 'statwrite' ); ?></div>
					<div class="wphs-status-value <?php echo '' !== $cloud['last_error'] ? 'is-warn' : ''; ?>"><?php echo '' !== $cloud['last_error'] ? esc_html( $cloud['last_error'] ) : '—'; ?></div>
				</div>
				<div class="wphs-status-item">
					<div class="wphs-status-label"><?php esc_html_e( 'Next attempt / schedule', 'statwrite' ); ?></div>
					<div class="wphs-status-value"><?php echo esc_html( $cloud_next_txt ); ?> — <?php echo esc_html( $cloud_cron_txt ); ?></div>
				</div>
				<div class="wphs-status-item">
					<div class="wphs-status-label"><?php esc_html_e( 'Queue directory', 'statwrite' ); ?></div>
					<div class="wphs-status-value <?php echo $dir_ok ? 'is-ok' : 'is-warn'; ?>"><?php echo $dir_ok ? esc_html__( 'Writable', 'statwrite' ) : esc_html__( 'Not writable', 'statwrite' ); ?></div>
				</div>
			</div>

			<div class="wphs-actions-row" style="margin-top:14px">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="wphs_cloud_sync">
					<?php wp_nonce_field( 'wphs_cloud_sync' ); ?>
					<button type="submit" class="wphs-btn is-secondary"><?php esc_html_e( 'Send queue now', 'statwrite' ); ?></button>
				</form>
				<?php if ( $cloud['local_rows'] > 0 ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-wphs-confirm="<?php esc_attr_e( 'Purge local analytics data? This cannot be undone.', 'statwrite' ); ?>">
						<input type="hidden" name="action" value="wphs_purge_local">
						<?php wp_nonce_field( 'wphs_purge_local' ); ?>
						<button type="submit" class="wphs-btn is-danger"><?php esc_html_e( 'Purge old local data', 'statwrite' ); ?></button>
					</form>
				<?php endif; ?>
			</div>

			<p class="wphs-hint"><?php esc_html_e( 'If DISABLE_WP_CRON is enabled, set a real server cron for wphs_cloud_sync or use the send-now button.', 'statwrite' ); ?></p>
		<?php endif; ?>
	</details>
</div>
