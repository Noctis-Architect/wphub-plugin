<?php
defined( 'ABSPATH' ) || exit;

final class WPHS_Bot_Filter {

	private const PATTERN = '/googlebot|bingbot|yandexbot|baiduspider|duckduckbot|slurp|spider|crawl|mediapartners|adsbot|bingpreview|facebookexternalhit|twitterbot|linkedinbot|slackbot|telegrambot|discordbot|embedly|w3c_validator|pingdom|uptimerobot|statuscake|gtmetrix|lighthouse|headlesschrome|phantomjs|python-requests|python-urllib|go-http-client|curl\/|wget\/|axios|node-fetch|okhttp|ahrefs|semrush|screamingfrog|feedfetcher|google-inspectiontool|googleother|chrome-lighthouse|pagespeed|applebot|petalbot|bytespider|ccbot|claudebot|gptbot|perplexitybot|amazonbot|ia_archiver|archive\.org|mj12bot|dotbot|selenium|playwright|puppeteer|phantom|electron\/|scrapy|httpclient|libwww|guzzle|postmanruntime|insomnia|httpie|aiohttp|httpx\/|undici|dart\/|java\/|ruby|perl|php\/|go-http|wordpress\/|wp-remote|monitoring|uptime|dataprovider|expanse|zoominfo|cloudfront|masscan|nmap|zgrab|sqlmap|nikto|acunetix|netsparker|burp|nuclei/i';

	const SCORE_THRESHOLD = 3;

	public static function is_bot( $ua ) {
		$ua = trim( (string) $ua );

		if ( '' === $ua ) {
			return true;
		}

		if ( preg_match( self::PATTERN, $ua ) ) {
			return true;
		}

		if ( (int) apply_filters( 'wphs_is_bot', 0, $ua ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Scores the compact browser signals sent by tracker.js.
	 * Missing signals are treated as legacy JS and stay verified, so cached
	 * copies of the previous tracker do not suddenly lose all traffic.
	 */
	public static function evaluate_js( $signals, $ua ) {
		if ( ! is_array( $signals ) || empty( $signals ) ) {
			return array(
				'verified' => true,
				'score'    => 0,
				'reasons'  => array( 'legacy' ),
			);
		}

		$ua      = strtolower( (string) $ua );
		$desktop = ! self::is_mobile_ua( $ua );
		$browser = self::browser_family( $ua );
		$score   = 0;
		$reasons = array();
		$hard    = false;

		$webdriver = self::sig_int( $signals, 'w' );
		if ( 1 === $webdriver ) {
			$score += 5;
			$reasons[] = 'webdriver';
			$hard = true;
		}

		$screen_w = self::sig_int( $signals, 'sw' );
		$screen_h = self::sig_int( $signals, 'sh' );
		if ( array_key_exists( 'sw', $signals ) && ( $screen_w <= 0 || $screen_h <= 0 ) ) {
			$score += 5;
			$reasons[] = 'screen';
			$hard = true;
		}

		$languages = self::sig_int( $signals, 'l', -1 );
		if ( $languages === 0 ) {
			$score += 4;
			$reasons[] = 'languages';
			$hard = true;
		}

		if ( $desktop ) {
			$outer_w = self::sig_int( $signals, 'ow', -1 );
			$outer_h = self::sig_int( $signals, 'oh', -1 );
			if ( ( $outer_w === 0 || $outer_h === 0 ) && ( $outer_w >= 0 || $outer_h >= 0 ) ) {
				$score += 2;
				$reasons[] = 'outer';
			}

			$plugins = self::sig_int( $signals, 'p', -1 );
			if ( 0 === $plugins && in_array( $browser, array( 'chrome', 'edge' ), true ) ) {
				$score += 1;
				$reasons[] = 'plugins';
			}
		}

		$gl = self::sig_int( $signals, 'gl' );
		if ( 2 === $gl ) {
			$score += 2;
			$reasons[] = 'software_gl';
		}

		$notification = self::sig_int( $signals, 'nt', -1 );
		if ( 2 === $notification ) {
			$score += 1;
			$reasons[] = 'notification_denied';
		}

		$chrome = self::sig_int( $signals, 'ch', -1 );
		if ( 0 === $chrome && in_array( $browser, array( 'chrome', 'edge' ), true ) ) {
			$score += 1;
			$reasons[] = 'chrome_object';
		}

		$raf = self::sig_int( $signals, 'raf', -1 );
		if ( 0 === $raf ) {
			$score += 1;
			$reasons[] = 'no_animation_frame';
		}

		$elapsed = self::sig_int( $signals, 'ts', -1 );
		if ( $elapsed >= 0 && $elapsed < 150 ) {
			$score += 1;
			$reasons[] = 'instant_beacon';
		}

		$cores = self::sig_int( $signals, 'hc', -1 );
		if ( 0 === $cores ) {
			$score += 1;
			$reasons[] = 'no_cpu_info';
		}

		$platform = self::sig_int( $signals, 'pf', -1 );
		if ( $platform > 0 ) {
			$expected = self::ua_platform( $ua );
			$map      = array( 1 => 'windows', 2 => 'macos', 3 => 'linux', 4 => 'android', 5 => 'ios' );
			$actual   = isset( $map[ $platform ] ) ? $map[ $platform ] : 'other';

			if ( 'other' !== $expected && 'other' !== $actual && $expected !== $actual ) {
				$score += 1;
				$reasons[] = 'platform_mismatch';
			}
		}

		$touch = self::sig_int( $signals, 'tp', -1 );
		if ( ! $desktop && 0 === $touch ) {
			$score += 1;
			$reasons[] = 'no_touch';
		}

		$visible = self::sig_int( $signals, 'vs', -1 );
		if ( 0 === $visible ) {
			$score += 1;
			$reasons[] = 'hidden';
		}

		$interactions = self::sig_int( $signals, 'it' );
		if ( $interactions > 0 && $score > 0 ) {
			$score = max( 0, $score - 1 );
		}

		$verdict = array(
			'verified' => ! $hard && $score < self::SCORE_THRESHOLD,
			'score'    => $score,
			'reasons'  => array_slice( $reasons, 0, 6 ),
		);

		$filtered = apply_filters( 'wphs_js_bot_verdict', $verdict, $signals, $ua );

		if ( is_array( $filtered ) && array_key_exists( 'verified', $filtered ) ) {
			return array(
				'verified' => (bool) $filtered['verified'],
				'score'    => isset( $filtered['score'] ) ? (int) $filtered['score'] : $score,
				'reasons'  => isset( $filtered['reasons'] ) && is_array( $filtered['reasons'] ) ? array_slice( $filtered['reasons'], 0, 6 ) : $reasons,
			);
		}

		return $verdict;
	}

	public static function is_mobile_ua( $ua ) {
		return (bool) preg_match( '/mobile|iphone|ipod|android.*mobile|windows phone|blackberry|opera mini|ipad|tablet|kindle|silk/', strtolower( (string) $ua ) );
	}

	private static function browser_family( $ua ) {
		if ( preg_match( '/edg\//', $ua ) ) {
			return 'edge';
		}
		if ( preg_match( '/opr\/|opera/', $ua ) ) {
			return 'opera';
		}
		if ( preg_match( '/firefox\//', $ua ) ) {
			return 'firefox';
		}
		if ( preg_match( '/chrome\/|crios/', $ua ) ) {
			return 'chrome';
		}
		if ( preg_match( '/safari\//', $ua ) ) {
			return 'safari';
		}

		return 'other';
	}

	private static function ua_platform( $ua ) {
		if ( preg_match( '/iphone|ipad|ipod|ios/', $ua ) ) {
			return 'ios';
		}
		if ( preg_match( '/android/', $ua ) ) {
			return 'android';
		}
		if ( preg_match( '/windows/', $ua ) ) {
			return 'windows';
		}
		if ( preg_match( '/mac os|macintosh/', $ua ) ) {
			return 'macos';
		}
		if ( preg_match( '/linux|x11|cros/', $ua ) ) {
			return 'linux';
		}

		return 'other';
	}

	private static function sig_int( $signals, $key, $default = 0 ) {
		if ( ! array_key_exists( $key, $signals ) ) {
			return $default;
		}

		$value = $signals[ $key ];

		if ( is_bool( $value ) ) {
			return $value ? 1 : 0;
		}

		if ( ! is_numeric( $value ) ) {
			return $default;
		}

		return (int) $value;
	}
}
