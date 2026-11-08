<?php
defined( 'ABSPATH' ) || exit;

$overview       = isset( $overview ) && is_array( $overview ) ? $overview : array();
$overview_error = isset( $overview_error ) ? (string) $overview_error : '';
$overview_stale = ! empty( $overview_stale );

$console_url  = WPHS_Settings::console_url();
$settings_url = add_query_arg( array( 'page' => 'hub-pilot-settings' ), admin_url( 'admin.php' ) );

$site       = is_array( $overview['site'] ?? null ) ? $overview['site'] : array();
$speed      = is_array( $overview['speed'] ?? null ) ? $overview['speed'] : array();
$security   = is_array( $overview['security'] ?? null ) ? $overview['security'] : null;
$monitoring = is_array( $overview['monitoring'] ?? null ) ? $overview['monitoring'] : null;

$mobile  = isset( $speed['mobile'] ) && is_array( $speed['mobile'] ) ? $speed['mobile'] : null;
$desktop = isset( $speed['desktop'] ) && is_array( $speed['desktop'] ) ? $speed['desktop'] : null;

$score_class = static function ( $score ) {
	if ( ! is_numeric( $score ) ) {
		return 'is-na';
	}

	$score = (int) $score;

	if ( $score >= 90 ) {
		return 'is-good';
	}

	if ( $score >= 50 ) {
		return 'is-mid';
	}

	return 'is-bad';
};

$fmt_score = static function ( $score ) {
	return is_numeric( $score ) ? number_format_i18n( (int) $score ) : '—';
};

$fmt_seconds = static function ( $ms ) {
	if ( ! is_numeric( $ms ) || (float) $ms <= 0 ) {
		return '—';
	}

	return number_format_i18n( (float) $ms / 1000, 2 ) . ' ' . __( 'sec', 'hub-pilot' );
};

$fmt_ms = static function ( $ms ) {
	if ( ! is_numeric( $ms ) || (float) $ms <= 0 ) {
		return '—';
	}

	return number_format_i18n( (float) $ms, 0 ) . ' ms';
};

$fmt_cls = static function ( $cls ) {
	return is_numeric( $cls ) ? number_format_i18n( (float) $cls, 2 ) : '—';
};

$fmt_date = static function ( $iso ) {
	$ts = is_string( $iso ) && '' !== $iso ? strtotime( $iso ) : 0;

	return $ts ? wp_date( 'Y/m/d H:i', $ts ) : '—';
};

$security_score = $security ? ( $security['security_score'] ?? ( $security['score'] ?? null ) ) : null;
$security_grade = $security && ! empty( $security['grade'] ) ? (string) $security['grade'] : '';

$count_defs = array(
	'critical' => array( 'label' => __( 'Critical', 'hub-pilot' ), 'class' => 'is-critical' ),
	'high'     => array( 'label' => __( 'High', 'hub-pilot' ), 'class' => 'is-high' ),
	'medium'   => array( 'label' => __( 'Medium', 'hub-pilot' ), 'class' => 'is-medium' ),
	'low'      => array( 'label' => __( 'Low', 'hub-pilot' ), 'class' => 'is-low' ),
);

$has_counts = false;

if ( $security ) {
	foreach ( $count_defs as $key => $def ) {
		if ( isset( $security[ $key ] ) && is_numeric( $security[ $key ] ) ) {
			$has_counts = true;
			break;
		}
	}
}

$component_defs = array(
	'headers_score'         => __( 'Headers', 'hub-pilot' ),
	'ssl_score'             => __( 'SSL', 'hub-pilot' ),
	'vulnerabilities_score' => __( 'Vulnerabilities', 'hub-pilot' ),
);
$has_components = false;

if ( $security ) {
	foreach ( $component_defs as $key => $label ) {
		if ( isset( $security[ $key ] ) && is_numeric( $security[ $key ] ) ) {
			$has_components = true;
			break;
		}
	}
}

$monitor_status = $monitoring && ! empty( $monitoring['status'] ) ? (string) $monitoring['status'] : 'unknown';
$monitor_class  = 'up' === $monitor_status ? 'is-up' : ( 'down' === $monitor_status ? 'is-down' : 'is-unknown' );
$monitor_label  = 'up' === $monitor_status ? __( 'Online', 'hub-pilot' ) : ( 'down' === $monitor_status ? __( 'Offline', 'hub-pilot' ) : __( 'Unknown', 'hub-pilot' ) );

$security_link = $security && ! empty( $security['url'] ) ? $security['url'] : $console_url;
$monitor_link  = $monitoring && ! empty( $monitoring['url'] ) ? $monitoring['url'] : $console_url;
?>
<section class="wphs-site-section">
	<div class="wphs-section-head">
		<h2><?php esc_html_e( 'Site status', 'hub-pilot' ); ?></h2>
		<div class="wphs-section-links">
			<?php if ( ! empty( $site['domain'] ) ) : ?>
				<span class="wphs-muted"><?php echo esc_html( $site['domain'] ); ?></span>
			<?php endif; ?>
			<a href="<?php echo esc_url( $console_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open console', 'hub-pilot' ); ?></a>
		</div>
	</div>

	<?php if ( ! $overview && '' !== $overview_error ) : ?>
		<div class="wphs-notice is-error">
			<?php esc_html_e( 'Failed to fetch site status from the console:', 'hub-pilot' ); ?> <?php echo esc_html( $overview_error ); ?>
			<a href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'Check connection', 'hub-pilot' ); ?></a>
		</div>
	<?php else : ?>
		<?php if ( $overview_stale && '' !== $overview_error ) : ?>
			<div class="wphs-notice"><?php esc_html_e( 'Could not reach the console; showing the last saved data.', 'hub-pilot' ); ?></div>
		<?php endif; ?>

		<div class="wphs-site-grid">
			<div class="wphs-card wphs-site-card">
				<div class="wphs-card-head">
					<h2><?php esc_html_e( 'Latest speed test', 'hub-pilot' ); ?></h2>
					<span class="wphs-muted"><?php esc_html_e( 'Mobile and desktop', 'hub-pilot' ); ?></span>
				</div>

				<?php if ( ! $mobile && ! $desktop ) : ?>
					<div class="wphs-empty"><?php esc_html_e( 'No speed test recorded yet.', 'hub-pilot' ); ?></div>
				<?php else : ?>
					<div class="wphs-speed-grid">
						<?php
						foreach ( array(
							'mobile'  => __( 'Mobile', 'hub-pilot' ),
							'desktop' => __( 'Desktop', 'hub-pilot' ),
						) as $key => $label ) :
							$strategy_data = 'mobile' === $key ? $mobile : $desktop;
							?>
							<div class="wphs-speed-item">
								<div class="wphs-speed-title"><?php echo esc_html( $label ); ?></div>
								<?php if ( $strategy_data ) : ?>
									<div class="wphs-score <?php echo esc_attr( $score_class( $strategy_data['performance_score'] ?? null ) ); ?>"><?php echo esc_html( $fmt_score( $strategy_data['performance_score'] ?? null ) ); ?></div>
									<div class="wphs-metrics">
										<span>LCP <b><?php echo esc_html( $fmt_seconds( $strategy_data['lcp_ms'] ?? null ) ); ?></b></span>
										<span>CLS <b><?php echo esc_html( $fmt_cls( $strategy_data['cls'] ?? null ) ); ?></b></span>
										<span>TBT <b><?php echo esc_html( $fmt_ms( $strategy_data['tbt_ms'] ?? null ) ); ?></b></span>
									</div>
									<div class="wphs-muted"><?php esc_html_e( 'Tested:', 'hub-pilot' ); ?> <?php echo esc_html( $fmt_date( $strategy_data['created_at'] ?? null ) ); ?></div>
								<?php else : ?>
									<div class="wphs-empty"><?php esc_html_e( 'No test recorded', 'hub-pilot' ); ?></div>
								<?php endif; ?>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>

				<a class="wphs-card-link" href="<?php echo esc_url( $console_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Speed test details in the console', 'hub-pilot' ); ?></a>
			</div>

			<div class="wphs-card wphs-site-card">
				<div class="wphs-card-head">
					<h2><?php esc_html_e( 'Latest security test', 'hub-pilot' ); ?></h2>
					<span class="wphs-muted"><?php esc_html_e( 'If available', 'hub-pilot' ); ?></span>
				</div>

				<?php if ( ! $security ) : ?>
					<div class="wphs-empty"><?php esc_html_e( 'No security test recorded yet.', 'hub-pilot' ); ?></div>
				<?php else : ?>
					<div class="wphs-security-summary">
						<div class="wphs-score <?php echo esc_attr( $score_class( $security_score ) ); ?>"><?php echo esc_html( $fmt_score( $security_score ) ); ?></div>
						<div>
							<div class="wphs-security-grade">
								<?php esc_html_e( 'Security score', 'hub-pilot' ); ?>
								<?php if ( '' !== $security_grade ) : ?>
									<span class="wphs-badge"><?php echo esc_html( $security_grade ); ?></span>
								<?php endif; ?>
							</div>
							<?php if ( $has_counts ) : ?>
								<div class="wphs-counts">
									<?php foreach ( $count_defs as $key => $def ) : ?>
										<?php if ( isset( $security[ $key ] ) && is_numeric( $security[ $key ] ) ) : ?>
											<span class="wphs-count <?php echo esc_attr( $def['class'] ); ?>"><?php echo esc_html( $def['label'] . ' ' . number_format_i18n( (int) $security[ $key ] ) ); ?></span>
										<?php endif; ?>
									<?php endforeach; ?>
								</div>
							<?php endif; ?>
							<div class="wphs-muted"><?php esc_html_e( 'Tested:', 'hub-pilot' ); ?> <?php echo esc_html( $fmt_date( $security['created_at'] ?? null ) ); ?></div>
						</div>
					</div>

					<?php if ( $has_components ) : ?>
						<div class="wphs-metrics">
							<?php foreach ( $component_defs as $key => $label ) : ?>
								<?php if ( isset( $security[ $key ] ) && is_numeric( $security[ $key ] ) ) : ?>
									<span><?php echo esc_html( $label ); ?> <b><?php echo esc_html( number_format_i18n( (int) $security[ $key ] ) ); ?></b></span>
								<?php endif; ?>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				<?php endif; ?>

				<a class="wphs-card-link" href="<?php echo esc_url( $security_link ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Security test details in the console', 'hub-pilot' ); ?></a>
			</div>

			<div class="wphs-card wphs-site-card">
				<div class="wphs-card-head">
					<h2><?php esc_html_e( 'Monitoring', 'hub-pilot' ); ?></h2>
					<span class="wphs-muted"><?php esc_html_e( '24 hours', 'hub-pilot' ); ?></span>
				</div>

				<?php if ( ! $monitoring ) : ?>
					<div class="wphs-empty"><?php esc_html_e( 'Monitoring is not active or no data has been recorded.', 'hub-pilot' ); ?></div>
				<?php else : ?>
					<div class="wphs-status-pill <?php echo esc_attr( $monitor_class ); ?>"><i></i><?php echo esc_html( $monitor_label ); ?></div>
					<div class="wphs-metrics">
						<?php if ( isset( $monitoring['uptime_24h'] ) && is_numeric( $monitoring['uptime_24h'] ) ) : ?>
							<span><?php esc_html_e( 'Uptime', 'hub-pilot' ); ?> <b><?php echo esc_html( number_format_i18n( (float) $monitoring['uptime_24h'], 1 ) . '%' ); ?></b></span>
						<?php endif; ?>
						<?php if ( isset( $monitoring['avg_ttfb_ms'] ) && is_numeric( $monitoring['avg_ttfb_ms'] ) ) : ?>
							<span>TTFB <b><?php echo esc_html( $fmt_ms( $monitoring['avg_ttfb_ms'] ) ); ?></b></span>
						<?php endif; ?>
						<?php if ( isset( $monitoring['ssl_days_remaining'] ) && is_numeric( $monitoring['ssl_days_remaining'] ) ) : ?>
							<span>SSL <b>
							<?php
							/* translators: %s: number of days. */
							echo esc_html( sprintf( _n( '%s day', '%s days', (int) $monitoring['ssl_days_remaining'], 'hub-pilot' ), number_format_i18n( (int) $monitoring['ssl_days_remaining'] ) ) );
							?>
							</b></span>
						<?php elseif ( isset( $monitoring['ssl_valid'] ) ) : ?>
							<span>SSL <b><?php echo $monitoring['ssl_valid'] ? esc_html__( 'Valid', 'hub-pilot' ) : esc_html__( 'Invalid', 'hub-pilot' ); ?></b></span>
						<?php endif; ?>
					</div>
					<div class="wphs-muted"><?php esc_html_e( 'Last check:', 'hub-pilot' ); ?> <?php echo esc_html( $fmt_date( $monitoring['last_check'] ?? null ) ); ?></div>
				<?php endif; ?>

				<a class="wphs-card-link" href="<?php echo esc_url( $monitor_link ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Monitoring details in the console', 'hub-pilot' ); ?></a>
			</div>
		</div>
	<?php endif; ?>
</section>
