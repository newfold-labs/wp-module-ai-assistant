<?php
/**
 * AI Site Assistant main module class.
 *
 * @package NewfoldLabs\WP\Module\AIAssistant
 */

namespace NewfoldLabs\WP\Module\AIAssistant;

use NewfoldLabs\WP\Module\AIAssistant\RestApi\AssistantController;
use NewfoldLabs\WP\Module\AIAssistant\RestApi\KnowledgeController;
use NewfoldLabs\WP\Module\AIAssistant\RestApi\SearchController;
use NewfoldLabs\WP\Module\AIAssistant\Search\BM25\Indexer;
use NewfoldLabs\WP\Module\AIAssistant\Search\Synonyms;
use NewfoldLabs\WP\Module\AIAssistant\Search\SynonymSuggestor;
use NewfoldLabs\WP\Module\AIAssistant\Services\BrandColorResolver;
use NewfoldLabs\WP\Module\AIAssistant\Services\CapabilityGate;
use NewfoldLabs\WP\Module\AIAssistant\Services\KnowledgeStore;
use NewfoldLabs\WP\Module\AIAssistant\Services\SnapshotBuilder;
use NewfoldLabs\WP\ModuleLoader\Container;

use function NewfoldLabs\WP\Module\Features\isEnabled;

/**
 * Boots REST routes, knowledge hooks, and the public widget.
 */
class AISiteAssistant {

	/**
	 * Whether init() has already run.
	 *
	 * @var bool
	 */
	private static $initialized = false;

	/**
	 * Module container.
	 *
	 * @var Container|null
	 */
	protected $container;

	/**
	 * Constructor.
	 *
	 * @param Container|null $container Optional module container.
	 */
	public function __construct( ?Container $container = null ) {
		$this->container = $container;
	}

	/**
	 * Initialize hooks when the feature is enabled.
	 *
	 * @return void
	 */
	public function init() {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;

		if ( ! CapabilityGate::has_ai_assistant() ) {
			return;
		}

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend' ) );
		add_action( 'init', array( __CLASS__, 'load_text_domain' ), 100 );

		KnowledgeStore::register_hooks();
		Indexer::register_hooks();

		add_action( 'nfd_ai_assistant_search_rebuild_complete', array( __CLASS__, 'generate_content_synonyms' ) );

		if ( empty( KnowledgeStore::get_snapshot() ) ) {
			add_action(
				'init',
				static function () {
					if ( empty( KnowledgeStore::get_snapshot() ) ) {
						( new SnapshotBuilder() )->build_full();
					}
				},
				20
			);
		}

		if ( ! get_option( 'nfd_ai_assistant_search_indexed_at', '' ) ) {
			add_action(
				'init',
				static function () {
					Indexer::schedule_rebuild();
				},
				30
			);
		}
	}

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		( new AssistantController() )->register_routes();
		( new KnowledgeController() )->register_routes();
		( new SearchController() )->register_routes();
	}

	/**
	 * Enqueue the public chat widget.
	 *
	 * @return void
	 */
	public function enqueue_frontend() {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		if ( ! function_exists( 'NewfoldLabs\WP\Module\Features\isEnabled' ) || ! isEnabled( 'ai-site-assistant' ) ) {
			return;
		}

		if ( ! CapabilityGate::has_ai_assistant() ) {
			return;
		}

		if ( 'insufficient' === KnowledgeStore::get_quality_tier() ) {
			return;
		}

		wp_enqueue_style(
			'nfd-ai-site-assistant-widget',
			NFD_MODULE_AI_ASSISTANT_URL . 'build/widget.css',
			array(),
			NFD_MODULE_AI_ASSISTANT_VERSION
		);

		wp_enqueue_script(
			'nfd-ai-site-assistant-widget',
			NFD_MODULE_AI_ASSISTANT_URL . 'build/widget.js',
			array(),
			NFD_MODULE_AI_ASSISTANT_VERSION,
			true
		);

		$brand = BrandColorResolver::resolve();

		wp_localize_script(
			'nfd-ai-site-assistant-widget',
			'nfdAISiteAssistant',
			array(
				'apiRoot'         => esc_url_raw( rest_url( AssistantController::NAMESPACE ) ),
				'nonce'           => wp_create_nonce( 'wp_rest' ),
				'siteName'        => get_bloginfo( 'name' ),
				'welcomeMessage'  => sprintf(
					/* translators: %s: site name */
					__( 'Hi! Ask me anything about %s.', 'wp-module-ai-assistant' ),
					get_bloginfo( 'name' )
				),
				'suggestions'     => $this->initial_suggestions(),
				'brandColor'      => $brand,
				'contactPageUrl'  => KnowledgeStore::get_contact_page_url(),
			)
		);
	}

	/**
	 * Load module text domain.
	 *
	 * @return void
	 */
	public static function load_text_domain() {
		load_plugin_textdomain(
			'wp-module-ai-assistant',
			false,
			NFD_MODULE_AI_ASSISTANT_DIR . '/languages'
		);
	}

	/**
	 * Generate and apply LLM-based synonym suggestions after a search index rebuild.
	 *
	 * @return void
	 */
	public static function generate_content_synonyms() {
		$suggestor   = new SynonymSuggestor();
		$suggestions = $suggestor->from_llm();

		if ( empty( $suggestions ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( '[AI-Assistant AISiteAssistant] generate_content_synonyms: no suggestions returned, skipping' );
			}
			return;
		}

		Synonyms::update_custom_map( $suggestions );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[AI-Assistant AISiteAssistant] generate_content_synonyms: replaced map with ' . count( $suggestions ) . ' LLM-generated groups' );
		}
	}

	/**
	 * Default suggestion chips for an empty chat.
	 *
	 * @return array<int, string>
	 */
	private function initial_suggestions() {
		$snapshot = KnowledgeStore::get_snapshot();
		$mode     = ! empty( $snapshot['site_mode'] ) ? $snapshot['site_mode'] : 'business';

		if ( 'personal_blog' === $mode ) {
			return array(
				__( 'What do you write about?', 'wp-module-ai-assistant' ),
				__( 'Show me recent posts', 'wp-module-ai-assistant' ),
				__( 'How can I contact you?', 'wp-module-ai-assistant' ),
			);
		}

		return array(
			__( 'What services do you offer?', 'wp-module-ai-assistant' ),
			__( 'How can I contact you?', 'wp-module-ai-assistant' ),
			__( 'Where can I learn more?', 'wp-module-ai-assistant' ),
		);
	}

}
