<?php
/**
 * Minimal client for OpenAI-compatible chat completion APIs, used by the manual
 * ("your own API key") mode of the AI Writer. Requests go straight from this site to
 * the provider the administrator configured; nothing passes through WP-Hub.
 *
 * @package Hubpulse
 */

defined( 'ABSPATH' ) || exit;

final class WPHS_AI_Provider {

	/**
	 * Known OpenAI-compatible endpoints. "model" is only a starting suggestion; the
	 * administrator can load the provider's live model list and pick another one.
	 */
	public static function presets() {
		// phpcs:disable PluginCheck.CodeAnalysis.AIProvider.DirectIntegration -- Optional presets for the "bring your own provider" mode; the WordPress AI Client is offered first when available and is the default on WordPress 7.0+.
		return array(
			'wordpress'  => array(
				'label'  => __( 'WordPress AI (Settings → Connectors)', 'hubpulse' ),
				'group'  => 'wp',
				'base'   => '',
				'model'  => '',
				'signup' => admin_url( 'options-connectors.php' ),
			),
			'openai'     => array(
				'label'  => 'OpenAI',
				'group'  => 'intl',
				'base'   => 'https://api.openai.com/v1',
				'model'  => 'gpt-4o-mini',
				'signup' => 'https://platform.openai.com/api-keys',
			),
			'anthropic'  => array(
				'label'  => 'Anthropic (Claude)',
				'group'  => 'intl',
				'base'   => 'https://api.anthropic.com/v1',
				'model'  => 'claude-sonnet-5-5',
				'signup' => 'https://console.anthropic.com/settings/keys',
			),
			'gemini'     => array(
				'label'  => 'Google Gemini',
				'group'  => 'intl',
				'base'   => 'https://generativelanguage.googleapis.com/v1beta/openai',
				'model'  => 'gemini-2.5-flash',
				'signup' => 'https://aistudio.google.com/apikey',
			),
			'openrouter' => array(
				'label'  => 'OpenRouter',
				'group'  => 'intl',
				'base'   => 'https://openrouter.ai/api/v1',
				'model'  => 'openai/gpt-4o-mini',
				'signup' => 'https://openrouter.ai/keys',
			),
			'deepseek'   => array(
				'label'  => 'DeepSeek',
				'group'  => 'intl',
				'base'   => 'https://api.deepseek.com/v1',
				'model'  => 'deepseek-chat',
				'signup' => 'https://platform.deepseek.com/api_keys',
			),
			'groq'       => array(
				'label'  => 'Groq',
				'group'  => 'intl',
				'base'   => 'https://api.groq.com/openai/v1',
				'model'  => 'llama-3.3-70b-versatile',
				'signup' => 'https://console.groq.com/keys',
			),
			'mistral'    => array(
				'label'  => 'Mistral',
				'group'  => 'intl',
				'base'   => 'https://api.mistral.ai/v1',
				'model'  => 'mistral-large-latest',
				'signup' => 'https://console.mistral.ai/api-keys',
			),
			'avalai'     => array(
				'label'  => 'AvalAI',
				'group'  => 'local',
				'base'   => 'https://api.avalai.ir/v1',
				'model'  => 'gpt-4o-mini',
				'signup' => 'https://avalai.ir',
			),
			'gapgpt'     => array(
				'label'  => 'GapGPT',
				'group'  => 'local',
				'base'   => 'https://api.gapgpt.app/v1',
				'model'  => 'gpt-4o-mini',
				'signup' => 'https://gapgpt.app',
			),
			'metis'      => array(
				'label'  => 'Metis AI',
				'group'  => 'local',
				'base'   => 'https://api.metisai.ir/openai/v1',
				'model'  => 'gpt-4o-mini',
				'signup' => 'https://metisai.ir',
			),
			'ollama'     => array(
				'label'  => __( 'Self-hosted (Ollama, LM Studio)', 'hubpulse' ),
				'group'  => 'custom',
				'base'   => 'http://localhost:11434/v1',
				'model'  => 'llama3.1',
				'signup' => '',
			),
			'custom'     => array(
				'label'  => __( 'Other (OpenAI-compatible)', 'hubpulse' ),
				'group'  => 'custom',
				'base'   => '',
				'model'  => '',
				'signup' => '',
			),
		);
		// phpcs:enable PluginCheck.CodeAnalysis.AIProvider.DirectIntegration
	}

	/**
	 * Whether the WordPress AI Client (WordPress 7.0+) can be used on this site.
	 */
	public static function wp_ai_available() {
		return function_exists( 'wp_ai_client_prompt' ) && function_exists( 'wp_supports_ai' ) && wp_supports_ai();
	}

	public static function is_wp( array $cfg ) {
		return isset( $cfg['provider'] ) && 'wordpress' === $cfg['provider'];
	}

	/**
	 * @param array $cfg Provider settings (provider, base_url, api_key).
	 * @return array{0:bool,1:string,2:array} ok, message, model ids.
	 */
	public static function test( array $cfg ) {
		if ( self::is_wp( $cfg ) ) {
			$chat = self::chat(
				$cfg,
				array(
					array(
						'role'    => 'user',
						'content' => 'Reply with the single word: OK',
					),
				),
				array(
					'max_tokens'  => 8,
					'temperature' => 0,
				)
			);

			return array( $chat['ok'], $chat['ok'] ? __( 'Connected. The AI provider configured in WordPress answered successfully.', 'hubpulse' ) : $chat['message'], array() );
		}

		$check = self::validate( $cfg );

		if ( true !== $check ) {
			return array( false, $check, array() );
		}

		$models = array();
		$res    = self::request( $cfg, 'GET', 'models', null, 20 );

		if ( $res['ok'] && isset( $res['data']['data'] ) && is_array( $res['data']['data'] ) ) {
			foreach ( $res['data']['data'] as $row ) {
				if ( isset( $row['id'] ) && is_string( $row['id'] ) ) {
					$models[] = $row['id'];
				}
			}

			sort( $models );
		}

		// A tiny completion proves the key, the model and the chat endpoint together.
		$chat = self::chat(
			$cfg,
			array(
				array(
					'role'    => 'user',
					'content' => 'Reply with the single word: OK',
				),
			),
			array(
				'max_tokens'  => 8,
				'temperature' => 0,
				'timeout'     => 30,
			)
		);

		if ( ! $chat['ok'] ) {
			return array( false, $chat['message'], array_slice( $models, 0, 300 ) );
		}

		/* translators: %s: model name. */
		$message = sprintf( __( 'Connected. The model "%s" answered successfully.', 'hubpulse' ), $cfg['model'] );

		return array( true, $message, array_slice( $models, 0, 300 ) );
	}

	/**
	 * @param array $cfg      Provider settings.
	 * @param array $messages Chat messages.
	 * @param array $opts     max_tokens, temperature, timeout, json.
	 * @return array{ok:bool,text:string,message:string,usage:array}
	 */
	public static function chat( array $cfg, array $messages, array $opts = array() ) {
		if ( self::is_wp( $cfg ) ) {
			return self::chat_wp( $messages, $opts );
		}

		$check = self::validate( $cfg );

		if ( true !== $check ) {
			return array(
				'ok'      => false,
				'text'    => '',
				'message' => $check,
				'usage'   => array(),
			);
		}

		$body = array(
			'model'       => (string) $cfg['model'],
			'messages'    => $messages,
			'temperature' => isset( $opts['temperature'] ) ? (float) $opts['temperature'] : 0.7,
			'max_tokens'  => isset( $opts['max_tokens'] ) ? (int) $opts['max_tokens'] : 4000,
		);

		if ( ! empty( $opts['json'] ) ) {
			$body['response_format'] = array( 'type' => 'json_object' );
		}

		$timeout = isset( $opts['timeout'] ) ? (int) $opts['timeout'] : 150;
		$res     = self::request( $cfg, 'POST', 'chat/completions', $body, $timeout );

		// Some providers do not support response_format; retry once without it.
		if ( ! $res['ok'] && ! empty( $body['response_format'] ) && in_array( $res['code'], array( 400, 422 ), true ) ) {
			unset( $body['response_format'] );
			$res = self::request( $cfg, 'POST', 'chat/completions', $body, $timeout );
		}

		if ( ! $res['ok'] ) {
			return array(
				'ok'      => false,
				'text'    => '',
				'message' => $res['message'],
				'usage'   => array(),
			);
		}

		$text = isset( $res['data']['choices'][0]['message']['content'] ) ? $res['data']['choices'][0]['message']['content'] : '';

		if ( is_array( $text ) ) {
			$parts = array();

			foreach ( $text as $part ) {
				if ( isset( $part['text'] ) ) {
					$parts[] = (string) $part['text'];
				}
			}

			$text = implode( '', $parts );
		}

		if ( '' === trim( (string) $text ) ) {
			return array(
				'ok'      => false,
				'text'    => '',
				'message' => __( 'The provider returned an empty answer. Try another model or a higher output token limit.', 'hubpulse' ),
				'usage'   => array(),
			);
		}

		return array(
			'ok'      => true,
			'text'    => (string) $text,
			'message' => '',
			'usage'   => isset( $res['data']['usage'] ) && is_array( $res['data']['usage'] ) ? $res['data']['usage'] : array(),
		);
	}

	/**
	 * Text generation through the WordPress AI Client, using whatever provider the site
	 * owner connected in Settings → Connectors.
	 */
	private static function chat_wp( array $messages, array $opts ) {
		$fail = static function ( $message ) {
			return array(
				'ok'      => false,
				'text'    => '',
				'message' => (string) $message,
				'usage'   => array(),
			);
		};

		if ( ! self::wp_ai_available() ) {
			return $fail( __( 'The WordPress AI Client is not available. It needs WordPress 7.0 or newer with AI enabled.', 'hubpulse' ) );
		}

		$system = '';
		$user   = '';

		foreach ( $messages as $message ) {
			if ( 'system' === $message['role'] ) {
				$system .= $message['content'] . "\n";
			} else {
				$user .= $message['content'] . "\n";
			}
		}

		try {
			$builder = wp_ai_client_prompt( trim( $user ) );

			if ( '' !== trim( $system ) ) {
				$builder = $builder->using_system_instruction( trim( $system ) );
			}

			if ( isset( $opts['temperature'] ) ) {
				$builder = $builder->using_temperature( (float) $opts['temperature'] );
			}

			if ( isset( $opts['max_tokens'] ) ) {
				$builder = $builder->using_max_tokens( (int) $opts['max_tokens'] );
			}

			if ( ! empty( $opts['json'] ) ) {
				$builder = $builder->as_json_response();
			}

			if ( ! $builder->is_supported_for_text_generation() ) {
				return $fail( __( 'No AI provider that can write text is connected in WordPress. Add one in Settings → Connectors.', 'hubpulse' ) );
			}

			$text = $builder->generate_text();
		} catch ( \Throwable $e ) {
			return $fail( substr( wp_strip_all_tags( $e->getMessage() ), 0, 300 ) );
		}

		if ( is_wp_error( $text ) ) {
			return $fail( $text->get_error_message() );
		}

		if ( '' === trim( (string) $text ) ) {
			return $fail( __( 'The provider returned an empty answer. Try again or raise the output token limit.', 'hubpulse' ) );
		}

		return array(
			'ok'      => true,
			'text'    => (string) $text,
			'message' => '',
			'usage'   => array(),
		);
	}

	/**
	 * @return true|string True or an error message.
	 */
	private static function validate( array $cfg ) {
		$base = isset( $cfg['base_url'] ) ? (string) $cfg['base_url'] : '';

		$scheme = (string) wp_parse_url( $base, PHP_URL_SCHEME );

		if ( '' === $base || ! filter_var( $base, FILTER_VALIDATE_URL ) || ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return __( 'Enter a valid API base URL.', 'hubpulse' );
		}

		if ( 0 !== strpos( $base, 'https://' ) && ! self::is_local_url( $base ) ) {
			return __( 'The API base URL must use HTTPS so the key is not sent in plain text.', 'hubpulse' );
		}

		if ( empty( $cfg['api_key'] ) && ! self::is_local_url( $base ) ) {
			return __( 'Enter the API key.', 'hubpulse' );
		}

		if ( empty( $cfg['model'] ) ) {
			return __( 'Enter a model name.', 'hubpulse' );
		}

		return true;
	}

	/**
	 * Plain HTTP is accepted only for self-hosted models on this machine or the local
	 * network (for example Ollama or LM Studio), where the key never crosses the internet.
	 */
	private static function is_local_url( $url ) {
		$host = trim( (string) wp_parse_url( $url, PHP_URL_HOST ), '[]' );

		if ( in_array( $host, array( 'localhost', '::1' ), true ) || '.local' === substr( $host, -6 ) ) {
			return true;
		}

		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return false === filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
		}

		return false;
	}

	private static function request( array $cfg, $method, $path, $body, $timeout ) {
		$url     = untrailingslashit( (string) $cfg['base_url'] ) . '/' . $path;
		$headers = array(
			'Accept' => 'application/json',
		);

		if ( ! empty( $cfg['api_key'] ) ) {
			$headers['Authorization'] = 'Bearer ' . $cfg['api_key'];
		}

		if ( isset( $cfg['provider'] ) && 'anthropic' === $cfg['provider'] ) {
			$headers['x-api-key']         = $cfg['api_key'];
			$headers['anthropic-version'] = '2023-06-01';
		}

		if ( isset( $cfg['provider'] ) && 'openrouter' === $cfg['provider'] ) {
			$headers['HTTP-Referer'] = home_url( '/' );
			$headers['X-Title']      = get_bloginfo( 'name' );
		}

		$args = array(
			'method'      => $method,
			'timeout'     => max( 5, (int) $timeout ),
			'redirection' => 2,
			'headers'     => $headers,
		);

		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'      => false,
				'code'    => 0,
				'data'    => array(),
				/* translators: %s: network error message. */
				'message' => sprintf( __( 'Could not reach the provider: %s', 'hubpulse' ), substr( $response->get_error_message(), 0, 200 ) ),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$data = is_array( $data ) ? $data : array();

		if ( $code >= 200 && $code < 300 ) {
			return array(
				'ok'      => true,
				'code'    => $code,
				'data'    => $data,
				'message' => '',
			);
		}

		$detail = '';

		if ( isset( $data['error']['message'] ) ) {
			$detail = (string) $data['error']['message'];
		} elseif ( isset( $data['error'] ) && is_string( $data['error'] ) ) {
			$detail = $data['error'];
		} elseif ( isset( $data['message'] ) && is_string( $data['message'] ) ) {
			$detail = $data['message'];
		} elseif ( isset( $data['detail'] ) && is_string( $data['detail'] ) ) {
			$detail = $data['detail'];
		}

		$hints = array(
			401 => __( 'The API key was rejected.', 'hubpulse' ),
			402 => __( 'The provider account has no credit left.', 'hubpulse' ),
			403 => __( 'Access denied by the provider (the key or your server region may not be allowed).', 'hubpulse' ),
			404 => __( 'The endpoint or model was not found; check the base URL and model name.', 'hubpulse' ),
			429 => __( 'Rate limit or quota reached at the provider.', 'hubpulse' ),
		);
		/* translators: %d: HTTP status code. */
		$message = isset( $hints[ $code ] ) ? $hints[ $code ] : sprintf( __( 'The provider returned HTTP %d.', 'hubpulse' ), $code );

		if ( '' !== $detail ) {
			$message .= ' ' . substr( wp_strip_all_tags( $detail ), 0, 300 );
		}

		return array(
			'ok'      => false,
			'code'    => $code,
			'data'    => $data,
			'message' => $message,
		);
	}
}
