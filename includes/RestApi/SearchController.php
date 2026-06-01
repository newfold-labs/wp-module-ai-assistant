<?php
/**
 * REST endpoints for AI assistant search.
 *
 * @package NewfoldLabs\WP\Module\AIAssistant\RestApi
 */

namespace NewfoldLabs\WP\Module\AIAssistant\RestApi;

use NewfoldLabs\WP\Module\AIAssistant\Search\BM25\Indexer;
use NewfoldLabs\WP\Module\AIAssistant\Search\BM25\Schema;
use NewfoldLabs\WP\Module\AIAssistant\Search\SearchService;
use NewfoldLabs\WP\Module\AIAssistant\Search\Synonyms;
use NewfoldLabs\WP\Module\AIAssistant\Search\SynonymSuggestor;
use NewfoldLabs\WP\Module\AIAssistant\Services\CapabilityGate;
use NewfoldLabs\WP\Module\AIAssistant\Services\KnowledgeStore;
use WP_REST_Request;
use WP_REST_Server;

/**
 * Handles search routes backed by the module search service.
 */
class SearchController {

	const NAMESPACE = 'newfold-ai-assistant/v1';

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/search',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'search' ),
				'permission_callback' => array( $this, 'public_permission' ),
				'args'                => array(
					'q'     => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'types' => array(
						'required'          => false,
						'type'              => 'array',
						'items'             => array(
							'type' => 'string',
						),
						'sanitize_callback' => array( $this, 'sanitize_types' ),
					),
					'limit' => array(
						'required'          => false,
						'type'              => 'integer',
						'default'           => 5,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/search/rebuild',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'rebuild' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/search/stats',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'stats' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/search/synonyms',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_synonyms' ),
					'permission_callback' => array( $this, 'admin_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_synonyms' ),
					'permission_callback' => array( $this, 'admin_permission' ),
					'args'                => array(
						'synonyms' => array(
							'required' => true,
							'type'     => 'object',
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/search/synonyms/suggest',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'suggest_synonyms' ),
				'permission_callback' => array( $this, 'admin_permission' ),
				'args'                => array(
					'method' => array(
						'required'          => false,
						'type'              => 'string',
						'default'           => 'both',
						'enum'              => array( 'content', 'llm', 'both' ),
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Permission for public search.
	 *
	 * @return bool|\WP_Error
	 */
	public function public_permission() {
		$permission = CapabilityGate::rest_permission();
		if ( is_wp_error( $permission ) ) {
			return $permission;
		}

		return $this->is_ip_rate_limited()
			? new \WP_Error(
				'rest_rate_limited',
				__( 'Too many search requests. Please wait a moment and try again.', 'wp-module-ai-assistant' ),
				array( 'status' => 429 )
			)
			: true;
	}

	/**
	 * Admin permission callback.
	 *
	 * @return bool|\WP_Error
	 */
	public function admin_permission() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'Sorry, you are not allowed to manage AI assistant search.', 'wp-module-ai-assistant' ),
				array( 'status' => 403 )
			);
		}

		return CapabilityGate::rest_permission();
	}

	/**
	 * GET search results.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function search( WP_REST_Request $request ) {
		$query = trim( (string) $request->get_param( 'q' ) );
		if ( '' === $query ) {
			return new \WP_Error(
				'invalid_search_query',
				__( 'Search query is required.', 'wp-module-ai-assistant' ),
				array( 'status' => 400 )
			);
		}

		if ( strlen( $query ) > 200 ) {
			return new \WP_Error(
				'invalid_search_query',
				__( 'Search query is too long.', 'wp-module-ai-assistant' ),
				array( 'status' => 400 )
			);
		}

		$limit   = max( 1, min( 20, absint( $request->get_param( 'limit' ) ?: 5 ) ) );
		$types   = $this->sanitize_types( $request->get_param( 'types' ) );
		$results = ( new SearchService() )->search( $query, $limit, $types );

		$this->increment_ip_rate_limit();

		return rest_ensure_response(
			array(
				'query'   => $query,
				'count'   => count( $results ),
				'results' => array_map( array( $this, 'format_result' ), $results ),
			)
		);
	}

	/**
	 * POST queue search index rebuild.
	 *
	 * @return \WP_REST_Response
	 */
	public function rebuild() {
		( new Indexer() )->start_rebuild();

		return rest_ensure_response(
			array(
				'queued' => true,
				'stats'  => Schema::get_index_status(),
			)
		);
	}

	/**
	 * GET search index stats.
	 *
	 * @return \WP_REST_Response
	 */
	public function stats() {
		return rest_ensure_response( Schema::get_index_status() );
	}

	/**
	 * GET synonym map.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_synonyms() {
		return rest_ensure_response(
			array(
				'defaults' => Synonyms::get_default_map(),
				'custom'   => Synonyms::get_custom_map(),
				'merged'   => Synonyms::get_map(),
			)
		);
	}

	/**
	 * POST synonym map.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function update_synonyms( WP_REST_Request $request ) {
		$custom = Synonyms::update_custom_map( $request->get_param( 'synonyms' ) );

		return rest_ensure_response(
			array(
				'custom' => $custom,
				'merged' => Synonyms::get_map(),
			)
		);
	}

	/**
	 * POST /search/synonyms/suggest — Propose synonyms from content analysis and/or LLM.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function suggest_synonyms( WP_REST_Request $request ) {
		$method      = $request->get_param( 'method' ) ?: 'both';
		$suggestor   = new SynonymSuggestor();
		$suggestions = $suggestor->suggest( $method );

		return rest_ensure_response(
			array(
				'method'      => $method,
				'count'       => count( $suggestions ),
				'suggestions' => $suggestions,
			)
		);
	}

	/**
	 * Sanitize requested post types against the module allow-list.
	 *
	 * @param mixed $types Raw types.
	 * @return array<int, string>
	 */
	public function sanitize_types( $types ) {
		if ( ! is_array( $types ) ) {
			$types = empty( $types ) ? array() : array( $types );
		}

		$allowed = KnowledgeStore::indexable_post_types();
		$types   = array_map( 'sanitize_key', $types );

		return array_values( array_intersect( $types, $allowed ) );
	}

	/**
	 * Format one search result for REST consumers.
	 *
	 * @param array<string, mixed> $result Search result.
	 * @return array<string, mixed>
	 */
	public function format_result( array $result ) {
		return array(
			'id'        => (int) $result['id'],
			'title'     => (string) $result['title'],
			'url'       => (string) $result['permalink'],
			'excerpt'   => (string) $result['excerpt'],
			'post_type' => (string) $result['post_type'],
			'score'     => isset( $result['score'] ) ? (float) $result['score'] : 0.0,
		);
	}

	/**
	 * Whether this IP has exceeded the public search limit.
	 *
	 * @return bool
	 */
	private function is_ip_rate_limited() {
		$key   = 'nfd_aia_search_ip_' . md5( $this->get_client_ip() );
		$count = (int) get_transient( $key );
		$limit = (int) apply_filters( 'nfd_ai_assistant_search_ip_rate_limit', 60 );

		return $count >= $limit;
	}

	/**
	 * Increment public search rate limit.
	 *
	 * @return void
	 */
	private function increment_ip_rate_limit() {
		$key   = 'nfd_aia_search_ip_' . md5( $this->get_client_ip() );
		$count = (int) get_transient( $key );

		set_transient( $key, $count + 1, 10 * MINUTE_IN_SECONDS );
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
