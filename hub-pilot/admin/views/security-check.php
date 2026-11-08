<?php
defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are local to the admin method that requires this file.

$security     = WPHS_Security_Check::results();
$has_failures = ! empty( $security['failures'] );
?>
<section class="wphs-card wphs-security-check<?php echo $has_failures ? ' is-warn' : ' is-ok'; ?>">
	<div class="wphs-card-head">
		<h2><?php esc_html_e( 'Security check', 'hub-pilot' ); ?></h2>
		<span class="wphs-card-head-actions">
			<span class="wphs-muted"><?php esc_html_e( 'Basic checks', 'hub-pilot' ); ?></span>
			<a class="wphs-security-refresh" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wphs_security_recheck' ), 'wphs_security_recheck' ) ); ?>"><?php esc_html_e( 'Re-check', 'hub-pilot' ); ?></a>
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
		<p class="wphs-security-pass"><?php esc_html_e( 'All basic checks passed.', 'hub-pilot' ); ?></p>
	<?php endif; ?>

	<?php if ( ! empty( $security['unknown'] ) ) : ?>
		<p class="wphs-security-note"><?php esc_html_e( 'Some checks could not run from this server.', 'hub-pilot' ); ?></p>
	<?php endif; ?>

	<p class="wphs-table-note">
	<?php
	/* translators: %s: time difference, for example "5 minutes". */
	echo esc_html( sprintf( __( 'Checked %s ago.', 'hub-pilot' ), human_time_diff( $security['checked_at'], time() ) ) );
	?>
	</p>
</section>
