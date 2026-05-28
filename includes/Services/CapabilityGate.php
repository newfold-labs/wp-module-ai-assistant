<?php
/**
 * Capability gate helpers for AI Site Assistant.
 *
 * @package NewfoldLabs\WP\Module\AIAssistant\Services
 */

namespace NewfoldLabs\WP\Module\AIAssistant\Services;

use NewfoldLabs\WP\Module\Data\SiteCapabilities;

/**
 * Centralized capability checks for the AI Site Assistant module.
 */
class CapabilityGate {

	/**
	 * Check whether AI access is enabled for the current site.
	 *
	 * @return bool
	 */
	public static function has_ai_access() {
		if ( ! class_exists( SiteCapabilities::class ) ) {
			return false;
		}

		$capabilities = new SiteCapabilities();

		return (bool) $capabilities->get( 'canAccessAI' );
	}

	/**
	 * Check whether the AI Site Assistant feature is enabled for the current site.
	 *
	 * Requires both `canAccessAI` and `hasAIAssistant` SiteCapabilities flags.
	 *
	 * @return bool
	 */
	public static function has_ai_assistant() {
		if ( apply_filters( 'nfd_ai_assistant_bypass_capability', false ) ) {
			return true;
		}

		if ( ! self::has_ai_access() ) {
			return false;
		}

		$capabilities = new SiteCapabilities();

		return (bool) $capabilities->get( 'hasAIAssistant' );
	}

	/**
	 * Whether the assistant REST endpoint should accept requests.
	 *
	 * @return bool|\WP_Error
	 */
	public static function rest_permission() {
		if ( ! self::has_ai_assistant() ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'AI Site Assistant is not enabled for your site.', 'wp-module-ai-assistant' ),
				array( 'status' => 403 )
			);
		}

		if ( ! function_exists( 'NewfoldLabs\WP\Module\Features\isEnabled' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'Features module is unavailable.', 'wp-module-ai-assistant' ),
				array( 'status' => 503 )
			);
		}

		if ( ! \NewfoldLabs\WP\Module\Features\isEnabled( 'ai-site-assistant' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'AI Site Assistant is disabled.', 'wp-module-ai-assistant' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}
}
