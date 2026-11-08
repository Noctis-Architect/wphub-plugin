<?php
defined( 'ABSPATH' ) || exit;

final class WPHS_Credit {

	public static function init() {
		add_action( 'wp_head', array( __CLASS__, 'render' ), 0 );
	}

	public static function render() {
		if ( ! WPHS_Settings::value( 'source_credit' ) ) {
			return;
		}

		if ( ! apply_filters( 'wphs_show_source_credit', true ) ) {
			return;
		}

		if ( is_admin() || is_feed() || is_robots() || is_embed() ) {
			return;
		}

		$width = 47;
		$label = 'HUBPILOT BY WP-HUB';
		$rule  = str_repeat( '─', $width );

		echo "\n<!--\n";
		echo esc_html( '  ┌' . $rule . "┐\n" );
		echo esc_html( '  │' . str_pad( $label, $width, ' ', STR_PAD_BOTH ) . "│\n" );
		echo esc_html( '  └' . $rule . "┘\n" );
		echo "-->\n";
	}
}
