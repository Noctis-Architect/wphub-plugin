<?php
defined( 'ABSPATH' ) || exit;

$t            = $data['totals'];
$prev         = $data['prev'];
$changes      = $data['changes'];
$range        = (int) $data['days'];
// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Local template variable.
$mode         = $data['mode'];
$mode_label   = $data['mode_label'];
$mode_hint    = $data['mode_hint'];
$mode_options = $data['mode_options'];
$quality      = $data['quality'];
$ranges       = array(
	1  => __( 'Today', 'traffic-analytics' ),
	/* translators: %s: number of days. */
	7  => sprintf( _n( '%s day', '%s days', 7, 'traffic-analytics' ), number_format_i18n( 7 ) ),
	/* translators: %s: number of days. */
	30 => sprintf( _n( '%s day', '%s days', 30, 'traffic-analytics' ), number_format_i18n( 30 ) ),
	/* translators: %s: number of days. */
	90 => sprintf( _n( '%s day', '%s days', 90, 'traffic-analytics' ), number_format_i18n( 90 ) ),
);
$next         = wp_next_scheduled( WPHS_Aggregator::EVENT );
$settings_url = add_query_arg( array( 'page' => 'wphub-analytics-settings' ), admin_url( 'admin.php' ) );
$refresh_url  = add_query_arg(
	array(
		'page'  => 'wphub-analytics',
		'range' => $range,
		'mode'  => $mode,
	),
	admin_url( 'admin.php' )
);
$report_url   = static function ( $days, $report_mode ) {
	return add_query_arg(
		array(
			'page'  => 'wphub-analytics',
			'range' => $days,
			'mode'  => $report_mode,
		),
		admin_url( 'admin.php' )
	);
};
$quality_groups    = array();
$has_legacy_quality = false;
$quality_tone      = $quality['verified_share'] >= 80 ? 'is-good' : ( $quality['verified_share'] >= 50 ? 'is-warn' : 'is-bad' );

foreach ( $quality['groups'] as $group ) {
	if ( 'legacy' === $group['key'] && $group['pageviews'] > 0 ) {
		$has_legacy_quality = true;
	}

	if ( $group['pageviews'] > 0 || in_array( $group['key'], array( 'verified', 'nojs', 'suspect' ), true ) ) {
		$quality_groups[] = $group;
	}
}

/* translators: %s: time difference, for example "5 minutes". */
$last_run_text  = $data['last_run'] > 0 ? sprintf( __( '%s ago', 'traffic-analytics' ), human_time_diff( $data['last_run'], time() ) ) : __( 'No processing yet', 'traffic-analytics' );
$token_set      = ! empty( $token_set );
$overview_error = isset( $overview_error ) ? (string) $overview_error : '';
$overview_stale = ! empty( $overview_stale );

$icons = array(
	'eye'      => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.6-6.5 10-6.5S22 12 22 12s-3.6 6.5-10 6.5S2 12 2 12Z"/><circle cx="12" cy="12" r="2.6"/></svg>',
	'users'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="3.4"/><path d="M17 11a3.4 3.4 0 1 0 0-6.8"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/></svg>',
	'activity' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12h4l2.4-6 4.2 12L16 12h5"/></svg>',
	'clock'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.2 2"/></svg>',
	'bounce'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M3 5v6a4 4 0 0 0 4 4h13"/><path d="m16 11 4 4-4 4"/></svg>',
	'chart'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19V5"/><path d="M4 19h16"/><path d="m7 15 3.5-4 3 2.5L20 7"/></svg>',
);

$trend = static function ( $change ) {
	if ( null === $change ) {
		return '<span class="wphs-trend is-flat">—</span>';
	}

	$class = $change > 0 ? 'is-up' : ( $change < 0 ? 'is-down' : 'is-flat' );
	$sign  = $change > 0 ? '+' : '';

	return '<span class="wphs-trend ' . $class . '">' . $sign . number_format_i18n( $change, 0 ) . '%</span>';
};

$prev_avg_text    = $prev['sessions'] > 0 ? WPHS_Reports::duration( $data['prev_avg_engaged'] ) : '—';
$prev_bounce_text = $prev['sessions'] > 0 ? number_format_i18n( $data['prev_bounce_rate'], 0 ) . '%' : '—';

$series = $data['series'];
$n      = count( $series );

$chart_peak = 0;
$peak_day   = null;

foreach ( $series as $point ) {
	if ( $point['pageviews'] > $chart_peak ) {
		$chart_peak = $point['pageviews'];
		$peak_day   = $point;
	}
}

$chart_max = max( 1, $chart_peak );

$chart_w   = 1000;
$chart_h   = 240;
$chart_pad = 24;
$points    = array();

foreach ( $series as $i => $point ) {
	$x = $n > 1 ? ( $i / ( $n - 1 ) ) * $chart_w : $chart_w / 2;
	$y = $chart_h - $chart_pad - ( ( $point['pageviews'] / $chart_max ) * ( $chart_h - 2 * $chart_pad ) );

	$points[] = array(
		'x' => round( $x, 2 ),
		'y' => round( $y, 2 ),
		'p' => $point,
	);
}

$line_path = '';
$area_path = '';

if ( $points ) {
	$line_path = 'M' . $points[0]['x'] . ' ' . $points[0]['y'];

	foreach ( array_slice( $points, 1 ) as $point ) {
		$line_path .= ' L' . $point['x'] . ' ' . $point['y'];
	}

	$area_path = 'M' . $points[0]['x'] . ' ' . ( $chart_h - $chart_pad ) . ' L' . $points[0]['x'] . ' ' . $points[0]['y'];

	foreach ( array_slice( $points, 1 ) as $point ) {
		$area_path .= ' L' . $point['x'] . ' ' . $point['y'];
	}

	$last       = end( $points );
	$area_path .= ' L' . $last['x'] . ' ' . ( $chart_h - $chart_pad ) . ' Z';
}

$grid_lines = array();
for ( $i = 0; $i <= 4; $i++ ) {
	$grid_lines[] = round( $chart_pad + ( $chart_h - 2 * $chart_pad ) * ( $i / 4 ), 2 );
}

$step = $n > 0 ? $chart_w / $n : $chart_w;

if ( $n <= 7 ) {
	$date_labels = array();
	foreach ( $series as $point ) {
		$date_labels[] = substr( WPHS_Reports::day_label( $point['day'] ), 5 );
	}
	$date_style = 'grid-template-columns:repeat(' . max( 1, $n ) . ',minmax(0,1fr))';
} else {
	$indexes     = array( 0, (int) floor( $n * 0.25 ), (int) floor( $n * 0.5 ), (int) floor( $n * 0.75 ), $n - 1 );
	$date_labels = array();
	foreach ( $indexes as $index ) {
		$date_labels[] = substr( WPHS_Reports::day_label( $series[ $index ]['day'] ), 5 );
	}
	$date_style = '';
}

$channel_classes = array(
	'search'   => 'is-search',
	'social'   => 'is-social',
	'direct'   => 'is-direct',
	'referral' => 'is-referral',
	'paid'     => 'is-paid',
	'email'    => 'is-email',
	'campaign' => 'is-campaign',
);
?>
<div class="wrap wphs" dir="<?php echo is_rtl() ? 'rtl' : 'ltr'; ?>">
	<header class="wphs-hero">
		<div class="wphs-hero-brand">
			<span class="wphs-logo"><img src="<?php echo esc_url( WPHS_URL . 'assets/logo.png' ); ?>" alt="" width="38" height="38"></span>
			<div>
				<h1 class="wphs-title"><?php esc_html_e( 'WP-Hub Dashboard', 'traffic-analytics' ); ?></h1>
				<p class="wphs-sub"><?php echo $token_set ? esc_html__( 'Site analytics, speed, security, and monitoring', 'traffic-analytics' ) : esc_html__( 'Lightweight pageview and engagement analytics', 'traffic-analytics' ); ?></p>
			</div>
		</div>
		<div class="wphs-hero-side">
			<span class="wphs-live<?php echo $next ? ' is-on' : ''; ?>"><i></i><?php echo $next ? esc_html__( 'Automatic processing active', 'traffic-analytics' ) : esc_html__( 'Automatic processing disabled', 'traffic-analytics' ); ?></span>
			<a class="wphs-btn is-ghost" href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'Settings', 'traffic-analytics' ); ?></a>
		</div>
	</header>

	<?php if ( $token_set ) : ?>
		<?php require WPHS_DIR . 'admin/views/site-status.php'; ?>
	<?php endif; ?>

	<div class="wphs-section-head">
		<h2><?php esc_html_e( 'Pageviews', 'traffic-analytics' ); ?> <span class="wphs-mode-badge"><?php echo esc_html( $mode_label ); ?></span></h2>
		<span class="wphs-muted"><?php esc_html_e( 'Pageviews, sources, and engagement', 'traffic-analytics' ); ?></span>
	</div>

	<?php if ( ! $data['has_any'] ) : ?>
		<section class="wphs-empty-state">
			<div class="wphs-empty-icon"><?php echo $icons['chart']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
			<h2><?php esc_html_e( 'No data yet', 'traffic-analytics' ); ?></h2>
			<p><?php esc_html_e( 'Data starts with the first real visit. Admin and logged-in user visits are not counted by default.', 'traffic-analytics' ); ?></p>
			<ul class="wphs-steps">
				<li><span>1</span> <?php esc_html_e( 'Open the site in a private window', 'traffic-analytics' ); ?></li>
				<li><span>2</span> <?php esc_html_e( 'Open the dashboard or run cron', 'traffic-analytics' ); ?></li>
				<li><span>3</span> <?php esc_html_e( 'If caching is enabled, clear it once', 'traffic-analytics' ); ?></li>
			</ul>
			<div class="wphs-empty-actions">
				<a class="wphs-btn is-primary" href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'Check settings', 'traffic-analytics' ); ?></a>
				<a class="wphs-btn is-secondary" href="<?php echo esc_url( $refresh_url ); ?>"><?php esc_html_e( 'Refresh', 'traffic-analytics' ); ?></a>
			</div>
		</section>
	<?php else : ?>
		<div class="wphs-toolbar">
			<nav class="wphs-segmented" aria-label="<?php esc_attr_e( 'Report range', 'traffic-analytics' ); ?>">
				<?php foreach ( $ranges as $days => $label ) : ?>
					<a class="wphs-seg<?php echo $range === $days ? ' is-active' : ''; ?>"
						href="<?php echo esc_url( $report_url( $days, $mode ) ); ?>">
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>
			<div class="wphs-toolbar-meta">
				<?php echo esc_html( WPHS_Reports::day_label( $data['start'] ) ); ?> <?php esc_html_e( 'to', 'traffic-analytics' ); ?> <?php echo esc_html( WPHS_Reports::day_label( $data['today'] ) ); ?>
				—
				<?php esc_html_e( 'Last processed:', 'traffic-analytics' ); ?> <?php echo esc_html( $last_run_text ); ?>
			</div>
		</div>

		<div class="wphs-mode-row">
			<nav class="wphs-segmented wphs-mode-nav" aria-label="<?php esc_attr_e( 'Traffic quality', 'traffic-analytics' ); ?>">
				<span class="wphs-mode-caption"><?php esc_html_e( 'Quality', 'traffic-analytics' ); ?></span>
				<?php foreach ( $mode_options as $mode_key => $mode_text ) : ?>
					<a class="wphs-seg<?php echo $mode === $mode_key ? ' is-active' : ''; ?>"
						href="<?php echo esc_url( $report_url( $range, $mode_key ) ); ?>">
						<?php echo esc_html( $mode_text ); ?>
					</a>
				<?php endforeach; ?>
			</nav>
			<p class="wphs-mode-hint"><?php echo esc_html( $mode_hint ); ?></p>
		</div>

		<section class="wphs-kpis">
			<div class="wphs-kpi">
				<div class="wphs-kpi-top">
					<span class="wphs-kpi-icon"><?php echo $icons['eye']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					<span class="wphs-kpi-label"><?php esc_html_e( 'Pageviews', 'traffic-analytics' ); ?></span>
				</div>
				<div class="wphs-kpi-value"><?php echo esc_html( WPHS_Reports::int( $t['pageviews'] ) ); ?></div>
				<div class="wphs-kpi-foot">
					<?php echo null === $changes['pageviews'] ? '<span class="wphs-muted">' . esc_html__( 'No previous data', 'traffic-analytics' ) . '</span>' : $trend( $changes['pageviews'] ) . '<span>' . esc_html__( 'vs. previous period', 'traffic-analytics' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
			</div>
			<div class="wphs-kpi">
				<div class="wphs-kpi-top">
					<span class="wphs-kpi-icon"><?php echo $icons['users']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					<span class="wphs-kpi-label"><?php esc_html_e( 'Visitors', 'traffic-analytics' ); ?></span>
				</div>
				<div class="wphs-kpi-value"><?php echo esc_html( WPHS_Reports::int( $t['visitors'] ) ); ?></div>
				<div class="wphs-kpi-foot">
					<?php echo null === $changes['visitors'] ? '<span class="wphs-muted">' . esc_html__( 'No previous data', 'traffic-analytics' ) . '</span>' : $trend( $changes['visitors'] ) . '<span>' . esc_html__( 'vs. previous period', 'traffic-analytics' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
			</div>
			<div class="wphs-kpi">
				<div class="wphs-kpi-top">
					<span class="wphs-kpi-icon"><?php echo $icons['activity']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					<span class="wphs-kpi-label"><?php esc_html_e( 'Sessions', 'traffic-analytics' ); ?></span>
				</div>
				<div class="wphs-kpi-value"><?php echo esc_html( WPHS_Reports::int( $t['sessions'] ) ); ?></div>
				<div class="wphs-kpi-foot">
					<?php echo null === $changes['sessions'] ? '<span class="wphs-muted">' . esc_html__( 'No previous data', 'traffic-analytics' ) . '</span>' : $trend( $changes['sessions'] ) . '<span>' . esc_html__( 'vs. previous period', 'traffic-analytics' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
			</div>
			<div class="wphs-kpi">
				<div class="wphs-kpi-top">
					<span class="wphs-kpi-icon"><?php echo $icons['clock']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					<span class="wphs-kpi-label"><?php esc_html_e( 'Avg. engaged time', 'traffic-analytics' ); ?></span>
				</div>
				<div class="wphs-kpi-value is-compact"><?php echo esc_html( WPHS_Reports::duration( $data['avg_engaged'] ) ); ?></div>
				<div class="wphs-kpi-foot"><span><?php esc_html_e( 'Previous period:', 'traffic-analytics' ); ?> <?php echo esc_html( $prev_avg_text ); ?></span></div>
			</div>
			<div class="wphs-kpi">
				<div class="wphs-kpi-top">
					<span class="wphs-kpi-icon"><?php echo $icons['bounce']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					<span class="wphs-kpi-label"><?php esc_html_e( 'Bounce rate', 'traffic-analytics' ); ?></span>
				</div>
				<div class="wphs-kpi-value"><?php echo esc_html( number_format_i18n( $data['bounce_rate'], 0 ) . '%' ); ?></div>
				<div class="wphs-kpi-foot"><span><?php esc_html_e( 'Previous period:', 'traffic-analytics' ); ?> <?php echo esc_html( $prev_bounce_text ); ?></span></div>
			</div>
		</section>
		<p class="wphs-table-note"><?php esc_html_e( 'Visitors are based on a daily hash; the same person on different days is counted again.', 'traffic-analytics' ); ?></p>

		<?php if ( ! $quality['has_data'] ) : ?>
			<div class="wphs-notice"><?php esc_html_e( 'Traffic quality is recorded from now on; older data is only visible in "All" mode.', 'traffic-analytics' ); ?></div>
		<?php else : ?>
			<section class="wphs-card wphs-quality-card">
				<div class="wphs-card-head">
					<h2><?php esc_html_e( 'Traffic quality', 'traffic-analytics' ); ?></h2>
					<span class="wphs-quality-score <?php echo esc_attr( $quality_tone ); ?>"><?php echo esc_html( number_format_i18n( $quality['verified_share'], $quality['verified_share'] < 10 ? 1 : 0 ) . '% ' . __( 'verified', 'traffic-analytics' ) ); ?></span>
				</div>
				<div class="wphs-quality-track" role="img" aria-label="<?php esc_attr_e( 'Traffic quality breakdown', 'traffic-analytics' ); ?>">
					<?php foreach ( $quality_groups as $group ) : ?>
						<?php if ( $group['pageviews'] <= 0 ) : ?>
							<?php continue; ?>
						<?php endif; ?>
						<span class="wphs-quality-fill <?php echo esc_attr( $group['tone'] ); ?>" style="width:<?php echo esc_attr( round( $group['share'], 2 ) ); ?>%"></span>
					<?php endforeach; ?>
				</div>
				<div class="wphs-quality-legend">
					<?php foreach ( $quality_groups as $group ) : ?>
						<span class="wphs-quality-item">
							<i class="<?php echo esc_attr( $group['tone'] ); ?>"></i>
							<?php echo esc_html( $group['label'] ); ?>
							<b><?php echo esc_html( WPHS_Reports::int( $group['pageviews'] ) ); ?></b>
							<em><?php echo esc_html( number_format_i18n( $group['share'], $group['share'] < 10 ? 1 : 0 ) . '%' ); ?></em>
						</span>
					<?php endforeach; ?>
				</div>
				<?php if ( $has_legacy_quality ) : ?>
					<p class="wphs-table-note"><?php esc_html_e( 'Some data in this range was recorded before quality breakdown was enabled and is not counted in Verified, No-JS, or Suspect.', 'traffic-analytics' ); ?></p>
				<?php endif; ?>
			</section>
		<?php endif; ?>

		<?php if ( $data['detail_limited'] ) : ?>
			<div class="wphs-notice">
			<?php
			/* translators: %s: date when detailed data starts. */
			echo esc_html( sprintf( __( 'Page, source, device, and event details for part of this range have been deleted. Totals are calculated from daily summaries; details are available from %s.', 'traffic-analytics' ), WPHS_Reports::day_label( $data['detail_from'] ) ) );
			?>
			</div>
		<?php endif; ?>

		<?php if ( 0 === (int) $t['pageviews'] ) : ?>
			<div class="wphs-notice">
				<?php
				/* translators: %s: report mode label. */
				echo esc_html( sprintf( __( 'No data for this range in "%s" mode.', 'traffic-analytics' ), $mode_label ) );
				?>
				<?php if ( 'all' !== $mode ) : ?>
					<a href="<?php echo esc_url( $report_url( $range, 'all' ) ); ?>"><?php esc_html_e( 'Show all visits', 'traffic-analytics' ); ?></a>
				<?php else : ?>
					<?php esc_html_e( 'Choose another range.', 'traffic-analytics' ); ?>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<section class="wphs-card wphs-chart-card">
			<div class="wphs-card-head">
				<h2><?php esc_html_e( 'Daily pageviews', 'traffic-analytics' ); ?></h2>
				<span class="wphs-legend"><i></i> <?php esc_html_e( 'Pageviews', 'traffic-analytics' ); ?></span>
			</div>
			<div class="wphs-chart-meta">
				<?php esc_html_e( 'Total pageviews:', 'traffic-analytics' ); ?> <?php echo esc_html( WPHS_Reports::int( $t['pageviews'] ) ); ?>
				<?php if ( $peak_day ) : ?>
					— <?php esc_html_e( 'Peak day:', 'traffic-analytics' ); ?> <?php echo esc_html( WPHS_Reports::int( $chart_peak ) ); ?> <?php esc_html_e( 'pageviews on', 'traffic-analytics' ); ?> <?php echo esc_html( WPHS_Reports::day_label( $peak_day['day'] ) ); ?>
				<?php endif; ?>
			</div>
			<div class="wphs-chart">
				<svg viewBox="0 0 1000 240" preserveAspectRatio="none" role="img" aria-label="<?php esc_attr_e( 'Daily pageviews chart', 'traffic-analytics' ); ?>">
					<g stroke="#e8eef5" stroke-width="1" vector-effect="non-scaling-stroke">
						<?php foreach ( $grid_lines as $grid_y ) : ?>
							<line x1="0" y1="<?php echo esc_attr( $grid_y ); ?>" x2="1000" y2="<?php echo esc_attr( $grid_y ); ?>"></line>
						<?php endforeach; ?>
					</g>
					<?php if ( $area_path ) : ?>
						<path d="<?php echo esc_attr( $area_path ); ?>" fill="rgba(34,113,177,0.08)"></path>
					<?php endif; ?>
					<?php if ( $line_path ) : ?>
						<path d="<?php echo esc_attr( $line_path ); ?>" fill="none" stroke="#2271b1" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke"></path>
					<?php endif; ?>
					<?php if ( 1 === $n && $points ) : ?>
						<circle cx="500" cy="<?php echo esc_attr( $points[0]['y'] ); ?>" r="5" fill="#2271b1"></circle>
					<?php endif; ?>
					<?php foreach ( $points as $i => $point ) : ?>
						<rect x="<?php echo esc_attr( round( $i * $step, 2 ) ); ?>" y="0" width="<?php echo esc_attr( round( $step, 2 ) ); ?>" height="240" fill="transparent" pointer-events="all">
							<title>
							<?php
							/* translators: %1$s: date; %2$s: pageviews; %3$s: visitors. */
							echo esc_html( sprintf( __( '%1$s — %2$s pageviews, %3$s visitors', 'traffic-analytics' ), WPHS_Reports::day_label( $point['p']['day'] ), number_format_i18n( $point['p']['pageviews'] ), number_format_i18n( $point['p']['visitors'] ) ) );
							?>
							</title>
						</rect>
					<?php endforeach; ?>
				</svg>
			</div>
			<div class="wphs-chart-dates<?php echo $n <= 7 ? ' is-dense' : ''; ?>" style="<?php echo esc_attr( $date_style ); ?>">
				<?php foreach ( $date_labels as $label ) : ?>
					<span><?php echo esc_html( $label ); ?></span>
				<?php endforeach; ?>
			</div>
		</section>

		<div class="wphs-grid-2">
			<section class="wphs-card">
				<div class="wphs-card-head">
					<h2><?php esc_html_e( 'Top pages', 'traffic-analytics' ); ?></h2>
					<span class="wphs-muted"><?php esc_html_e( '10 pages', 'traffic-analytics' ); ?></span>
				</div>
				<div class="wphs-table-wrap">
					<table class="wphs-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Page', 'traffic-analytics' ); ?></th>
								<th class="num"><?php esc_html_e( 'Pageviews', 'traffic-analytics' ); ?></th>
								<th class="num"><?php esc_html_e( 'Visitors', 'traffic-analytics' ); ?></th>
								<th class="num"><?php esc_html_e( 'Avg. time', 'traffic-analytics' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php if ( empty( $data['pages'] ) ) : ?>
								<tr><td colspan="4" class="wphs-empty"><?php esc_html_e( 'No data to display.', 'traffic-analytics' ); ?></td></tr>
							<?php else : ?>
								<?php foreach ( $data['pages'] as $row ) : ?>
									<?php $label = '' !== (string) $row['title'] ? $row['title'] : $row['path']; ?>
									<tr>
										<td>
											<div class="wphs-page-name"><?php echo esc_html( $label ); ?></div>
											<div class="wphs-path"><?php echo esc_html( $row['path'] ); ?></div>
										</td>
										<td class="num"><?php echo esc_html( WPHS_Reports::int( $row['pv'] ) ); ?></td>
										<td class="num"><?php echo esc_html( WPHS_Reports::int( $row['uv'] ) ); ?></td>
										<td class="num"><?php echo (int) $row['engaged'] > 0 ? esc_html( WPHS_Reports::duration( $row['avg'] ) ) : '—'; ?></td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
						</tbody>
					</table>
				</div>
				<p class="wphs-table-note"><?php esc_html_e( 'Top 10 pages; visitors per page are unique, and one person can be counted on multiple pages.', 'traffic-analytics' ); ?></p>
			</section>

			<section class="wphs-card">
				<div class="wphs-card-head">
					<h2><?php esc_html_e( 'Sources', 'traffic-analytics' ); ?></h2>
					<span class="wphs-muted"><?php esc_html_e( '10 sources', 'traffic-analytics' ); ?></span>
				</div>
				<div class="wphs-table-wrap">
					<table class="wphs-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Source', 'traffic-analytics' ); ?></th>
								<th><?php esc_html_e( 'Channel', 'traffic-analytics' ); ?></th>
								<th class="num"><?php esc_html_e( 'Pageviews', 'traffic-analytics' ); ?></th>
								<th class="num"><?php esc_html_e( 'Visitors', 'traffic-analytics' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php if ( empty( $data['sources'] ) ) : ?>
								<tr><td colspan="4" class="wphs-empty"><?php esc_html_e( 'No data to display.', 'traffic-analytics' ); ?></td></tr>
							<?php else : ?>
								<?php foreach ( $data['sources'] as $row ) : ?>
									<?php $name = '' !== (string) $row['host'] ? $row['host'] : __( 'Direct', 'traffic-analytics' ); ?>
									<tr>
										<td><?php echo esc_html( $name ); ?></td>
										<td>
											<?php if ( '' !== (string) $row['host'] ) : ?>
												<?php $badge_class = isset( $channel_classes[ $row['channel'] ] ) ? $channel_classes[ $row['channel'] ] : 'is-direct'; ?>
												<span class="wphs-badge <?php echo esc_attr( $badge_class ); ?>"><?php echo esc_html( WPHS_Reports::channel_label( $row['channel'] ) ); ?></span>
											<?php else : ?>
												<span class="wphs-muted">—</span>
											<?php endif; ?>
										</td>
										<td class="num"><?php echo esc_html( WPHS_Reports::int( $row['pv'] ) ); ?></td>
										<td class="num"><?php echo esc_html( WPHS_Reports::int( $row['uv'] ) ); ?></td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
						</tbody>
					</table>
				</div>
				<p class="wphs-table-note"><?php esc_html_e( 'Top 10 sources; visitors per source are unique; internal navigation is excluded, so the sum does not equal total pageviews.', 'traffic-analytics' ); ?></p>
			</section>
		</div>

		<div class="wphs-grid-2">
			<section class="wphs-card">
				<div class="wphs-card-head">
					<h2><?php esc_html_e( 'Devices', 'traffic-analytics' ); ?></h2>
					<span class="wphs-muted"><?php esc_html_e( 'Share of total pageviews', 'traffic-analytics' ); ?></span>
				</div>
				<div class="wphs-table-wrap">
					<table class="wphs-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Device', 'traffic-analytics' ); ?></th>
								<th class="num"><?php esc_html_e( 'Pageviews', 'traffic-analytics' ); ?></th>
								<th class="num"><?php esc_html_e( 'Visitors', 'traffic-analytics' ); ?></th>
								<th><?php esc_html_e( 'Share', 'traffic-analytics' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php if ( empty( $data['devices'] ) ) : ?>
								<tr><td colspan="4" class="wphs-empty"><?php esc_html_e( 'No data to display.', 'traffic-analytics' ); ?></td></tr>
							<?php else : ?>
								<?php foreach ( $data['devices'] as $row ) : ?>
									<?php
									$pct          = (int) $t['pageviews'] > 0 ? ( (int) $row['pv'] / (int) $t['pageviews'] ) * 100 : 0;
									$device_class = 'is-device-' . preg_replace( '/[^a-z]/', '', (string) $row['device'] );
									?>
									<tr>
										<td><span class="wphs-badge <?php echo esc_attr( $device_class ); ?>"><?php echo esc_html( WPHS_Reports::device_label( $row['device'] ) ); ?></span></td>
										<td class="num"><?php echo esc_html( WPHS_Reports::int( $row['pv'] ) ); ?></td>
										<td class="num"><?php echo esc_html( WPHS_Reports::int( $row['uv'] ) ); ?></td>
										<td>
											<div class="wphs-share">
												<span class="wphs-share-track"><span class="wphs-share-fill" style="width: <?php echo esc_attr( round( $pct, 1 ) ); ?>%"></span></span>
												<span class="wphs-share-val"><?php echo esc_html( number_format_i18n( $pct, 0 ) . '%' ); ?></span>
											</div>
										</td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
						</tbody>
					</table>
				</div>
				<p class="wphs-table-note"><?php esc_html_e( 'Visitors per device are unique; one person using multiple devices is counted in multiple rows.', 'traffic-analytics' ); ?></p>
			</section>

			<section class="wphs-card">
				<div class="wphs-card-head">
					<h2><?php esc_html_e( 'Events', 'traffic-analytics' ); ?></h2>
					<span class="wphs-muted"><?php esc_html_e( 'Top 8 events', 'traffic-analytics' ); ?></span>
				</div>
				<div class="wphs-table-wrap">
					<table class="wphs-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Event', 'traffic-analytics' ); ?></th>
								<th class="num"><?php esc_html_e( 'Count', 'traffic-analytics' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php if ( empty( $data['events'] ) ) : ?>
								<tr><td colspan="2" class="wphs-empty"><?php esc_html_e( 'No events recorded.', 'traffic-analytics' ); ?></td></tr>
							<?php else : ?>
								<?php foreach ( $data['events'] as $row ) : ?>
									<tr>
										<td><?php echo esc_html( WPHS_Reports::event_label( $row['event'] ) ); ?></td>
										<td class="num"><?php echo esc_html( WPHS_Reports::int( $row['total'] ) ); ?></td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
						</tbody>
					</table>
				</div>
				<p class="wphs-table-note"><?php esc_html_e( 'Events are collected from browser interactions.', 'traffic-analytics' ); ?></p>
			</section>
		</div>
	<?php endif; ?>

	<?php require WPHS_DIR . 'admin/views/security-check.php'; ?>

	<?php if ( $data['spool_size'] > 0 ) : ?>
		<div class="wphs-notice">
			<?php
			/* translators: %s: amount of queued data, for example "1 KB". */
			echo esc_html( sprintf( __( '%s of data is queued for processing.', 'traffic-analytics' ), size_format( $data['spool_size'], 1 ) ) );
			?>
			<a href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'Process queue manually', 'traffic-analytics' ); ?></a>
		</div>
	<?php endif; ?>

	<?php if ( ! $next ) : ?>
		<div class="wphs-notice">
		<?php
		/* translators: %s: cron event name. */
		echo esc_html( sprintf( __( 'Scheduled processing is not active. Process the queue manually from the settings page or set a server cron for %s.', 'traffic-analytics' ), 'wphs_aggregate' ) );
		?>
		</div>
	<?php endif; ?>

	<div class="wphs-footer">
		<span><?php esc_html_e( 'Last processed:', 'traffic-analytics' ); ?> <?php echo esc_html( $last_run_text ); ?></span>
		<a href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'Settings and data retention', 'traffic-analytics' ); ?></a>
	</div>
</div>
