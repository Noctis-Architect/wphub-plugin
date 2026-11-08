<?php
defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are local to the admin method that requires this file.

$settings_url = add_query_arg( array( 'page' => 'hub-pilot-settings' ), admin_url( 'admin.php' ) );
$notice       = get_transient( 'wphs_cloud_notice' );

if ( $notice ) {
	delete_transient( 'wphs_cloud_notice' );
}

$token_set      = ! empty( $token_set );
$overview       = isset( $overview ) && is_array( $overview ) ? $overview : array();
$overview_error = isset( $overview_error ) ? (string) $overview_error : '';
$overview_stale = ! empty( $overview_stale );

$console_analytics = isset( $overview['analytics'] ) && is_array( $overview['analytics'] ) ? $overview['analytics'] : null;
$ca_sessions       = $console_analytics ? (int) ( $console_analytics['sessions'] ?? 0 ) : 0;
$ca_bounce         = 0;
$ca_avg            = 0;
$ca_days           = $console_analytics ? max( 1, (int) ( $console_analytics['range_days'] ?? 7 ) ) : 7;

if ( $console_analytics ) {
	if ( isset( $console_analytics['bounce_rate'] ) && is_numeric( $console_analytics['bounce_rate'] ) ) {
		$ca_bounce = (float) $console_analytics['bounce_rate'];
	} elseif ( $ca_sessions > 0 ) {
		$ca_bounce = ( (float) ( $console_analytics['bounces'] ?? 0 ) / $ca_sessions ) * 100;
	}

	if ( isset( $console_analytics['avg_engaged'] ) && is_numeric( $console_analytics['avg_engaged'] ) ) {
		$ca_avg = (float) $console_analytics['avg_engaged'];
	} elseif ( $ca_sessions > 0 ) {
		$ca_avg = (float) ( $console_analytics['engaged'] ?? 0 ) / $ca_sessions;
	}
}

/* translators: %s: time difference, for example "5 minutes". */
$last_success = $status['last_success'] > 0 ? sprintf( __( '%s ago', 'hub-pilot' ), human_time_diff( $status['last_success'], time() ) ) : __( 'Not yet', 'hub-pilot' );
/* translators: %s: time difference, for example "5 minutes". */
$last_attempt = $status['last_attempt'] > 0 ? sprintf( __( '%s ago', 'hub-pilot' ), human_time_diff( $status['last_attempt'], time() ) ) : __( 'No attempts yet', 'hub-pilot' );
/* translators: %s: time difference until the next attempt. */
$next_attempt = $status['next_attempt'] > time() ? sprintf( __( 'in about %s', 'hub-pilot' ), human_time_diff( time(), $status['next_attempt'] ) ) : '—';
$cron_next    = $status['cron_next'] > time() ? wp_date( 'Y/m/d H:i', $status['cron_next'] ) : __( 'Waiting to run', 'hub-pilot' );
$connected    = $status['token_set'] && '' === $status['last_error'];
?>
<div class="wrap wphs" dir="<?php echo is_rtl() ? 'rtl' : 'ltr'; ?>">
	<header class="wphs-hero">
		<div class="wphs-hero-brand">
			<span class="wphs-logo"><img src="<?php echo esc_url( WPHS_URL . 'assets/logo.png' ); ?>" alt="" width="38" height="38"></span>
			<div>
				<h1 class="wphs-title"><?php esc_html_e( 'HubPilot Dashboard', 'hub-pilot' ); ?></h1>
				<p class="wphs-sub"><?php esc_html_e( 'Cloud mode — analytics data is not stored on this site', 'hub-pilot' ); ?></p>
			</div>
		</div>
		<div class="wphs-hero-side">
			<span class="wphs-live<?php echo $connected ? ' is-on' : ''; ?>"><i></i><?php echo $status['token_set'] ? ( $connected ? esc_html__( 'Connection ready', 'hub-pilot' ) : esc_html__( 'Needs review', 'hub-pilot' ) ) : esc_html__( 'Not configured', 'hub-pilot' ); ?></span>
			<a class="wphs-btn is-ghost" href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'Settings', 'hub-pilot' ); ?></a>
		</div>
	</header>

	<?php if ( $notice ) : ?>
		<div class="wphs-notice<?php echo 'success' === $notice['type'] ? ' is-success' : ' is-error'; ?>"><?php echo esc_html( $notice['message'] ); ?></div>
	<?php endif; ?>

	<?php if ( ! $status['token_set'] ) : ?>
		<div class="wphs-notice is-error"><?php esc_html_e( 'The site connection key is not set. Nothing is sent to the console until you enter the key in Settings.', 'hub-pilot' ); ?></div>
	<?php endif; ?>

	<?php if ( $token_set ) : ?>
		<?php require WPHS_DIR . 'admin/views/site-status.php'; ?>
	<?php endif; ?>

	<?php if ( $console_analytics ) : ?>
		<section class="wphs-card">
			<div class="wphs-card-head">
				<h2><?php esc_html_e( 'Console analytics', 'hub-pilot' ); ?></h2>
				<span class="wphs-muted">
				<?php
				/* translators: %s: number of days. */
				echo esc_html( sprintf( _n( 'Last %s day', 'Last %s days', $ca_days, 'hub-pilot' ), number_format_i18n( $ca_days ) ) );
				?>
				</span>
			</div>
			<div class="wphs-console-stats">
				<div><span><?php esc_html_e( 'Pageviews', 'hub-pilot' ); ?></span><strong><?php echo esc_html( WPHS_Reports::int( $console_analytics['pageviews'] ?? 0 ) ); ?></strong></div>
				<div><span><?php esc_html_e( 'Visitors', 'hub-pilot' ); ?></span><strong><?php echo esc_html( WPHS_Reports::int( $console_analytics['visitors'] ?? 0 ) ); ?></strong></div>
				<div><span><?php esc_html_e( 'Sessions', 'hub-pilot' ); ?></span><strong><?php echo esc_html( WPHS_Reports::int( $console_analytics['sessions'] ?? 0 ) ); ?></strong></div>
				<div><span><?php esc_html_e( 'Bounce rate', 'hub-pilot' ); ?></span><strong><?php echo esc_html( number_format_i18n( $ca_bounce, 0 ) . '%' ); ?></strong></div>
				<div><span><?php esc_html_e( 'Avg. engaged time', 'hub-pilot' ); ?></span><strong><?php echo esc_html( WPHS_Reports::duration( $ca_avg ) ); ?></strong></div>
			</div>
			<p class="wphs-table-note"><?php esc_html_e( 'Only visits with a detected browser are shown; see the console analytics dashboard for other modes.', 'hub-pilot' ); ?></p>
		</section>
	<?php endif; ?>

	<section class="wphs-kpis">
		<div class="wphs-kpi">
			<div class="wphs-kpi-top"><span class="wphs-kpi-label"><?php esc_html_e( 'Queued', 'hub-pilot' ); ?></span></div>
			<div class="wphs-kpi-value"><?php echo esc_html( WPHS_Reports::int( $status['pending'] ) ); ?></div>
			<div class="wphs-kpi-foot"><span>
			<?php
			/* translators: %1$s: data size; %2$s: number of files. */
			echo esc_html( sprintf( __( '%1$s in %2$s files', 'hub-pilot' ), size_format( $status['spool_size'], 1 ), number_format_i18n( $status['files'] ) ) );
			?>
			</span></div>
		</div>
		<div class="wphs-kpi">
			<div class="wphs-kpi-top"><span class="wphs-kpi-label"><?php esc_html_e( 'Last successful sync', 'hub-pilot' ); ?></span></div>
			<div class="wphs-kpi-value is-compact"><?php echo esc_html( $last_success ); ?></div>
			<div class="wphs-kpi-foot"><span><?php esc_html_e( 'Last attempt:', 'hub-pilot' ); ?> <?php echo esc_html( $last_attempt ); ?></span></div>
		</div>
		<div class="wphs-kpi">
			<div class="wphs-kpi-top"><span class="wphs-kpi-label"><?php esc_html_e( 'Consecutive failures', 'hub-pilot' ); ?></span></div>
			<div class="wphs-kpi-value"><?php echo esc_html( WPHS_Reports::int( $status['failures'] ) ); ?></div>
			<div class="wphs-kpi-foot"><span><?php esc_html_e( 'Next attempt:', 'hub-pilot' ); ?> <?php echo esc_html( $next_attempt ); ?></span></div>
		</div>
	</section>

	<section class="wphs-card">
		<div class="wphs-card-head">
			<h2><?php esc_html_e( 'Connection status', 'hub-pilot' ); ?></h2>
			<a class="wphs-muted" href="<?php echo esc_url( $status['console_url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open console', 'hub-pilot' ); ?></a>
		</div>
		<div class="wphs-status-grid">
			<div class="wphs-status-item">
				<div class="wphs-status-label"><?php esc_html_e( 'Connection key', 'hub-pilot' ); ?></div>
				<div class="wphs-status-value <?php echo $status['token_set'] ? 'is-ok' : 'is-warn'; ?>"><?php echo $status['token_set'] ? esc_html__( 'Configured', 'hub-pilot' ) : esc_html__( 'Not configured', 'hub-pilot' ); ?></div>
			</div>
			<div class="wphs-status-item">
				<div class="wphs-status-label"><?php esc_html_e( 'Last batch', 'hub-pilot' ); ?></div>
				<div class="wphs-status-value">
				<?php
				/* translators: %1$s: number of events; %2$s: HTTP status code. */
				echo $status['last_count'] > 0 ? esc_html( sprintf( __( '%1$s events — HTTP %2$s', 'hub-pilot' ), number_format_i18n( $status['last_count'] ), $status['last_http'] ) ) : '—';
				?>
				</div>
			</div>
			<div class="wphs-status-item">
				<div class="wphs-status-label"><?php esc_html_e( 'Sync schedule', 'hub-pilot' ); ?></div>
				<div class="wphs-status-value"><?php echo esc_html( $cron_next ); ?></div>
			</div>
			<div class="wphs-status-item">
				<div class="wphs-status-label"><?php esc_html_e( 'Last error', 'hub-pilot' ); ?></div>
				<div class="wphs-status-value <?php echo '' !== $status['last_error'] ? 'is-warn' : ''; ?>"><?php echo '' !== $status['last_error'] ? esc_html( $status['last_error'] ) : '—'; ?></div>
			</div>
			<div class="wphs-status-item">
				<div class="wphs-status-label"><?php esc_html_e( 'Old local data', 'hub-pilot' ); ?></div>
				<div class="wphs-status-value">
				<?php
				/* translators: %s: number of days. */
				echo esc_html( sprintf( _n( '%s summary day', '%s summary days', (int) $status['local_rows'], 'hub-pilot' ), number_format_i18n( $status['local_rows'] ) ) );
				?>
				</div>
			</div>
		</div>

		<p class="wphs-hint"><?php esc_html_e( 'In Cloud mode, data is only placed in a temporary queue and removed from the site after a successful sync. If the console is unavailable, the site and visitors are unaffected and sending is retried later.', 'hub-pilot' ); ?></p>
	</section>

	<section class="wphs-card">
		<div class="wphs-card-head">
			<h2><?php esc_html_e( 'Actions', 'hub-pilot' ); ?></h2>
			<span class="wphs-muted"><?php esc_html_e( 'Manual', 'hub-pilot' ); ?></span>
		</div>
		<div class="wphs-actions-row">
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
			<?php if ( $status['local_rows'] > 0 ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm(<?php echo esc_attr( wp_json_encode( __( 'Purge local analytics data? This cannot be undone.', 'hub-pilot' ) ) ); ?>);">
					<input type="hidden" name="action" value="wphs_purge_local">
					<?php wp_nonce_field( 'wphs_purge_local' ); ?>
					<?php submit_button( __( 'Purge local data', 'hub-pilot' ), 'delete', 'submit', false ); ?>
				</form>
			<?php endif; ?>
		</div>
	</section>

	<?php require WPHS_DIR . 'admin/views/security-check.php'; ?>

	<div class="wphs-footer">
		<span><?php esc_html_e( 'Storage mode: Cloud', 'hub-pilot' ); ?></span>
		<a href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'Settings and connection key', 'hub-pilot' ); ?></a>
	</div>
</div>
