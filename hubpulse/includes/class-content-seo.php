<?php
/**
 * SEO meta for articles written with Hubpulse: reads and writes Yoast SEO, Rank Math
 * and All in One SEO fields, and prints the FAQ schema / fallback description that
 * Hubpulse stored for its own articles.
 *
 * @package Hubpulse
 */

defined( 'ABSPATH' ) || exit;

final class WPHS_Content_SEO {

	public static function detect() {
		if ( defined( 'WPSEO_VERSION' ) ) {
			return 'yoast';
		}

		if ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) ) {
			return 'rankmath';
		}

		if ( defined( 'AIOSEO_VERSION' ) && class_exists( '\AIOSEO\Plugin\Common\Models\Post' ) ) {
			return 'aioseo';
		}

		return 'none';
	}

	public static function label() {
		$labels = array(
			'yoast'    => 'Yoast SEO',
			'rankmath' => 'Rank Math',
			'aioseo'   => 'All in One SEO',
		);
		$plugin = self::detect();

		return isset( $labels[ $plugin ] ) ? $labels[ $plugin ] : __( 'None detected', 'hubpulse' );
	}

	public static function read( $post_id ) {
		$out = array(
			'title'         => null,
			'description'   => null,
			'focus_keyword' => null,
		);

		switch ( self::detect() ) {
			case 'yoast':
				$out['title']         = get_post_meta( $post_id, '_yoast_wpseo_title', true );
				$out['description']   = get_post_meta( $post_id, '_yoast_wpseo_metadesc', true );
				$out['focus_keyword'] = get_post_meta( $post_id, '_yoast_wpseo_focuskw', true );
				break;
			case 'rankmath':
				$out['title']         = get_post_meta( $post_id, 'rank_math_title', true );
				$out['description']   = get_post_meta( $post_id, 'rank_math_description', true );
				$keywords             = (string) get_post_meta( $post_id, 'rank_math_focus_keyword', true );
				$out['focus_keyword'] = '' !== $keywords ? trim( explode( ',', $keywords )[0] ) : '';
				break;
			case 'aioseo':
				try {
					$row = \AIOSEO\Plugin\Common\Models\Post::getPost( $post_id );
				} catch ( \Throwable $e ) {
					$row = null;
				}

				if ( $row && $row->exists() ) {
					$out['title']       = $row->title;
					$out['description'] = $row->description;
					$phrases            = is_string( $row->keyphrases ) ? json_decode( $row->keyphrases, true ) : (array) $row->keyphrases;

					$out['focus_keyword'] = isset( $phrases['focus']['keyphrase'] ) ? $phrases['focus']['keyphrase'] : '';
				}
				break;
			default:
				$out['description'] = get_post_meta( $post_id, '_wphs_meta_description', true );
		}

		return $out;
	}

	/**
	 * @param int   $post_id Post ID.
	 * @param array $seo     title / description / focus_keyword.
	 * @return bool True when an SEO plugin received the values.
	 */
	public static function write( $post_id, array $seo ) {
		$title = isset( $seo['title'] ) ? sanitize_text_field( (string) $seo['title'] ) : '';
		$desc  = isset( $seo['description'] ) ? sanitize_text_field( (string) $seo['description'] ) : '';
		$kw    = isset( $seo['focus_keyword'] ) ? sanitize_text_field( (string) $seo['focus_keyword'] ) : '';

		switch ( self::detect() ) {
			case 'yoast':
				self::maybe_meta( $post_id, '_yoast_wpseo_title', $title );
				self::maybe_meta( $post_id, '_yoast_wpseo_metadesc', $desc );
				self::maybe_meta( $post_id, '_yoast_wpseo_focuskw', $kw );

				return true;
			case 'rankmath':
				self::maybe_meta( $post_id, 'rank_math_title', $title );
				self::maybe_meta( $post_id, 'rank_math_description', $desc );
				self::maybe_meta( $post_id, 'rank_math_focus_keyword', $kw );

				return true;
			case 'aioseo':
				try {
					$row = \AIOSEO\Plugin\Common\Models\Post::getPost( $post_id );
				} catch ( \Throwable $e ) {
					return false;
				}

				if ( ! $row->exists() ) {
					$row->post_id = $post_id;
				}

				if ( '' !== $title ) {
					$row->title = $title;
				}

				if ( '' !== $desc ) {
					$row->description = $desc;
				}

				if ( '' !== $kw ) {
					$row->keyphrases = wp_json_encode(
						array(
							'focus'      => array( 'keyphrase' => $kw ),
							'additional' => array(),
						)
					);
				}

				try {
					$row->save();
				} catch ( \Throwable $e ) {
					return false;
				}

				return true;
		}

		// No SEO plugin: keep the description so Hubpulse can print it in the page head.
		self::maybe_meta( $post_id, '_wphs_meta_description', $desc );

		return false;
	}

	private static function maybe_meta( $post_id, $key, $value ) {
		if ( '' !== $value ) {
			update_post_meta( $post_id, $key, $value );
		}
	}

	/**
	 * Stores a FAQPage schema after reducing it to plain question/answer text.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $schema  FAQPage JSON-LD as an array, or a list of {q, a} pairs.
	 */
	public static function save_faq_schema( $post_id, array $schema ) {
		$pairs = array();

		if ( isset( $schema['mainEntity'] ) && is_array( $schema['mainEntity'] ) ) {
			foreach ( $schema['mainEntity'] as $item ) {
				if ( isset( $item['name'], $item['acceptedAnswer']['text'] ) ) {
					$pairs[] = array( $item['name'], $item['acceptedAnswer']['text'] );
				}
			}
		} else {
			foreach ( $schema as $item ) {
				if ( is_array( $item ) && isset( $item['q'], $item['a'] ) ) {
					$pairs[] = array( $item['q'], $item['a'] );
				}
			}
		}

		$entities = array();

		foreach ( array_slice( $pairs, 0, 15 ) as $pair ) {
			$question = sanitize_text_field( (string) $pair[0] );
			$answer   = sanitize_textarea_field( wp_strip_all_tags( (string) $pair[1] ) );

			if ( '' !== $question && '' !== $answer ) {
				$entities[] = array(
					'@type'          => 'Question',
					'name'           => $question,
					'acceptedAnswer' => array(
						'@type' => 'Answer',
						'text'  => $answer,
					),
				);
			}
		}

		if ( $entities ) {
			update_post_meta( $post_id, '_wphs_faq', $entities );
		} else {
			delete_post_meta( $post_id, '_wphs_faq' );
		}
	}

	public static function print_head() {
		if ( ! is_singular( 'post' ) ) {
			return;
		}

		$post_id = get_the_ID();

		if ( 'none' === self::detect() ) {
			$desc = (string) get_post_meta( $post_id, '_wphs_meta_description', true );

			if ( '' !== $desc ) {
				echo '<meta name="description" content="' . esc_attr( $desc ) . '">' . "\n";
			}
		}

		$faq = get_post_meta( $post_id, '_wphs_faq', true );

		// Articles published by the standalone connector stored the schema as JSON.
		if ( ! $faq ) {
			$legacy = json_decode( (string) get_post_meta( $post_id, '_wphub_faq_schema', true ), true );
			$faq    = is_array( $legacy ) && isset( $legacy['mainEntity'] ) ? $legacy['mainEntity'] : array();
		}

		if ( ! is_array( $faq ) || ! $faq ) {
			return;
		}

		$data = array(
			'@context'   => 'https://schema.org',
			'@type'      => 'FAQPage',
			'mainEntity' => array_values( $faq ),
		);

		// JSON_HEX_TAG keeps "</script>" inside an answer from closing the tag.
		echo '<script type="application/ld+json">' . wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG ) . "</script>\n";
	}
}
