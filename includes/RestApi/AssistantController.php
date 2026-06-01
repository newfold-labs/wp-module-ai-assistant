<?php
/**
 * Public REST endpoint for visitor questions.
 *
 * @package NewfoldLabs\WP\Module\AIAssistant\RestApi
 */

namespace NewfoldLabs\WP\Module\AIAssistant\RestApi;

use NewfoldLabs\WP\Module\AIAssistant\Services\AiAssistantWorker;
use NewfoldLabs\WP\Module\AIAssistant\Services\CapabilityGate;
use NewfoldLabs\WP\Module\AIAssistant\Services\ConversationStore;
use NewfoldLabs\WP\Module\AIAssistant\Services\KnowledgeStore;
use NewfoldLabs\WP\Module\AIAssistant\Services\PromptAssembler;
use NewfoldLabs\WP\Module\AIAssistant\Services\ResponseParser;
use NewfoldLabs\WP\Module\AIAssistant\Services\Retriever;
use WP_REST_Request;
use WP_REST_Server;

/**
 * Handles POST /newfold-ai-assistant/v1/ask.
 */
class AssistantController {

	const NAMESPACE = 'newfold-ai-assistant/v1';

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/ask',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'ask' ),
				'permission_callback' => array( $this, 'permission_callback' ),
				'args'                => array(
					'question' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'conversation_id' => array(
						'required' => false,
						'type'     => 'string',
					),
					'preview' => array(
						'required' => false,
						'type'     => 'boolean',
					),
				),
			)
		);
	}

	/**
	 * Permission callback.
	 *
	 * @return bool|\WP_Error
	 */
	public function permission_callback() {
		return CapabilityGate::rest_permission();
	}

	/**
	 * Handle an ask request.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function ask( WP_REST_Request $request ) {
		$question = trim( (string) $request->get_param( 'question' ) );
		$preview  = (bool) $request->get_param( 'preview' );

		if ( '' === $question ) {
			return new \WP_Error( 'invalid_question', __( 'Question is required.', 'wp-module-ai-assistant' ), array( 'status' => 400 ) );
		}

		if ( strlen( $question ) > 500 ) {
			return new \WP_Error( 'invalid_question', __( 'Question is too long.', 'wp-module-ai-assistant' ), array( 'status' => 400 ) );
		}

		if ( ! $preview || ! current_user_can( 'manage_options' ) ) {
			if ( $this->is_ip_rate_limited() ) {
				return rest_ensure_response(
					array(
						'answer'          => __( 'Too many requests. Please wait a moment and try again.', 'wp-module-ai-assistant' ),
						'suggestions'     => array(),
						'ctas'            => array(),
						'sources'         => array(),
						'needs_human'     => false,
						'conversation_id' => $request->get_param( 'conversation_id' ) ?: '',
						'throttled'       => true,
					)
				);
			}
		}

		$conversation_id = sanitize_text_field( (string) $request->get_param( 'conversation_id' ) );
		if ( '' === $conversation_id ) {
			$conversation_id = wp_generate_uuid4();
		}

		if ( ! $preview && $this->is_conversation_rate_limited( $conversation_id ) ) {
			return rest_ensure_response(
				array(
					'answer'          => __( 'Too many messages in this conversation. Please start again.', 'wp-module-ai-assistant' ),
					'suggestions'     => array(),
					'ctas'            => array(),
					'sources'         => array(),
					'needs_human'     => false,
					'conversation_id' => $conversation_id,
					'throttled'       => true,
				)
			);
		}

		$store        = new ConversationStore();
		$conversation = $store->get( $conversation_id ) ?: $store->create();
		$conversation = $store->sync_brief_version( $conversation );

		$retriever = new Retriever();
		$retrieved = $retriever->top_k( $question, 20 );

		$prompt = ( new PromptAssembler() )->build( $question, $retrieved, $conversation );
		$worker = new AiAssistantWorker();
		$raw    = $worker->ask( $prompt );

		if ( is_wp_error( $raw ) ) {
			$this->record_worker_failure();
			return rest_ensure_response( $this->build_error_response( $conversation_id ) );
		}

		$this->reset_worker_failures();

		$snapshot = KnowledgeStore::get_snapshot();
		$ctas     = ! empty( $snapshot['ctas_catalog'] ) ? $snapshot['ctas_catalog'] : array();
		$parsed   = ( new ResponseParser() )->parse( $raw, $ctas, $retrieved );

		$conversation = $store->append_message( $conversation_id, $conversation, 'user', $question );
		$store->append_message( $conversation_id, $conversation, 'assistant', $parsed['answer'] );

		if ( ! $preview ) {
			$this->increment_ip_rate_limit();
			$this->increment_conversation_rate_limit( $conversation_id );
		}

		return rest_ensure_response(
			array_merge(
				$parsed,
				array(
					'conversation_id' => $conversation_id,
					'throttled'       => false,
				)
			)
		);
	}

	/**
	 * Build a visitor-friendly fallback when the Worker is unavailable.
	 *
	 * @param string $conversation_id Conversation UUID.
	 * @return array<string, mixed>
	 */
	private function build_error_response( $conversation_id ) {
		$has_contact = '' !== KnowledgeStore::get_contact_page_url();

		return array(
			'answer'          => $has_contact
				? __( 'The assistant is temporarily unavailable. Please try again shortly or contact us for help.', 'wp-module-ai-assistant' )
				: __( 'The assistant is temporarily unavailable. Please try again shortly.', 'wp-module-ai-assistant' ),
			'suggestions'     => $this->retry_suggestions(),
			'ctas'            => array(),
			'sources'         => array(),
			'needs_human'     => $has_contact,
			'conversation_id' => $conversation_id,
			'throttled'       => false,
		);
	}

	/**
	 * Suggestion chips shown after a failed Worker call.
	 *
	 * @return array<int, string>
	 */
	private function retry_suggestions() {
		$snapshot = KnowledgeStore::get_snapshot();
		$mode     = ! empty( $snapshot['site_mode'] ) ? $snapshot['site_mode'] : 'business';

		if ( 'personal_blog' === $mode ) {
			return array(
				__( 'What do you write about?', 'wp-module-ai-assistant' ),
				__( 'Show me recent posts', 'wp-module-ai-assistant' ),
			);
		}

		return array(
			__( 'What services do you offer?', 'wp-module-ai-assistant' ),
			__( 'Where can I learn more?', 'wp-module-ai-assistant' ),
		);
	}

	/**
	 * Record a Worker failure and maybe open the circuit.
	 *
	 * @return void
	 */
	private function record_worker_failure() {
		$count = (int) get_transient( 'nfd_aia_failures' );
		++$count;
		set_transient( 'nfd_aia_failures', $count, 10 * MINUTE_IN_SECONDS );
		$threshold = (int) apply_filters( 'nfd_ai_assistant_circuit_breaker_threshold', 3 );
		if ( $count >= $threshold ) {
			set_transient( 'nfd_aia_circuit_open', 1, 10 * MINUTE_IN_SECONDS );
		}
	}

	/**
	 * Reset failure counter after a successful call.
	 *
	 * @return void
	 */
	private function reset_worker_failures() {
		delete_transient( 'nfd_aia_failures' );
		delete_transient( 'nfd_aia_circuit_open' );
	}

	/**
	 * Per-IP rate limit check.
	 *
	 * @return bool
	 */
	private function is_ip_rate_limited() {
		$key   = 'nfd_aia_ip_' . md5( $this->get_client_ip() );
		$count = (int) get_transient( $key );
		$limit = (int) apply_filters( 'nfd_ai_assistant_ip_rate_limit', 20 );
		return $count >= $limit;
	}

	/**
	 * Increment per-IP counter.
	 *
	 * @return void
	 */
	private function increment_ip_rate_limit() {
		$key   = 'nfd_aia_ip_' . md5( $this->get_client_ip() );
		$count = (int) get_transient( $key );
		set_transient( $key, $count + 1, 10 * MINUTE_IN_SECONDS );
	}

	/**
	 * Per-conversation rate limit check.
	 *
	 * @param string $conversation_id Conversation UUID.
	 * @return bool
	 */
	private function is_conversation_rate_limited( $conversation_id ) {
		$key   = 'nfd_aia_conv_rate_' . md5( $conversation_id );
		$count = (int) get_transient( $key );
		$limit = (int) apply_filters( 'nfd_ai_assistant_conversation_rate_limit', 60 );
		return $count >= $limit;
	}

	/**
	 * Increment per-conversation counter.
	 *
	 * @param string $conversation_id Conversation UUID.
	 * @return void
	 */
	private function increment_conversation_rate_limit( $conversation_id ) {
		$key   = 'nfd_aia_conv_rate_' . md5( $conversation_id );
		$count = (int) get_transient( $key );
		set_transient( $key, $count + 1, HOUR_IN_SECONDS );
	}

	/**
	 * Best-effort client IP for rate limiting.
	 *
	 * @return string
	 */
	private function get_client_ip() {
		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			return sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}
		return 'unknown';
	}
}
