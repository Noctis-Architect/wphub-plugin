<?php
/**
 * Signed REST routes that let the WP-Hub console read this site's content and send
 * articles to it (namespace wphub-content/v1).
 *
 * Every request is a JSON POST signed with HMAC-SHA256 over
 * "{timestamp}\n{nonce}\n{METHOD}\n{route}\n{sha256(body)}" using the content key the
 * console issued for this site. Requests older than five minutes or re-using a nonce
 * are rejected. Nothing is exposed until the site owner connects the console.
 *
 * @package Hubpulse
 */

defined( 'ABSPATH' ) || exit;

final class WPHS_Content_API {

	const NS       = 'wphub-content/v1';
	const OPTION   = 'wphs_content';
	const MAX_SKEW = 300;

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );

		if ( ! self::standalone_connector_active() ) {
			add_action( 'wp_head', array( 'WPHS_Content_SEO', 'print_head' ), 30 );
		}
	}

	/**
	 * The standalone "WP-Hub Content Connector" plugin uses the same namespace; when it
	 * is active it keeps serving the routes and Hubpulse stays out of the way.
	 */
	public static function standalone_connector_active() {
		return class_exists( 'WPHub_CC_REST' );
	}

	public static function defaults() {
		return array(
			'secret'           => '',
			'allow_publish'    => false,
			'author_id'        => 0,
			'default_category' => 0,
			'linked_at'        => 0,
			'log'              => array(),
		);
	}

	public static function settings() {
		$saved = get_option( self::OPTION, array() );

		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	public static function update( array $changes ) {
		$settings = array_merge( self::settings(), $changes );

		update_option( self::OPTION, $settings, false );

		return $settings;
	}

	public static function is_linked() {
		return '' !== (string) self::settings()['secret'];
	}

	/**
	 * Generates the content signing key inside WordPress. It is sent to the console once over
	 * HTTPS and never comes back, so a leaked site key alone cannot obtain it.
	 */
	public static function new_secret() {
		return 'wphc_' . wp_generate_password( 48, false, false );
	}

	public static function valid_secret( $secret ) {
		return (bool) preg_match( '/^wphc_[A-Za-z0-9_\-]{30,120}$/', (string) $secret );
	}

	public static function register_routes() {
		if ( self::standalone_connector_active() ) {
			return;
		}

		$routes = array( 'ping', 'posts', 'terms', 'media', 'publish', 'update' );

		foreach ( $routes as $route ) {
			register_rest_route(
				self::NS,
				'/' . $route,
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'route_' . $route ),
					'permission_callback' => array( __CLASS__, 'verify' ),
				)
			);
		}
	}

	/**
	 * HMAC verification with replay protection.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function verify( WP_REST_Request $request ) {
		$secret = (string) self::settings()['secret'];

		if ( '' === $secret ) {
			return new WP_Error( 'wphub_not_configured', __( 'The console is not connected to this site.', 'hubpulse' ), array( 'status' => 403 ) );
		}

		$ts        = (string) $request->get_header( 'x_wphub_timestamp' );
		$nonce     = (string) $request->get_header( 'x_wphub_nonce' );
		$signature = strtolower( (string) $request->get_header( 'x_wphub_signature' ) );

		if ( '' === $ts || '' === $signature || ! ctype_digit( $ts ) || ! preg_match( '/^[a-f0-9]{16,64}$/', $nonce ) ) {
			return new WP_Error( 'wphub_unsigned', __( 'The request is not signed.', 'hubpulse' ), array( 'status' => 401 ) );
		}

		if ( abs( time() - (int) $ts ) > self::MAX_SKEW ) {
			return new WP_Error( 'wphub_clock', __( 'The server clock differs too much from the console; check the server time.', 'hubpulse' ), array( 'status' => 401 ) );
		}

		$route    = $request->get_route();
		$message  = $ts . "\n" . $nonce . "\n" . strtoupper( $request->get_method() ) . "\n" . $route . "\n" . hash( 'sha256', (string) $request->get_body() );
		$expected = hash_hmac( 'sha256', $message, $secret );

		if ( ! hash_equals( $expected, $signature ) ) {
			// Anyone can send a bad signature: log at most once a minute so unauthenticated
			// requests cannot turn into a stream of database writes.
			if ( ! get_transient( 'wphs_cn_reject_logged' ) ) {
				set_transient( 'wphs_cn_reject_logged', 1, MINUTE_IN_SECONDS );
				self::log( 'rejected', $route );
			}

			return new WP_Error( 'wphub_bad_signature', __( 'Invalid request signature; reconnect the console.', 'hubpulse' ), array( 'status' => 401 ) );
		}

		$nonce_key = 'wphs_cn_' . md5( $nonce );

		if ( get_transient( $nonce_key ) ) {
			return new WP_Error( 'wphub_replay', __( 'Repeated request rejected.', 'hubpulse' ), array( 'status' => 401 ) );
		}

		set_transient( $nonce_key, 1, self::MAX_SKEW * 2 );
		self::log( 'ok', $route );

		return true;
	}

	private static function log( $result, $route ) {
		$settings = self::settings();
		$log      = is_array( $settings['log'] ) ? $settings['log'] : array();

		array_unshift(
			$log,
			array(
				'time'   => time(),
				'route'  => substr( (string) $route, 0, 80 ),
				'result' => 'ok' === $result ? 'ok' : 'rejected',
			)
		);

		self::update( array( 'log' => array_slice( $log, 0, 10 ) ) );
	}

	private static function author_id() {
		$author = (int) self::settings()['author_id'];

		if ( $author && user_can( $author, 'edit_posts' ) ) {
			return $author;
		}

		$admins = get_users(
			array(
				'role'   => 'administrator',
				'number' => 1,
				'fields' => 'ID',
			)
		);

		return $admins ? (int) $admins[0] : 0;
	}

	public static function allowed_statuses() {
		return self::settings()['allow_publish'] ? array( 'draft', 'pending', 'publish', 'future' ) : array( 'draft', 'pending' );
	}

	public static function route_ping() {
		$author = get_userdata( self::author_id() );

		return rest_ensure_response(
			array(
				'ok'               => true,
				'site_name'        => get_bloginfo( 'name' ),
				'site_url'         => home_url( '/' ),
				'wp_version'       => get_bloginfo( 'version' ),
				'plugin_version'   => WPHS_VERSION,
				'seo_plugin'       => WPHS_Content_SEO::detect(),
				'can_publish'      => in_array( 'publish', self::allowed_statuses(), true ),
				'allowed_statuses' => self::allowed_statuses(),
				'author'           => $author ? array(
					'id'   => $author->ID,
					'name' => $author->display_name,
				) : null,
				'locale'           => get_locale(),
			)
		);
	}

	public static function route_posts( WP_REST_Request $request ) {
		$p         = (array) $request->get_json_params();
		$requested = isset( $p['post_type'] ) ? sanitize_key( $p['post_type'] ) : 'post';
		// Products are offered only on WooCommerce shops; they are link and image targets for articles.
		$post_type = in_array( $requested, array( 'page', 'product' ), true ) && post_type_exists( $requested ) ? $requested : 'post';
		$per_page  = max( 1, min( 100, isset( $p['per_page'] ) ? (int) $p['per_page'] : 50 ) );
		$page      = max( 1, isset( $p['page'] ) ? (int) $p['page'] : 1 );
		$query     = new WP_Query(
			array(
				'post_type'      => $post_type,
				'post_status'    => 'publish',
				'posts_per_page' => $per_page,
				'paged'          => $page,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
		$seo_known = 'none' !== WPHS_Content_SEO::detect();
		$items     = array();

		foreach ( $query->posts as $post ) {
			$cats = array();
			$tags = array();

			if ( 'post' === $post_type ) {
				foreach ( get_the_category( $post->ID ) as $cat ) {
					$cats[] = array(
						'id'   => $cat->term_id,
						'name' => $cat->name,
					);
				}

				foreach ( (array) get_the_tags( $post->ID ) as $tag ) {
					if ( $tag ) {
						$tags[] = array(
							'id'   => $tag->term_id,
							'name' => $tag->name,
						);
					}
				}
			}

			$items[] = array(
				'id'             => $post->ID,
				'type'           => $post->post_type,
				'title'          => get_the_title( $post ),
				'slug'           => $post->post_name,
				'link'           => get_permalink( $post ),
				'status'         => $post->post_status,
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter: renders blocks/shortcodes so links and images are real.
				'content'        => apply_filters( 'the_content', $post->post_content ),
				'excerpt'        => $post->post_excerpt,
				'date'           => get_post_time( 'c', true, $post ),
				'modified'       => get_post_modified_time( 'c', true, $post ),
				'categories'     => $cats,
				'tags'           => $tags,
				'featured_media' => (int) get_post_thumbnail_id( $post->ID ),
				'seo'            => WPHS_Content_SEO::read( $post->ID ),
				'seo_known'      => $seo_known,
			);
		}

		return rest_ensure_response(
			array(
				'items'       => $items,
				'total'       => (int) $query->found_posts,
				'total_pages' => (int) $query->max_num_pages,
			)
		);
	}

	public static function route_terms() {
		$map  = static function ( $term ) {
			return array(
				'id'     => $term->term_id,
				'name'   => $term->name,
				'slug'   => $term->slug,
				'count'  => (int) $term->count,
				'parent' => (int) $term->parent,
			);
		};
		$cats = get_terms(
			array(
				'taxonomy'   => 'category',
				'hide_empty' => false,
				'number'     => 300,
			)
		);
		$tags = get_terms(
			array(
				'taxonomy'   => 'post_tag',
				'hide_empty' => false,
				'number'     => 300,
				'orderby'    => 'count',
				'order'      => 'DESC',
			)
		);

		$authors = array();

		foreach ( get_users(
			array(
				'capability' => 'edit_posts',
				'number'     => 50,
			)
		) as $user ) {
			$authors[] = array(
				'id'   => $user->ID,
				'name' => $user->display_name,
			);
		}

		return rest_ensure_response(
			array(
				'categories' => is_wp_error( $cats ) ? array() : array_map( $map, $cats ),
				'tags'       => is_wp_error( $tags ) ? array() : array_map( $map, $tags ),
				'authors'    => $authors,
			)
		);
	}

	public static function route_media( WP_REST_Request $request ) {
		$p        = (array) $request->get_json_params();
		$per_page = max( 1, min( 100, isset( $p['per_page'] ) ? (int) $p['per_page'] : 100 ) );
		$page     = max( 1, isset( $p['page'] ) ? (int) $p['page'] : 1 );
		$query    = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => 'image',
				'posts_per_page' => $per_page,
				'paged'          => $page,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
		$items    = array();

		foreach ( $query->posts as $att ) {
			// Media attached to drafts or private posts is not public yet; do not reveal it.
			if ( $att->post_parent && 'publish' !== get_post_status( $att->post_parent ) ) {
				continue;
			}

			$src = wp_get_attachment_image_src( $att->ID, 'large' );

			if ( ! $src ) {
				continue;
			}

			$items[] = array(
				'id'      => $att->ID,
				'url'     => $src[0],
				'width'   => (int) $src[1],
				'height'  => (int) $src[2],
				'alt'     => (string) get_post_meta( $att->ID, '_wp_attachment_image_alt', true ),
				'title'   => get_the_title( $att ),
				'caption' => $att->post_excerpt,
				'mime'    => $att->post_mime_type,
			);
		}

		return rest_ensure_response(
			array(
				'items'       => $items,
				'total_pages' => (int) $query->max_num_pages,
			)
		);
	}

	public static function route_publish( WP_REST_Request $request ) {
		$p       = (array) $request->get_json_params();
		$postarr = self::prepare_postarr( $p, false );

		if ( is_wp_error( $postarr ) ) {
			return $postarr;
		}

		$post_id = wp_insert_post( wp_slash( $postarr ), true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		return self::saved_response( $post_id, $p );
	}

	public static function route_update( WP_REST_Request $request ) {
		$p       = (array) $request->get_json_params();
		$post_id = isset( $p['id'] ) ? (int) $p['id'] : 0;

		// Only posts that the console created may be overwritten.
		if ( ! $post_id || ! get_post( $post_id ) || ! get_post_meta( $post_id, '_wphub_article_id', true ) ) {
			return new WP_Error( 'wphub_not_found', __( 'This post was not created by WP-Hub.', 'hubpulse' ), array( 'status' => 404 ) );
		}

		$postarr = self::prepare_postarr( $p, true );

		if ( is_wp_error( $postarr ) ) {
			return $postarr;
		}

		$postarr['ID'] = $post_id;
		$result        = wp_update_post( wp_slash( $postarr ), true );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return self::saved_response( $post_id, $p );
	}

	private static function saved_response( $post_id, array $p ) {
		$seo = self::after_save( $post_id, $p );

		return rest_ensure_response(
			array(
				'id'          => $post_id,
				'link'        => get_permalink( $post_id ),
				'status'      => get_post_status( $post_id ),
				'seo_applied' => $seo,
			)
		);
	}

	private static function prepare_postarr( array $p, $is_update ) {
		$status = isset( $p['status'] ) ? sanitize_key( $p['status'] ) : 'draft';

		if ( ! in_array( $status, self::allowed_statuses(), true ) ) {
			return new WP_Error( 'wphub_status_forbidden', __( 'Direct publishing is turned off in Hubpulse; send the article as a draft.', 'hubpulse' ), array( 'status' => 403 ) );
		}

		$postarr = array(
			'post_title'   => sanitize_text_field( isset( $p['title'] ) ? (string) $p['title'] : '' ),
			'post_content' => wp_kses_post( isset( $p['content'] ) ? (string) $p['content'] : '' ),
			'post_excerpt' => sanitize_textarea_field( isset( $p['excerpt'] ) ? (string) $p['excerpt'] : '' ),
			'post_status'  => $status,
			'post_type'    => 'post',
		);

		if ( '' === $postarr['post_title'] || '' === $postarr['post_content'] ) {
			return new WP_Error( 'wphub_invalid', __( 'The article title and content are required.', 'hubpulse' ), array( 'status' => 400 ) );
		}

		if ( ! empty( $p['slug'] ) ) {
			$postarr['post_name'] = sanitize_title( (string) $p['slug'] );
		}

		if ( ! $is_update ) {
			$postarr['post_author'] = self::author_id();
		}

		if ( 'future' === $status && ! empty( $p['date_gmt'] ) ) {
			$time = strtotime( (string) $p['date_gmt'] . ' UTC' );

			if ( $time ) {
				$gmt                      = gmdate( 'Y-m-d H:i:s', $time );
				$postarr['post_date_gmt'] = $gmt;
				$postarr['post_date']     = get_date_from_gmt( $gmt );
			}
		}

		$cats = array_values( array_filter( array_map( 'intval', isset( $p['categories'] ) ? (array) $p['categories'] : array() ) ) );

		if ( ! $cats && self::settings()['default_category'] ) {
			$cats = array( (int) self::settings()['default_category'] );
		}

		if ( $cats ) {
			$postarr['post_category'] = $cats;
		}

		return $postarr;
	}

	private static function after_save( $post_id, array $p ) {
		if ( ! empty( $p['tags'] ) && is_array( $p['tags'] ) ) {
			wp_set_post_tags( $post_id, array_slice( array_map( 'sanitize_text_field', $p['tags'] ), 0, 10 ), false );
		}

		$media = isset( $p['featured_media'] ) ? (int) $p['featured_media'] : 0;

		if ( $media && 'attachment' === get_post_type( $media ) ) {
			set_post_thumbnail( $post_id, $media );
		}

		$seo_applied = WPHS_Content_SEO::write( $post_id, isset( $p['seo'] ) ? (array) $p['seo'] : array() );

		if ( ! empty( $p['faq_schema'] ) && is_array( $p['faq_schema'] ) ) {
			WPHS_Content_SEO::save_faq_schema( $post_id, $p['faq_schema'] );
		}

		if ( ! empty( $p['source']['console_article_id'] ) ) {
			update_post_meta( $post_id, '_wphub_article_id', (int) $p['source']['console_article_id'] );
		}

		return $seo_applied;
	}
}
