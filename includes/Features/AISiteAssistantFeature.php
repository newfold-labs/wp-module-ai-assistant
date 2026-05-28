<?php
/**
 * Features-tab integration for AI Site Assistant.
 *
 * @package NewfoldLabs\WP\Module\AIAssistant\Features
 */

namespace NewfoldLabs\WP\Module\AIAssistant\Features;

use NewfoldLabs\WP\Module\AIAssistant\AISiteAssistant;
use NewfoldLabs\WP\Module\AIAssistant\Services\CapabilityGate;

/**
 * Registers the ai-site-assistant feature toggle.
 */
class AISiteAssistantFeature extends \NewfoldLabs\WP\Module\Features\Feature {

	/**
	 * Feature name.
	 *
	 * @var string
	 */
	protected $name = 'ai-site-assistant';

	/**
	 * Default enabled state.
	 *
	 * @var bool
	 */
	protected $value = true;

	/**
	 * Boot module hooks when enabled and allowed.
	 *
	 * @return void
	 */
	public function initialize() {
		if ( ! CapabilityGate::has_ai_assistant() ) {
			return;
		}

		( new AISiteAssistant() )->init();
	}

	/**
	 * Whether the toggle should appear in admin UI.
	 *
	 * @return bool
	 */
	public function canToggle() {
		return CapabilityGate::has_ai_assistant() && parent::canToggle();
	}
}
