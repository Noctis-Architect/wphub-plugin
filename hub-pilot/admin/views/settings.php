<?php
defined( 'ABSPATH' ) || exit;

$next         = wp_next_scheduled( WPHS_Aggregator::EVENT );
$last_run     = (int) get_option( 'wphs_last_run', 0 );
$spool        = WPHS_Spool::size();
$files        = WPHS_Spool::files();
$dashboard    = add_query_arg( array( 'page' => 'hub-pilot' ), admin_url( 'admin.php' ) );
/* translators: %s: time difference, for example "5 minutes". */
$last_run_txt = $last_run > 0 ? sprintf( __( '%s ago', 'hub-pilot' ), human_time_diff( $last_run, time() ) ) : __( 'Not yet', 'hub-pilot' );
$next_txt     = $next && $next > time() ? wp_date( 'Y/m/d H:i', $next ) : __( 'Waiting to run', 'hub-pilot' );
$dir_ok       = is_dir( WPHS_Spool::dir() ) && wp_is_writable( WPHS_Spool::dir() );
// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Local template variable.
$mode         = WPHS_Settings::mode();
$cloud        = WPHS_Cloud::status();
$cloud_notice = get_transient( 'wphs_cloud_notice' );

if ( $cloud_notice ) {
	delete_transient( 'wphs_cloud_notice' );
}

/* translators: %s: time difference, for example "5 minutes". */
$cloud_last_success = $cloud['last_success'] > 0 ? sprintf( __( '%s ago', 'hub-pilot' ), human_time_diff( $cloud['last_success'], time() ) ) : '—';
/* translators: %s: time difference until the next attempt. */
$cloud_next_txt     = $cloud['next_attempt'] > time() ? sprintf( __( 'in about %s', 'hub-pilot' ), human_time_diff( time(), $cloud['next_attempt'] ) ) : '—';
$cloud_cron_txt     = $cloud['cron_next'] > time() ? wp_date( 'Y/m/d H:i', $cloud['cron_next'] ) : __( 'Waiting to run', 'hub-pilot' );
?>
<div class="wrap wphs" dir="<?php echo is_rtl() ? 'rtl' : 'ltr'; ?>">
	<header class="wphs-hero">
		<div class="wphs-hero-brand">
			<span class="wphs-logo"><img src="<?php echo esc_url( WPHS_URL . 'assets/logo.png' ); ?>" alt="" width="38" height="38"></span>
			<div>
				<h1 class="wphs-title"><?php esc_html_e( 'HubPilot Settings', 'hub-pilot' ); ?></h1>
				<p class="wphs-sub"><?php esc_html_e( 'Data retention, privacy, and processing status', 'hub-pilot' ); ?></p>
			</div>
		</div>
		<div class="wphs-hero-side">
			<a class="wphs-btn is-ghost" href="<?php echo esc_url( $dashboard ); ?>"><?php esc_html_e( 'Back to dashboard', 'hub-pilot' ); ?></a>
		</div>
	</header>

	<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display flag. ?>
	<?php if ( isset( $_GET['updated'] ) ) : ?>
		<div class="wphs-notice is-success"><?php esc_html_e( 'Settings saved.', 'hub-pilot' ); ?></div>
	<?php endif; ?>

	<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display flag. ?>
	<?php if ( isset( $_GET['flushed'] ) ) : ?>
		<div class="wphs-notice is-success"><?php esc_html_e( 'Queue processed; existing data was added to analytics.', 'hub-pilot' ); ?></div>
	<?php endif; ?>

	<?php if ( $cloud_notice ) : ?>
		<div class="wphs-notice<?php echo 'success' === $cloud_notice['type'] ? ' is-success' : ' is-error'; ?>"><?php echo esc_html( $cloud_notice['message'] ); ?></div>
	<?php endif; ?>

	<?php if ( 'cloud' === $mode && ! $cloud['token_set'] ) : ?>
		<div class="wphs-notice is-error"><?php esc_html_e( 'The site connection key is not set; in Cloud mode, no data is sent to the console until you enter it.', 'hub-pilot' ); ?></div>
	<?php endif; ?>

	<section class="wphs-card">
		<div class="wphs-card-head">
			<h2><?php esc_html_e( 'Three data retention levels', 'hub-pilot' ); ?></h2>
			<span class="wphs-muted"><?php esc_html_e( 'Keeps the database lean', 'hub-pilot' ); ?></span>
		</div>
		<p class="wphs-hint"><?php esc_html_e( 'Data is retained in tiers; after each level expires, only the next level remains.', 'hub-pilot' ); ?></p>
		<div class="wphs-table-wrap">
			<table class="wphs-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Level', 'hub-pilot' ); ?></th>
						<th><?php esc_html_e( 'Includes', 'hub-pilot' ); ?></th>
						<th class="num"><?php esc_html_e( 'Default', 'hub-pilot' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td><?php esc_html_e( 'Detailed data', 'hub-pilot' ); ?></td>
						<td><?php esc_html_e( 'Per-visit tracking, hourly table, sessions, and events', 'hub-pilot' ); ?></td>
						<td class="num"><?php esc_html_e( '30 days', 'hub-pilot' ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Daily data', 'hub-pilot' ); ?></td>
						<td><?php esc_html_e( 'Page, source, device, and browser breakdown by day', 'hub-pilot' ); ?></td>
						<td class="num"><?php esc_html_e( '180 days', 'hub-pilot' ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Daily totals', 'hub-pilot' ); ?></td>
						<td><?php esc_html_e( 'Pageviews, visitors, sessions, bounces, and engaged time without page or source breakdown', 'hub-pilot' ); ?></td>
						<td class="num"><?php esc_html_e( '24 months', 'hub-pilot' ); ?></td>
					</tr>
				</tbody>
			</table>
		</div>
	</section>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wphs-form">
		<input type="hidden" name="action" value="wphs_save_settings">
		<?php wp_nonce_field( 'wphs_save_settings' ); ?>

		<section class="wphs-card">
			<div class="wphs-card-head">
				<h2><?php esc_html_e( 'Storage mode', 'hub-pilot' ); ?></h2>
				<span class="wphs-muted"><?php esc_html_e( 'Local or cloud', 'hub-pilot' ); ?></span>
			</div>
			<div class="wphs-mode-grid">
				<label class="wphs-mode-card">
					<input type="radio" name="storage_mode" value="local" <?php checked( $settings['storage_mode'], 'local' ); ?>>
					<span class="wphs-mode-title"><?php esc_html_e( 'Local', 'hub-pilot' ); ?></span>
					<span class="wphs-mode-desc"><?php esc_html_e( 'Data is stored in this site\'s database and the full local dashboard is available.', 'hub-pilot' ); ?></span>
				</label>
				<label class="wphs-mode-card">
					<input type="radio" name="storage_mode" value="cloud" <?php checked( $settings['storage_mode'], 'cloud' ); ?>>
					<span class="wphs-mode-title"><?php esc_html_e( 'Cloud', 'hub-pilot' ); ?></span>
					<span class="wphs-mode-desc"><?php esc_html_e( 'No analytics data is stored in the site database; a temporary queue is sent to the console at most every five minutes.', 'hub-pilot' ); ?></span>
				</label>
			</div>
			<p class="wphs-hint"><?php esc_html_e( 'In Cloud mode, the temporary queue is cleared after a successful sync. If the console is unavailable, the site and its visitors are unaffected.', 'hub-pilot' ); ?></p>
		</section>

		<section class="wphs-card wphs-promo">
			<div class="wphs-promo-copy">
				<span class="wphs-promo-eyebrow"><?php esc_html_e( 'WP-Hub console', 'hub-pilot' ); ?></span>
				<strong><?php esc_html_e( 'Analytics, speed, security, and monitoring in one dashboard', 'hub-pilot' ); ?></strong>
				<p><?php esc_html_e( 'For full reports, speed tests, security checks, and monitoring, get a site key from the console. The console URL is fixed in the plugin and does not need to be entered.', 'hub-pilot' ); ?></p>
			</div>
			<a class="wphs-btn is-primary" href="<?php echo esc_url( WPHS_Settings::console_url() ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Get key from console', 'hub-pilot' ); ?></a>
		</section>

		<section class="wphs-card" id="wphs-cloud-settings">
			<div class="wphs-card-head">
				<h2><?php esc_html_e( 'Console connection', 'hub-pilot' ); ?></h2>
				<span class="wphs-muted"><?php esc_html_e( 'Cloud mode only', 'hub-pilot' ); ?></span>
			</div>
			<table class="wphs-field-table" role="presentation">
				<tr>
					<th scope="row"><label for="wphs-console-token"><?php esc_html_e( 'Site connection key', 'hub-pilot' ); ?></label></th>
					<td>
						<input type="text" id="wphs-console-token" name="console_token" value="<?php echo esc_attr( $settings['console_token'] ); ?>" placeholder="wphs_..." dir="ltr" autocomplete="off" spellcheck="false">
						<p class="wphs-hint"><?php esc_html_e( 'Get the site\'s dedicated key from the console and enter it here. The console URL is fixed in the plugin; requests use Bearer authentication and an HMAC signature.', 'hub-pilot' ); ?></p>
					</td>
				</tr>
			</table>
		</section>

		<section class="wphs-card">
			<div class="wphs-card-head">
				<h2><?php esc_html_e( 'Data retention', 'hub-pilot' ); ?></h2>
				<span class="wphs-muted"><?php esc_html_e( 'Configurable ranges', 'hub-pilot' ); ?></span>
			</div>
			<table class="wphs-field-table" role="presentation">
				<tr>
					<th scope="row"><label for="wphs-detail"><?php esc_html_e( 'Detailed data', 'hub-pilot' ); ?></label></th>
					<td>
						<select id="wphs-detail" name="retention_detail_days">
							<?php foreach ( array( 7, 14, 30, 60, 90 ) as $days ) : ?>
								<option value="
								<?php echo esc_attr( $days ); ?>" <?php selected( (int) $settings['retention_detail_days'], $days ); ?>><?php
								/* translators: %s: number of days. */
								echo esc_html( sprintf( _n( '%s day', '%s days', $days, 'hub-pilot' ), number_format_i18n( $days ) ) );
								?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="wphs-hint"><?php esc_html_e( 'After this period, per-visit tracking, hourly stats, sessions, and events are deleted.', 'hub-pilot' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wphs-daily"><?php esc_html_e( 'Daily data', 'hub-pilot' ); ?></label></th>
					<td>
						<select id="wphs-daily" name="retention_daily_days">
							<?php foreach ( array( 30, 90, 180, 365 ) as $days ) : ?>
								<option value="
								<?php echo esc_attr( $days ); ?>" <?php selected( (int) $settings['retention_daily_days'], $days ); ?>><?php
								/* translators: %s: number of days. */
								echo esc_html( sprintf( _n( '%s day', '%s days', $days, 'hub-pilot' ), number_format_i18n( $days ) ) );
								?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="wphs-hint"><?php esc_html_e( 'After this period, page, source, device, and browser breakdowns are deleted and only daily totals remain.', 'hub-pilot' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wphs-totals"><?php esc_html_e( 'Daily totals', 'hub-pilot' ); ?></label></th>
					<td>
						<select id="wphs-totals" name="retention_totals_months">
							<?php foreach ( array( 0, 6, 12, 24, 36, 60 ) as $months ) : ?>
								<option value="
								<?php echo esc_attr( $months ); ?>" <?php selected( (int) $settings['retention_totals_months'], $months ); ?>><?php
								/* translators: %s: number of months. */
								echo 0 === $months ? esc_html__( 'Keep forever', 'hub-pilot' ) : esc_html( sprintf( _n( '%s month', '%s months', $months, 'hub-pilot' ), number_format_i18n( $months ) ) );
								?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="wphs-hint"><?php esc_html_e( 'Older data, including daily totals, is deleted after this period. "Keep forever" accepts very slow database growth.', 'hub-pilot' ); ?></p>
					</td>
				</tr>
			</table>
		</section>

		<section class="wphs-card">
			<div class="wphs-card-head">
				<h2><?php esc_html_e( 'Bot detection and traffic quality', 'hub-pilot' ); ?></h2>
				<span class="wphs-muted"><?php esc_html_e( 'User-Agent + browser signals', 'hub-pilot' ); ?></span>
			</div>
			<table class="wphs-field-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Browser verification', 'hub-pilot' ); ?></th>
					<td>
						<label><input type="checkbox" name="bot_js_check" value="1" <?php checked( ! empty( $settings['bot_js_check'] ) ); ?>> <?php esc_html_e( 'To count a visit as "verified", also check JavaScript execution and browser signals', 'hub-pilot' ); ?></label>
						<p class="wphs-hint"><?php esc_html_e( 'Known bots are filtered by User-Agent. Visits without browser verification appear in "No-JS", and rejected visits appear in "Suspect".', 'hub-pilot' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Send visitor IP to console', 'hub-pilot' ); ?></th>
					<td>
						<label><input type="checkbox" name="send_visitor_ip" value="1" <?php checked( ! empty( $settings['send_visitor_ip'] ) ); ?>> <?php esc_html_e( 'In Cloud mode, send the visitor IP to the console for datacenter and country detection', 'hub-pilot' ); ?></label>
						<p class="wphs-hint"><?php esc_html_e( 'The IP is sent only server-to-server over HTTPS; the console stores only the result (datacenter/country) and does not keep the raw IP. This option has no effect in Local mode.', 'hub-pilot' ); ?></p>
					</td>
				</tr>
			</table>
		</section>

		<section class="wphs-card">
			<div class="wphs-card-head">
				<h2><?php esc_html_e( 'Privacy and filtering', 'hub-pilot' ); ?></h2>
				<span class="wphs-muted"><?php esc_html_e( 'No raw IP storage', 'hub-pilot' ); ?></span>
			</div>
			<table class="wphs-field-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Logged-in users', 'hub-pilot' ); ?></th>
					<td>
						<label><input type="checkbox" name="track_logged_in" value="1" <?php checked( ! empty( $settings['track_logged_in'] ) ); ?>> <?php esc_html_e( 'Track logged-in users too', 'hub-pilot' ); ?></label>
						<p class="wphs-hint"><?php esc_html_e( 'By default, admin and logged-in user visits are not tracked.', 'hub-pilot' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Do Not Track', 'hub-pilot' ); ?></th>
					<td>
						<label><input type="checkbox" name="respect_dnt" value="1" <?php checked( ! empty( $settings['respect_dnt'] ) ); ?>> <?php esc_html_e( 'Respect visitors\' DNT / GPC requests', 'hub-pilot' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( '404 pages', 'hub-pilot' ); ?></th>
					<td>
						<label><input type="checkbox" name="exclude_404" value="1" <?php checked( ! empty( $settings['exclude_404'] ) ); ?>> <?php esc_html_e( 'Do not track 404 pages', 'hub-pilot' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Uninstall', 'hub-pilot' ); ?></th>
					<td>
						<label><input type="checkbox" name="delete_on_uninstall" value="1" <?php checked( ! empty( $settings['delete_on_uninstall'] ) ); ?>> <?php esc_html_e( 'Delete analytics tables on uninstall', 'hub-pilot' ); ?></label>
						<p class="wphs-hint"><?php esc_html_e( 'If disabled, data remains in the database after the plugin is uninstalled.', 'hub-pilot' ); ?></p>
					</td>
				</tr>
			</table>
		</section>

		<section class="wphs-card">
			<div class="wphs-card-head">
				<h2><?php esc_html_e( 'Appearance and notifications', 'hub-pilot' ); ?></h2>
				<span class="wphs-muted"><?php esc_html_e( 'Analytics pages only', 'hub-pilot' ); ?></span>
			</div>
			<table class="wphs-field-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'WordPress notices', 'hub-pilot' ); ?></th>
					<td>
						<label><input type="checkbox" name="hide_notices" value="1" <?php checked( ! empty( $settings['hide_notices'] ) ); ?>> <?php esc_html_e( 'Hide WordPress and other plugin notices on analytics pages', 'hub-pilot' ); ?></label>
						<p class="wphs-hint"><?php esc_html_e( 'This option only affects this plugin\'s dashboard and settings pages, not other admin pages.', 'hub-pilot' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Source credit', 'hub-pilot' ); ?></th>
					<td>
						<label><input type="checkbox" name="source_credit" value="1" <?php checked( ! empty( $settings['source_credit'] ) ); ?>> <?php esc_html_e( 'Show the WP-Hub name at the top of the page source', 'hub-pilot' ); ?></label>
						<p class="wphs-hint"><?php esc_html_e( 'This is only an HTML comment and does not affect the site\'s appearance or speed.', 'hub-pilot' ); ?></p>
					</td>
				</tr>
			</table>
		</section>

		<?php submit_button( __( 'Save settings', 'hub-pilot' ) ); ?>
	</form>

	<?php if ( 'local' === $mode ) : ?>
	<section class="wphs-card">
		<div class="wphs-card-head">
			<h2><?php esc_html_e( 'Processing status', 'hub-pilot' ); ?></h2>
			<span class="wphs-muted"><?php esc_html_e( 'Queue health and schedule', 'hub-pilot' ); ?></span>
		</div>
		<div class="wphs-status-grid">
			<div class="wphs-status-item">
				<div class="wphs-status-label"><?php esc_html_e( 'Scheduled processing', 'hub-pilot' ); ?></div>
				<div class="wphs-status-value <?php echo $next ? 'is-ok' : 'is-warn'; ?>">
					<?php echo $next ? esc_html__( 'Active — ', 'hub-pilot' ) . esc_html( $next_txt ) : esc_html__( 'Disabled', 'hub-pilot' ); ?>
				</div>
			</div>
			<div class="wphs-status-item">
				<div class="wphs-status-label"><?php esc_html_e( 'Last processed', 'hub-pilot' ); ?></div>
				<div class="wphs-status-value"><?php echo esc_html( $last_run_txt ); ?></div>
			</div>
			<div class="wphs-status-item">
				<div class="wphs-status-label"><?php esc_html_e( 'Current queue', 'hub-pilot' ); ?></div>
				<div class="wphs-status-value"><?php echo esc_html( size_format( $spool, 1 ) ); ?> <?php esc_html_e( 'in', 'hub-pilot' ); ?> <?php echo esc_html( number_format_i18n( count( $files ) ) ); ?> <?php esc_html_e( 'files', 'hub-pilot' ); ?></div>
			</div>
			<div class="wphs-status-item">
				<div class="wphs-status-label"><?php esc_html_e( 'Queue directory', 'hub-pilot' ); ?></div>
				<div class="wphs-status-value <?php echo $dir_ok ? 'is-ok' : 'is-warn'; ?>"><?php echo $dir_ok ? esc_html__( 'Writable', 'hub-pilot' ) : esc_html__( 'Not writable', 'hub-pilot' ); ?></div>
			</div>
		</div>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:14px">
			<input type="hidden" name="action" value="wphs_flush">
			<?php wp_nonce_field( 'wphs_flush' ); ?>
			<?php submit_button( __( 'Process queue manually', 'hub-pilot' ), 'secondary', 'submit', false ); ?>
		</form>

		<p class="wphs-hint"><?php esc_html_e( 'If you use full-page caching, clear it once after activation so the tracker is included in cached pages. Analytics are kept only on this site with no external requests.', 'hub-pilot' ); ?></p>
		<p class="wphs-hint"><?php esc_html_e( 'If DISABLE_WP_CRON is enabled, set a real server cron for wphs_aggregate or use the manual processing button.', 'hub-pilot' ); ?></p>
	</section>
	<?php else : ?>
	<section class="wphs-card">
		<div class="wphs-card-head">
			<h2><?php esc_html_e( 'Cloud sync status', 'hub-pilot' ); ?></h2>
			<span class="wphs-muted"><?php esc_html_e( 'No local storage', 'hub-pilot' ); ?></span>
		</div>
		<div class="wphs-status-grid">
			<div class="wphs-status-item">
				<div class="wphs-status-label"><?php esc_html_e( 'Queued events', 'hub-pilot' ); ?></div>
				<div class="wphs-status-value"><?php echo esc_html( number_format_i18n( $cloud['pending'] ) . ' ' . __( 'events', 'hub-pilot' ) . ' — ' . size_format( $cloud['spool_size'], 1 ) ); ?></div>
			</div>
			<div class="wphs-status-item">
				<div class="wphs-status-label"><?php esc_html_e( 'Last successful sync', 'hub-pilot' ); ?></div>
				<div class="wphs-status-value <?php echo $cloud['last_success'] > 0 ? 'is-ok' : ''; ?>"><?php echo esc_html( $cloud_last_success ); ?></div>
			</div>
			<div class="wphs-status-item">
				<div class="wphs-status-label"><?php esc_html_e( 'Last error', 'hub-pilot' ); ?></div>
				<div class="wphs-status-value <?php echo '' !== $cloud['last_error'] ? 'is-warn' : ''; ?>"><?php echo '' !== $cloud['last_error'] ? esc_html( $cloud['last_error'] ) : '—'; ?></div>
			</div>
			<div class="wphs-status-item">
				<div class="wphs-status-label"><?php esc_html_e( 'Next attempt / schedule', 'hub-pilot' ); ?></div>
				<div class="wphs-status-value"><?php echo esc_html( $cloud_next_txt ); ?> — <?php echo esc_html( $cloud_cron_txt ); ?></div>
			</div>
			<div class="wphs-status-item">
				<div class="wphs-status-label"><?php esc_html_e( 'Old local data', 'hub-pilot' ); ?></div>
				<div class="wphs-status-value"><?php echo esc_html( number_format_i18n( $cloud['local_rows'] ) . ' ' . __( 'summary days', 'hub-pilot' ) ); ?></div>
			</div>
			<div class="wphs-status-item">
				<div class="wphs-status-label"><?php esc_html_e( 'Queue directory', 'hub-pilot' ); ?></div>
				<div class="wphs-status-value <?php echo $dir_ok ? 'is-ok' : 'is-warn'; ?>"><?php echo $dir_ok ? esc_html__( 'Writable', 'hub-pilot' ) : esc_html__( 'Not writable', 'hub-pilot' ); ?></div>
			</div>
		</div>

		<div class="wphs-actions-row" style="margin-top:14px">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="wphs_cloud_sync">
				<?php wp_nonce_field( 'wphs_cloud_sync' ); ?>
				<?php submit_button( __( 'Send queue now', 'hub-pilot' ), 'primary', 'submit', false ); ?>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="wphs_cloud_test">
				<?php wp_nonce_field( 'wphs_cloud_test' ); ?>
				<?php submit_button( __( 'Test connection', 'hub-pilot' ), 'secondary', 'submit', false ); ?>
			</form>
			<?php if ( $cloud['local_rows'] > 0 ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm(<?php echo esc_attr( wp_json_encode( __( 'Purge local analytics data? This cannot be undone.', 'hub-pilot' ) ) ); ?>);">
					<input type="hidden" name="action" value="wphs_purge_local">
					<?php wp_nonce_field( 'wphs_purge_local' ); ?>
					<?php submit_button( __( 'Purge local data', 'hub-pilot' ), 'delete', 'submit', false ); ?>
				</form>
			<?php endif; ?>
		</div>

		<p class="wphs-hint"><?php esc_html_e( 'In Cloud mode, no analytics data is written to the site database; only a temporary queue file is used. Events are removed from the queue after a successful sync.', 'hub-pilot' ); ?></p>
		<p class="wphs-hint"><?php esc_html_e( 'If DISABLE_WP_CRON is enabled, set a real server cron for wphs_cloud_sync or use the send-now button.', 'hub-pilot' ); ?></p>
	</section>
	<?php endif; ?>

	<script>
	(function () {
		var card = document.getElementById('wphs-cloud-settings');
		if (!card) return;
		var radios = document.querySelectorAll('input[name="storage_mode"]');
		function toggle() {
			var checked = document.querySelector('input[name="storage_mode"]:checked');
			card.style.display = checked && checked.value === 'cloud' ? '' : 'none';
		}
		Array.prototype.forEach.call(radios, function (radio) {
			radio.addEventListener('change', toggle);
		});
		toggle();
	})();
	</script>
</div>
