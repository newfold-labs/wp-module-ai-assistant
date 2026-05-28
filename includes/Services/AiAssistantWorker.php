<?php
/**
 * Flat-prompt proxy to the Hiive AI Worker.
 *
 * @package NewfoldLabs\WP\Module\AIAssistant\Services
 */

namespace NewfoldLabs\WP\Module\AIAssistant\Services;

use NewfoldLabs\WP\Module\Data\HiiveConnection;

/**
 * Sends assembled prompts to NFD_AI_BASE/ai-site-assistant/ask.
 */
class AiAssistantWorker {

	const IDENTIFIER = 'ai-site-assistant';

	const ENDPOINT = 'ai-site-assistant/ask';

	/**
	 * Request an answer from the Worker.
	 *
	 * @param string $prompt Assembled flat prompt.
	 * @return string|\WP_Error Raw model text or error.
	 */
	public function ask( $prompt ) {
		if ( ! defined( 'NFD_AI_BASE' ) ) {
			return new \WP_Error(
				'configuration_error',
				__( 'AI service is not configured.', 'wp-module-ai-assistant' ),
				array( 'status' => 503 )
			);
		}

		$hiive_token = HiiveConnection::get_auth_token();
		if ( ! $hiive_token ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You are not authorized to make this call.', 'wp-module-ai-assistant' ),
				array( 'status' => 403 )
			);
		}

		$response = wp_remote_post(
			trailingslashit( NFD_AI_BASE ) . self::ENDPOINT,
			array(
				'method'  => 'POST',
				'headers' => array(
					'Content-Type'    => 'application/json',
					'X-Newfold-Brand' => $this->get_brand(),
				),
				'timeout' => 60,
				'body'    => wp_json_encode(
					array(
						'hiivetoken' => $hiive_token,
						'prompt'     => $prompt,
						'identifier' => self::IDENTIFIER,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new \WP_Error(
				'ai_service_error',
				__( 'We are unable to process the request at this moment.', 'wp-module-ai-assistant' ),
				array( 'status' => $code ?: 502 )
			);
		}

		$parsed = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( isset( $parsed['status'] ) && 'Failure' === $parsed['status'] ) {
			return new \WP_Error(
				'ai_service_error',
				__( 'We are unable to process the request at this moment.', 'wp-module-ai-assistant' ),
				array( 'status' => 502 )
			);
		}

		if ( ! empty( $parsed['payload']['choices'] ) && is_array( $parsed['payload']['choices'] ) ) {
			$first = $parsed['payload']['choices'][0] ?? null;
			if ( is_array( $first ) && ! empty( $first['text'] ) ) {
				return (string) $first['text'];
			}
		}

		if ( ! empty( $parsed['payload']['text'] ) ) {
			return (string) $parsed['payload']['text'];
		}

		return new \WP_Error(
			'ai_service_error',
			__( 'Unexpected AI service response.', 'wp-module-ai-assistant' ),
			array( 'status' => 502 )
		);
	}

	/**
	 * Resolve brand header for Worker requests.
	 *
	 * @return string
	 */
	private function get_brand() {
		$brand = get_option( 'mm_brand', 'web' );
		return apply_filters( 'newfold_ai_sitegen_brand', $brand );
	}
}
