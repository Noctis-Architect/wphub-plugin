<?php
defined( 'ABSPATH' ) || exit;

$security     = WPHS_Security_Check::results();
$has_failures = ! empty( $security['failures'] );
?>
<section class="wphs-card wphs-security-check<?php echo $has_failures ? ' is-warn' : ' is-ok'; ?>">
	<div class="wphs-card-head">
		<h2><?php esc_html_e( 'Security check', 'traffic-analytics' ); ?></h2>
		<span class="wphs-card-head-actions">
			<span class="wphs-muted"><?php esc_html_e( 'Basic checks', 'traffic-analytics' ); ?></span>
			<a class="wphs-security-refresh" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wphs_security_recheck' ), 'wphs_security_recheck' ) ); ?>"><?php esc_html_e( 'Re-check', 'traffic-analytics' ); ?></a>
		</span>
	</div>

	<?php if ( $has_failures ) : ?>
		<ul class="wphs-security-list">
			<?php foreach ( $security['failures'] as $failure ) : ?>
				<li>
					<span class="wphs-security-dot" aria-hidden="true"></span>
					<span><?php echo esc_html( $failure['label'] ); ?></span>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php else : ?>
		<p class="wphs-security-pass"><?php esc_html_e( 'All basic checks passed.', 'traffic-analytics' ); ?></p>
	<?php endif; ?>

	<?php if ( ! empty( $security['unknown'] ) ) : ?>
		<p class="wphs-security-note"><?php esc_html_e( 'Some checks could not run from this server.', 'traffic-analytics' ); ?></p>
	<?php endif; ?>

	<p class="wphs-table-note"><?php echo esc_html( sprintf( __( 'Checked %s ago.', 'traffic-analytics' ), human_time_diff( $security['checked_at'], time() ) ) ); ?></p>
</section>
