<?php
/**
 * AI Writer: write articles with the WP-Hub console (site key, console quota) or with
 * the administrator's own OpenAI-compatible provider (manual mode, no WP-Hub needed).
 *
 * @package Hubpulse
 */

defined( 'ABSPATH' ) || exit;

final class WPHS_Writer {

	const OPTION = 'wphs_writer';
	const META   = '_wphs_ai_generated';

	public static function init() {
		add_action( 'admin_post_wphs_writer_save', array( __CLASS__, 'save' ) );

		$ajax = array(
			'mode',
			'site_key',
			'ai_test',
			'ai_generate',
			'console_status',
			'console_link',
			'console_unlink',
			'console_articles',
			'console_article',
			'console_create',
			'console_publish',
		);

		foreach ( $ajax as $action ) {
			add_action( 'wp_ajax_wphs_' . $action, array( __CLASS__, 'ajax_' . $action ) );
		}
	}

	/* ───────────────────────── settings ───────────────────────── */

	public static function default_system_prompt() {
		return implode(
			"\n",
			array(
				'You are a senior SEO content writer and editor.',
				'Write original, accurate, genuinely useful articles for real readers, not for search engines.',
				'Use a clear structure: a short introduction, H2/H3 sections, short paragraphs, lists and tables where they help.',
				'Use the focus keyword naturally in the first paragraph, at least one H2 and the conclusion. Never keyword-stuff.',
				'Do not invent statistics, prices, quotes, laws or sources. If a fact is uncertain, say so or leave it out.',
				'Match the brand voice and the audience described below. Write entirely in the requested language.',
			)
		);
	}

	public static function defaults() {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );

		return array(
			'mode'             => '',
			'provider'         => WPHS_AI_Provider::wp_ai_available() ? 'wordpress' : 'openai',
			'base_url'         => '',
			'api_key'          => '',
			'model'            => '',
			'temperature'      => 0.7,
			'max_tokens'       => 6000,
			'system_prompt'    => self::default_system_prompt(),
			'site_name'        => get_bloginfo( 'name' ),
			'site_domain'      => $host,
			'site_topic'       => '',
			'business'         => '',
			'products'         => '',
			'audience'         => '',
			'tone'             => 'professional',
			'language'         => self::locale_language(),
			'region'           => '',
			'length'           => 'medium',
			'rules'            => '',
			'internal_links'   => 3,
			'include_faq'      => true,
			'default_status'   => 'draft',
			'default_category' => 0,
			'author_id'        => 0,
			'tested_at'        => 0,
		);
	}

	public static function settings() {
		$saved = get_option( self::OPTION, array() );

		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	private static function update( array $changes ) {
		$settings = array_merge( self::settings(), $changes );

		update_option( self::OPTION, $settings, false );

		return $settings;
	}

	public static function manual_ready() {
		$s = self::settings();

		if ( 'wordpress' === $s['provider'] ) {
			return WPHS_AI_Provider::wp_ai_available();
		}

		return '' !== $s['base_url'] && '' !== $s['model'] && ( '' !== $s['api_key'] || 'ollama' === $s['provider'] );
	}

	public static function tones() {
		return array(
			'professional' => __( 'Professional', 'hubpulse' ),
			'friendly'     => __( 'Friendly', 'hubpulse' ),
			'educational'  => __( 'Educational', 'hubpulse' ),
			'persuasive'   => __( 'Persuasive (sales)', 'hubpulse' ),
			'formal'       => __( 'Formal', 'hubpulse' ),
			'casual'       => __( 'Casual', 'hubpulse' ),
		);
	}

	public static function lengths() {
		return array(
			'short'  => __( 'Short (about 800 words)', 'hubpulse' ),
			'medium' => __( 'Medium (about 1,500 words)', 'hubpulse' ),
			'long'   => __( 'Long (about 2,500 words)', 'hubpulse' ),
		);
	}

	private static function locale_language() {
		$map    = array(
			'fa' => 'Persian',
			'ar' => 'Arabic',
			'tr' => 'Turkish',
			'de' => 'German',
			'fr' => 'French',
			'es' => 'Spanish',
			'it' => 'Italian',
			'ru' => 'Russian',
			'en' => 'English',
		);
		$prefix = substr( get_locale(), 0, 2 );

		return isset( $map[ $prefix ] ) ? $map[ $prefix ] : 'English';
	}

	/**
	 * Handles the manual-mode settings form and the console publishing permissions.
	 */
	public static function save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'hubpulse' ) );
		}

		check_admin_referer( 'wphs_writer_save' );

		$section = isset( $_POST['section'] ) ? sanitize_key( wp_unslash( $_POST['section'] ) ) : '';
		$current = self::settings();

		if ( 'console' === $section ) {
			$changes = array(
				'allow_publish'    => ! empty( $_POST['allow_publish'] ),
				'author_id'        => isset( $_POST['author_id'] ) ? absint( $_POST['author_id'] ) : 0,
				'default_category' => isset( $_POST['default_category'] ) ? absint( $_POST['default_category'] ) : 0,
			);

			WPHS_Content_API::update( $changes );
			self::redirect( 'saved' );
		}

		$presets  = WPHS_AI_Provider::presets();
		$provider = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : 'custom';
		$provider = isset( $presets[ $provider ] ) ? $provider : 'custom';
		$base     = isset( $_POST['base_url'] ) ? esc_url_raw( trim( wp_unslash( $_POST['base_url'] ) ), array( 'https', 'http' ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- esc_url_raw() sanitizes the URL.
		$api_key  = isset( $_POST['api_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) ) : '';

		// The saved key is never printed back; an empty field keeps it.
		if ( '' === $api_key || false !== strpos( $api_key, '•' ) ) {
			$api_key = $current['api_key'];
		}

		if ( ! empty( $_POST['clear_api_key'] ) ) {
			$api_key = '';
		}

		$text = static function ( $key, $max = 200 ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by check_admin_referer() above.
			return isset( $_POST[ $key ] ) ? substr( sanitize_text_field( wp_unslash( $_POST[ $key ] ) ), 0, $max ) : '';
		};
		$area = static function ( $key, $max = 2000 ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by check_admin_referer() above.
			return isset( $_POST[ $key ] ) ? substr( sanitize_textarea_field( wp_unslash( $_POST[ $key ] ) ), 0, $max ) : '';
		};

		$tone   = $text( 'tone', 40 );
		$length = $text( 'length', 20 );
		$status = $text( 'default_status', 20 );
		$prompt = $area( 'system_prompt', 4000 );

		$changes = array(
			'mode'             => 'manual',
			'provider'         => $provider,
			'base_url'         => untrailingslashit( $base ),
			'api_key'          => $api_key,
			'model'            => $text( 'model', 120 ),
			'temperature'      => isset( $_POST['temperature'] ) ? max( 0, min( 1.5, (float) $_POST['temperature'] ) ) : 0.7,
			'max_tokens'       => isset( $_POST['max_tokens'] ) ? max( 1000, min( 32000, absint( $_POST['max_tokens'] ) ) ) : 6000,
			'system_prompt'    => '' !== trim( $prompt ) ? $prompt : self::default_system_prompt(),
			'site_name'        => $text( 'site_name', 120 ),
			'site_domain'      => $text( 'site_domain', 120 ),
			'site_topic'       => $text( 'site_topic', 200 ),
			'business'         => $area( 'business', 1500 ),
			'products'         => $area( 'products', 1500 ),
			'audience'         => $text( 'audience', 300 ),
			'tone'             => isset( self::tones()[ $tone ] ) ? $tone : 'professional',
			'language'         => '' !== $text( 'language', 40 ) ? $text( 'language', 40 ) : self::locale_language(),
			'region'           => $text( 'region', 120 ),
			'length'           => isset( self::lengths()[ $length ] ) ? $length : 'medium',
			'rules'            => $area( 'rules', 2000 ),
			'internal_links'   => isset( $_POST['internal_links'] ) ? min( 10, absint( $_POST['internal_links'] ) ) : 3,
			'include_faq'      => ! empty( $_POST['include_faq'] ),
			'default_status'   => in_array( $status, array( 'draft', 'pending' ), true ) ? $status : 'draft',
			'default_category' => isset( $_POST['default_category'] ) ? absint( $_POST['default_category'] ) : 0,
			'author_id'        => isset( $_POST['author_id'] ) ? absint( $_POST['author_id'] ) : 0,
		);

		$changed_provider = $changes['base_url'] !== $current['base_url'] || $changes['api_key'] !== $current['api_key'] || $changes['model'] !== $current['model'];

		if ( $changed_provider ) {
			// Keep the "tested" state when these exact values passed the test before saving.
			$changes['tested_at'] = get_transient( 'wphs_ai_tested' ) === self::fingerprint( $changes ) ? time() : 0;
		}

		self::update( $changes );
		self::redirect( 'saved', 'manual' );
	}

	private static function redirect( $msg, $tab = '' ) {
		$args = array(
			'page'      => 'hubpulse-writer',
			'wphs_msg'  => $msg,
		);

		if ( '' !== $tab ) {
			$args['tab'] = $tab;
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/* ───────────────────────── AJAX helpers ───────────────────────── */

	private static function ajax_guard( $cap = 'manage_options' ) {
		check_ajax_referer( 'wphs_writer', 'nonce' );

		if ( ! current_user_can( $cap ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'hubpulse' ) ), 403 );
		}
	}

	private static function post_text( $key, $max = 300 ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Every caller runs ajax_guard() first.
		return isset( $_POST[ $key ] ) ? substr( sanitize_text_field( wp_unslash( $_POST[ $key ] ) ), 0, $max ) : '';
	}

	private static function post_area( $key, $max = 1500 ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Every caller runs ajax_guard() first.
		return isset( $_POST[ $key ] ) ? substr( sanitize_textarea_field( wp_unslash( $_POST[ $key ] ) ), 0, $max ) : '';
	}

	private static function console_reply( array $res, $extra = array() ) {
		if ( ! $res['ok'] ) {
			wp_send_json_error( array( 'message' => $res['message'] ), $res['code'] >= 400 && $res['code'] < 600 ? $res['code'] : 502 );
		}

		wp_send_json_success( array_merge( $res['data'], $extra ) );
	}

	/* ───────────────────────── AJAX: common ───────────────────────── */

	public static function ajax_mode() {
		self::ajax_guard();

		$mode = self::post_text( 'mode', 20 );

		self::update( array( 'mode' => in_array( $mode, array( 'console', 'manual' ), true ) ? $mode : '' ) );
		wp_send_json_success();
	}

	/**
	 * Saves the site key from the AI Writer onboarding and checks it with the console.
	 */
	public static function ajax_site_key() {
		self::ajax_guard();

		$key = trim( self::post_text( 'key', 200 ) );

		if ( ! preg_match( '/^[A-Za-z0-9._\-]{16,200}$/', $key ) ) {
			wp_send_json_error( array( 'message' => __( 'This does not look like a site key. Copy the whole key from the console.', 'hubpulse' ) ), 400 );
		}

		WPHS_Settings::set_token( $key );

		$ping = WPHS_Cloud::ping();

		if ( ! $ping[0] ) {
			WPHS_Settings::set_token( '' );
			wp_send_json_error( array( 'message' => $ping[1] ), 400 );
		}

		self::update( array( 'mode' => 'console' ) );

		// One key does everything: reports, storage and article writing.
		$link = self::link_console();

		wp_send_json_success(
			array(
				'linked'  => $link['ok'],
				'message' => $link['ok'] ? $ping[1] : $link['message'],
			)
		);
	}

	/* ───────────────────────── AJAX: console mode ───────────────────────── */

	public static function ajax_console_status() {
		self::ajax_guard( 'publish_posts' );

		$res = WPHS_Cloud::api( 'GET', 'api/v1/site/content/status' );

		self::console_reply(
			$res,
			array(
				'linked' => WPHS_Content_API::is_linked(),
			)
		);
	}

	/**
	 * Connects article writing with the saved site key: asks the console for a content key,
	 * stores it, then lets the console call back. The site key is the only thing the user enters.
	 *
	 * @return array { ok: bool, message: string, data: array }
	 */
	public static function link_console() {
		if ( ! WPHS_Cloud::has_token() ) {
			return array( 'ok' => false, 'message' => __( 'Add the site key first.', 'hubpulse' ), 'data' => array() );
		}

		if ( WPHS_Content_API::standalone_connector_active() ) {
			return array( 'ok' => false, 'message' => __( 'Deactivate the separate "WP-Hub Content Connector" plugin first; Hubpulse now includes it.', 'hubpulse' ), 'data' => array() );
		}

		$secret = WPHS_Content_API::new_secret();
		$res    = WPHS_Cloud::api(
			'POST',
			'api/v1/site/content/link',
			array(
				'site_url'       => home_url( '/' ),
				'connection_key' => $secret,
			)
		);

		if ( ! $res['ok'] ) {
			return array( 'ok' => false, 'message' => $res['message'], 'data' => array() );
		}

		WPHS_Content_API::update(
			array(
				'secret'    => $secret,
				'linked_at' => time(),
			)
		);

		$test = WPHS_Cloud::api( 'POST', 'api/v1/site/content/test', array(), 45 );

		if ( ! $test['ok'] ) {
			return array(
				'ok'      => true,
				/* translators: %s: error message from the console. */
				'message' => sprintf( __( 'Connected, but the console could not reach this site yet: %s', 'hubpulse' ), $test['message'] ),
				'data'    => array(),
			);
		}

		return array( 'ok' => true, 'message' => '', 'data' => (array) $test['data'] );
	}

	public static function ajax_console_link() {
		self::ajax_guard();

		$link = self::link_console();

		if ( ! $link['ok'] ) {
			wp_send_json_error( array( 'message' => $link['message'] ), 502 );
		}

		wp_send_json_success( array_merge( $link['data'], array( 'linked' => true, 'message' => $link['message'] ) ) );
	}

	public static function ajax_console_unlink() {
		self::ajax_guard();

		WPHS_Cloud::api( 'DELETE', 'api/v1/site/content/link', array() );

		// One key connects everything, so disconnecting removes it (and the content key with it).
		WPHS_Settings::set_token( '' );

		wp_send_json_success();
	}

	public static function ajax_console_articles() {
		self::ajax_guard( 'publish_posts' );
		self::console_reply( WPHS_Cloud::api( 'GET', 'api/v1/site/content/articles' ) );
	}

	public static function ajax_console_article() {
		self::ajax_guard( 'publish_posts' );

		$id = absint( self::post_text( 'id', 20 ) );

		self::console_reply( WPHS_Cloud::api( 'GET', 'api/v1/site/content/articles/' . $id ) );
	}

	public static function ajax_console_create() {
		self::ajax_guard( 'publish_posts' );

		$body = array(
			'title'          => self::post_text( 'title', 200 ),
			'focus_keyword'  => self::post_text( 'keyword', 120 ),
			'tone'           => self::post_text( 'tone', 40 ),
			'length'         => self::post_text( 'length', 20 ),
			'audience'       => self::post_text( 'audience', 200 ),
			'instructions'   => self::post_area( 'instructions', 1500 ),
			'internal_links' => min( 10, absint( self::post_text( 'internal_links', 4 ) ) ),
			'include_faq'    => '1' === self::post_text( 'include_faq', 2 ),
			'use_images'     => '1' === self::post_text( 'use_images', 2 ),
		);
		$cat  = absint( self::post_text( 'category', 20 ) );

		if ( $cat ) {
			$body['category_ids'] = array( $cat );
		}

		if ( mb_strlen( $body['title'] ) < 4 ) {
			wp_send_json_error( array( 'message' => __( 'Enter the article topic or title.', 'hubpulse' ) ), 400 );
		}

		self::console_reply( WPHS_Cloud::api( 'POST', 'api/v1/site/content/articles', array_filter( $body, array( __CLASS__, 'not_empty' ) ), 30 ) );
	}

	public static function ajax_console_publish() {
		self::ajax_guard( 'publish_posts' );

		$id     = absint( self::post_text( 'id', 20 ) );
		$status = self::post_text( 'status', 20 );
		$status = in_array( $status, WPHS_Content_API::allowed_statuses(), true ) ? $status : 'draft';

		self::console_reply( WPHS_Cloud::api( 'POST', 'api/v1/site/content/articles/' . $id . '/publish', array( 'status' => $status ), 60 ) );
	}

	public static function not_empty( $value ) {
		return '' !== $value && null !== $value;
	}

	/* ───────────────────────── AJAX: manual mode ───────────────────────── */

	public static function ajax_ai_test() {
		self::ajax_guard();

		$s   = self::settings();
		$cfg = array(
			'provider' => self::post_text( 'provider', 40 ),
			'base_url' => esc_url_raw( self::post_text( 'base_url', 300 ), array( 'https', 'http' ) ),
			'api_key'  => self::post_text( 'api_key', 300 ),
			'model'    => self::post_text( 'model', 120 ),
		);

		// Test the saved key when the form still shows the masked placeholder, but only against the
		// saved address: the stored key is never sent to a URL typed into this request.
		if ( '' === $cfg['api_key'] || false !== strpos( $cfg['api_key'], '•' ) ) {
			if ( untrailingslashit( $cfg['base_url'] ) !== untrailingslashit( (string) $s['base_url'] ) ) {
				wp_send_json_error( array( 'message' => __( 'Enter the API key for this new address before testing it.', 'hubpulse' ) ), 400 );
			}

			$cfg['api_key'] = $s['api_key'];
		}

		$result = WPHS_AI_Provider::test( $cfg );

		if ( $result[0] ) {
			set_transient( 'wphs_ai_tested', self::fingerprint( $cfg ), HOUR_IN_SECONDS );

			if ( self::fingerprint( $cfg ) === self::fingerprint( $s ) ) {
				self::update( array( 'tested_at' => time() ) );
			}
		}

		$payload = array(
			'message' => $result[1],
			'models'  => $result[2],
		);

		if ( $result[0] ) {
			wp_send_json_success( $payload );
		}

		wp_send_json_error( $payload, 400 );
	}

	private static function fingerprint( array $cfg ) {
		$provider = isset( $cfg['provider'] ) ? (string) $cfg['provider'] : '';

		return md5( $provider . '|' . untrailingslashit( (string) $cfg['base_url'] ) . '|' . $cfg['api_key'] . '|' . $cfg['model'] );
	}

	public static function ajax_ai_generate() {
		self::ajax_guard( 'publish_posts' );

		if ( ! self::manual_ready() ) {
			wp_send_json_error( array( 'message' => __( 'Set up your AI provider first.', 'hubpulse' ) ), 400 );
		}

		$s     = self::settings();
		$brief = array(
			'title'        => self::post_text( 'title', 200 ),
			'keyword'      => self::post_text( 'keyword', 120 ),
			'tone'         => self::post_text( 'tone', 40 ),
			'length'       => self::post_text( 'length', 20 ),
			'audience'     => self::post_text( 'audience', 200 ),
			'instructions' => self::post_area( 'instructions', 1500 ),
			'links'        => min( 10, absint( self::post_text( 'internal_links', 4 ) ) ),
			'faq'          => '1' === self::post_text( 'include_faq', 2 ),
			'status'       => self::post_text( 'status', 20 ),
			'category'     => absint( self::post_text( 'category', 20 ) ),
		);

		if ( mb_strlen( $brief['title'] ) < 4 ) {
			wp_send_json_error( array( 'message' => __( 'Enter the article topic or title.', 'hubpulse' ) ), 400 );
		}

		$result = WPHS_AI_Provider::chat(
			$s,
			self::article_messages( $s, $brief ),
			array(
				'json'        => true,
				'temperature' => (float) $s['temperature'],
				'max_tokens'  => (int) $s['max_tokens'],
				'timeout'     => 200,
			)
		);

		if ( ! $result['ok'] ) {
			wp_send_json_error( array( 'message' => $result['message'] ), 502 );
		}

		$article = self::parse_article( $result['text'] );

		if ( ! $article ) {
			wp_send_json_error( array( 'message' => __( 'The model did not return a usable article. Try again, or raise the output token limit.', 'hubpulse' ) ), 502 );
		}

		$post_id = self::save_post( $article, $brief, $s );

		if ( is_wp_error( $post_id ) ) {
			wp_send_json_error( array( 'message' => $post_id->get_error_message() ), 500 );
		}

		wp_send_json_success(
			array(
				'post' => self::post_row( get_post( $post_id ) ),
				'html' => $article['content'],
			)
		);
	}

	/* ───────────────────────── manual generation ───────────────────────── */

	private static function article_messages( array $s, array $brief ) {
		$words  = array(
			'short'  => 800,
			'medium' => 1500,
			'long'   => 2500,
		);
		$length = isset( $words[ $brief['length'] ] ) ? $words[ $brief['length'] ] : $words[ $s['length'] ];
		$tones  = self::tones();
		$tone   = isset( $tones[ $brief['tone'] ] ) ? $brief['tone'] : $s['tone'];

		$site = array_filter(
			array(
				'Website'           => trim( $s['site_name'] . ' (' . $s['site_domain'] . ')' ),
				'Topic / niche'     => $s['site_topic'],
				'Business'          => $s['business'],
				'Products/services' => $s['products'],
				'Target audience'   => '' !== $brief['audience'] ? $brief['audience'] : $s['audience'],
				'Region'            => $s['region'],
				'Brand voice'       => $tone,
			)
		);

		$system = $s['system_prompt'] . "\n\nAbout the website:\n";

		foreach ( $site as $label => $value ) {
			$system .= '- ' . $label . ': ' . $value . "\n";
		}

		if ( '' !== trim( $s['rules'] ) ) {
			$system .= "\nHouse rules (always follow):\n" . $s['rules'] . "\n";
		}

		$links = $brief['links'] > 0 ? self::link_candidates( 25 ) : array();

		$user  = 'Write a complete article in ' . $s['language'] . ".\n";
		$user .= 'Topic or working title: ' . $brief['title'] . "\n";

		if ( '' !== $brief['keyword'] ) {
			$user .= 'Focus keyword: ' . $brief['keyword'] . "\n";
		}

		$user .= 'Target length: about ' . $length . " words.\n";

		if ( '' !== $brief['instructions'] ) {
			$user .= 'Extra instructions: ' . $brief['instructions'] . "\n";
		}

		if ( $links ) {
			$user .= "\nWhere it genuinely helps the reader, link to up to " . $brief['links'] . " of these existing pages on the same site (use the exact URL, natural anchor text, never invent other internal URLs):\n";

			foreach ( $links as $link ) {
				$user .= '- ' . $link['title'] . ' — ' . $link['url'] . "\n";
			}
		}

		$user .= "\nReturn ONLY a JSON object with these keys:\n";
		$user .= '{"title": "final H1 title (max 70 chars)", "slug": "short-latin-url-slug", "meta_title": "max 60 chars", ';
		$user .= '"meta_description": "120-155 chars", "focus_keyword": "...", "excerpt": "1-2 sentences", "tags": ["3-6 tags"], ';
		$user .= '"content_html": "the article body as HTML using only h2, h3, p, ul, ol, li, strong, em, a, table, thead, tbody, tr, th, td, blockquote. No h1, no inline styles, no scripts."';

		if ( $brief['faq'] ) {
			$user .= ', "faq": [{"q": "question", "a": "short answer"}] (4-6 items; also include them at the end of content_html under an H2)';
		}

		$user .= '}';

		return array(
			array(
				'role'    => 'system',
				'content' => $system,
			),
			array(
				'role'    => 'user',
				'content' => $user,
			),
		);
	}

	private static function link_candidates( $limit ) {
		$posts = get_posts(
			array(
				'post_type'      => array( 'post', 'page' ),
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);
		$out   = array();

		foreach ( $posts as $post ) {
			$out[] = array(
				'title' => get_the_title( $post ),
				'url'   => get_permalink( $post ),
			);
		}

		return $out;
	}

	private static function parse_article( $text ) {
		$text = trim( (string) $text );
		$text = preg_replace( '/^```(?:json)?\s*|\s*```$/i', '', $text );
		$data = json_decode( $text, true );

		if ( ! is_array( $data ) ) {
			$start = strpos( $text, '{' );
			$end   = strrpos( $text, '}' );

			if ( false !== $start && false !== $end && $end > $start ) {
				$data = json_decode( substr( $text, $start, $end - $start + 1 ), true );
			}
		}

		if ( ! is_array( $data ) || empty( $data['content_html'] ) || empty( $data['title'] ) ) {
			return null;
		}

		// Drop script/style blocks with their text before KSES, and the H1 (the post title is the H1).
		$content = preg_replace( '#<(script|style|iframe|noscript)\b[^>]*>.*?</\1>#is', '', (string) $data['content_html'] );
		$content = preg_replace( '#<h1[^>]*>.*?</h1>#is', '', $content );
		$content = wp_kses_post( $content );

		return array(
			'title'            => sanitize_text_field( (string) $data['title'] ),
			'slug'             => isset( $data['slug'] ) ? sanitize_title( (string) $data['slug'] ) : '',
			'meta_title'       => isset( $data['meta_title'] ) ? sanitize_text_field( (string) $data['meta_title'] ) : '',
			'meta_description' => isset( $data['meta_description'] ) ? sanitize_text_field( (string) $data['meta_description'] ) : '',
			'focus_keyword'    => isset( $data['focus_keyword'] ) ? sanitize_text_field( (string) $data['focus_keyword'] ) : '',
			'excerpt'          => isset( $data['excerpt'] ) ? sanitize_textarea_field( (string) $data['excerpt'] ) : '',
			'tags'             => isset( $data['tags'] ) && is_array( $data['tags'] ) ? array_slice( array_map( 'sanitize_text_field', array_map( 'strval', $data['tags'] ) ), 0, 8 ) : array(),
			'faq'              => isset( $data['faq'] ) && is_array( $data['faq'] ) ? $data['faq'] : array(),
			'content'          => $content,
		);
	}

	private static function save_post( array $article, array $brief, array $s ) {
		$status = 'draft';

		if ( 'pending' === $brief['status'] || ( '' === $brief['status'] && 'pending' === $s['default_status'] ) ) {
			$status = 'pending';
		}

		$author = (int) $s['author_id'];

		if ( ! $author || ! user_can( $author, 'edit_posts' ) ) {
			$author = get_current_user_id();
		}

		$category = $brief['category'] ? $brief['category'] : (int) $s['default_category'];
		$postarr  = array(
			'post_title'   => $article['title'],
			'post_content' => $article['content'],
			'post_excerpt' => $article['excerpt'],
			'post_status'  => $status,
			'post_type'    => 'post',
			'post_author'  => $author,
		);

		if ( '' !== $article['slug'] ) {
			$postarr['post_name'] = $article['slug'];
		}

		if ( $category ) {
			$postarr['post_category'] = array( $category );
		}

		$post_id = wp_insert_post( wp_slash( $postarr ), true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		if ( $article['tags'] ) {
			wp_set_post_tags( $post_id, $article['tags'], false );
		}

		WPHS_Content_SEO::write(
			$post_id,
			array(
				'title'         => $article['meta_title'],
				'description'   => $article['meta_description'],
				'focus_keyword' => '' !== $article['focus_keyword'] ? $article['focus_keyword'] : $brief['keyword'],
			)
		);

		if ( $article['faq'] ) {
			WPHS_Content_SEO::save_faq_schema( $post_id, $article['faq'] );
		}

		update_post_meta(
			$post_id,
			self::META,
			array(
				'source' => 'manual',
				'model'  => $s['model'],
				'at'     => time(),
			)
		);

		return $post_id;
	}

	public static function post_row( $post ) {
		if ( ! $post ) {
			return null;
		}

		return array(
			'id'      => $post->ID,
			'title'   => get_the_title( $post ),
			'status'  => get_post_status_object( $post->post_status ) ? get_post_status_object( $post->post_status )->label : $post->post_status,
			'edit'    => get_edit_post_link( $post->ID, 'raw' ),
			'preview' => get_preview_post_link( $post ),
			'date'    => get_the_date( 'Y/m/d H:i', $post ),
		);
	}

	public static function recent_manual_posts( $limit = 8 ) {
		$posts = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => array( 'draft', 'pending', 'publish', 'future', 'private' ),
				'posts_per_page' => $limit,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Small admin-only list.
				'meta_key'       => self::META,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		return array_filter( array_map( array( __CLASS__, 'post_row' ), $posts ) );
	}
}
