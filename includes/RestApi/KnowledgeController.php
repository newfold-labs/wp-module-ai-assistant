<?php
/**
 * Admin REST endpoints for Knowledge page settings.
 *
 * @package NewfoldLabs\WP\Module\AIAssistant\RestApi
 */

namespace NewfoldLabs\WP\Module\AIAssistant\RestApi;

use NewfoldLabs\WP\Module\AIAssistant\Services\AiAssistantWorker;
use NewfoldLabs\WP\Module\AIAssistant\Services\CapabilityGate;
use NewfoldLabs\WP\Module\AIAssistant\Services\HomepageExtractor;
use NewfoldLabs\WP\Module\AIAssistant\Services\KnowledgePrefill;
use NewfoldLabs\WP\Module\AIAssistant\Services\KnowledgeStore;
use NewfoldLabs\WP\Module\AIAssistant\Services\QualityTierChecklist;
use NewfoldLabs\WP\Module\AIAssistant\Services\SnapshotBuilder;
use WP_REST_Request;
use WP_REST_Server;

/**
 * Handles admin knowledge configuration routes.
 */
class KnowledgeController {

	const NAMESPACE = 'newfold-ai-assistant/v1';

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/knowledge',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'admin_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'admin_permission' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/knowledge/rebuild',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'rebuild' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/knowledge/improve-description',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'improve_description' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);
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
				__( 'Sorry, you are not allowed to manage AI assistant knowledge.', 'wp-module-ai-assistant' ),
				array( 'status' => 403 )
			);
		}

		return CapabilityGate::has_ai_assistant()
			? true
			: new \WP_Error(
				'rest_forbidden',
				__( 'AI Site Assistant is not enabled for your site.', 'wp-module-ai-assistant' ),
				array( 'status' => 403 )
			);
	}

	/**
	 * GET knowledge status and editable fields.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_item() {
		return rest_ensure_response( $this->build_status_payload() );
	}

	/**
	 * PUT knowledge settings.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function update_item( WP_REST_Request $request ) {
		if ( $request->has_param( 'business_description' ) ) {
			update_option(
				'nfd_ai_assistant_business_description',
				sanitize_textarea_field( (string) $request->get_param( 'business_description' ) )
			);
		}

		if ( $request->has_param( 'curated_facts' ) ) {
			update_option(
				'nfd_ai_assistant_curated_facts',
				sanitize_textarea_field( (string) $request->get_param( 'curated_facts' ) )
			);
		}

		if ( $request->has_param( 'site_mode_override' ) ) {
			$override = sanitize_text_field( (string) $request->get_param( 'site_mode_override' ) );
			if ( in_array( $override, array( 'business', 'personal_blog', 'hybrid', '' ), true ) ) {
				update_option( 'nfd_ai_assistant_site_mode_override', $override );
			}
		}

		if ( $request->has_param( 'custom_ctas' ) ) {
			$this->save_custom_ctas( $request->get_param( 'custom_ctas' ) );
		}

		if ( $request->has_param( 'hidden_cta_urls' ) ) {
			$this->save_hidden_cta_urls( $request->get_param( 'hidden_cta_urls' ) );
		}

		KnowledgeStore::run_scheduled_rebuild();

		return rest_ensure_response( $this->build_status_payload() );
	}

	/**
	 * POST full snapshot rebuild.
	 *
	 * @return \WP_REST_Response
	 */
	public function rebuild() {
		( new SnapshotBuilder() )->build_full();

		return rest_ensure_response(
			array_merge(
				$this->build_status_payload(),
				array(
					'rebuilt' => true,
				)
			)
		);
	}

	/**
	 * POST one-shot AI polish of homepage extract into a description.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function improve_description() {
		$extract = HomepageExtractor::extract();
		if ( '' === trim( $extract ) ) {
			return new \WP_Error(
				'no_homepage_content',
				__( 'No homepage content was found to summarize.', 'wp-module-ai-assistant' ),
				array( 'status' => 400 )
			);
		}

		$prompt = "Summarize the following website homepage content into a concise 2-sentence site description "
			. "suitable for a visitor-facing AI assistant. Output plain text only, no JSON.\n\n"
			. $extract;

		$result = ( new AiAssistantWorker() )->ask( $prompt );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$description = sanitize_textarea_field( trim( (string) $result ) );
		update_option( 'nfd_ai_assistant_business_description', $description );
		KnowledgeStore::run_scheduled_rebuild();

		return rest_ensure_response(
			array_merge(
				$this->build_status_payload(),
				array(
					'suggested_description' => $description,
				)
			)
		);
	}

	/**
	 * Build the admin status payload.
	 *
	 * @return array<string, mixed>
	 */
	private function build_status_payload() {
		$snapshot = KnowledgeStore::get_snapshot();
		$brief    = KnowledgeStore::get_brief();
		$business = ! empty( $snapshot['business'] ) ? $snapshot['business'] : array();

		$quality_tier       = KnowledgeStore::get_quality_tier();
		$description_source = ! empty( $business['description_source'] ) ? (string) $business['description_source'] : '';
		$content_count      = ! empty( $snapshot['content_count'] ) ? (int) $snapshot['content_count'] : 0;
		$site_mode          = ! empty( $snapshot['site_mode'] ) ? (string) $snapshot['site_mode'] : 'business';
		$business_description = KnowledgePrefill::get_business_description();

		$feature_enabled = function_exists( 'NewfoldLabs\WP\Module\Features\isEnabled' )
			&& \NewfoldLabs\WP\Module\Features\isEnabled( 'ai-site-assistant' );

		return array(
			'feature_enabled'      => $feature_enabled,
			'quality_tier'         => $quality_tier,
			'site_mode'            => $site_mode,
			'site_mode_override'   => (string) get_option( 'nfd_ai_assistant_site_mode_override', '' ),
			'built_at'             => ! empty( $snapshot['built_at'] ) ? (string) $snapshot['built_at'] : '',
			'brief_version'        => ! empty( $brief['brief_version'] ) ? (string) $brief['brief_version'] : '',
			'content_count'        => $content_count,
			'corpus_count'         => ! empty( $snapshot['corpus'] ) ? count( $snapshot['corpus'] ) : 0,
			'description_source'   => $description_source,
			'business_description' => $business_description,
			'curated_facts'        => KnowledgePrefill::get_curated_facts(),
			'ctas_catalog'         => ! empty( $snapshot['ctas_catalog'] ) ? $snapshot['ctas_catalog'] : array(),
			'custom_ctas'          => $this->get_custom_ctas(),
			'hidden_cta_urls'      => $this->get_hidden_cta_urls(),
			'tier_checklist'       => QualityTierChecklist::build(
				$quality_tier,
				$description_source,
				$content_count,
				$site_mode,
				$business_description
			),
			'next_tier_hint'       => QualityTierChecklist::next_tier_hint( $quality_tier ),
		);
	}

	/**
	 * Normalize and save admin-curated CTAs.
	 *
	 * @param mixed $raw_ctas Raw CTA list.
	 * @return void
	 */
	private function save_custom_ctas( $raw_ctas ) {
		if ( ! is_array( $raw_ctas ) ) {
			return;
		}

		$clean = array();
		foreach ( array_slice( $raw_ctas, 0, 6 ) as $cta ) {
			if ( empty( $cta['label'] ) || empty( $cta['url'] ) ) {
				continue;
			}
			$clean[] = array(
				'label' => sanitize_text_field( (string) $cta['label'] ),
				'url'   => esc_url_raw( (string) $cta['url'] ),
			);
		}

		update_option( 'nfd_ai_assistant_ctas', $clean );
	}

	/**
	 * Save hidden auto-detected CTA URLs.
	 *
	 * @param mixed $raw_urls Raw URL list.
	 * @return void
	 */
	private function save_hidden_cta_urls( $raw_urls ) {
		if ( ! is_array( $raw_urls ) ) {
			return;
		}

		$clean = array();
		foreach ( $raw_urls as $url ) {
			$url = esc_url_raw( (string) $url );
			if ( '' !== $url ) {
				$clean[] = $url;
			}
		}

		update_option( 'nfd_ai_assistant_hidden_cta_urls', array_values( array_unique( $clean ) ) );
	}

	/**
	 * Get admin-curated CTAs.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function get_custom_ctas() {
		$ctas = get_option( 'nfd_ai_assistant_ctas', array() );
		return is_array( $ctas ) ? $ctas : array();
	}

	/**
	 * Get hidden auto-detected CTA URLs.
	 *
	 * @return array<int, string>
	 */
	private function get_hidden_cta_urls() {
		$urls = get_option( 'nfd_ai_assistant_hidden_cta_urls', array() );
		return is_array( $urls ) ? $urls : array();
	}
}
