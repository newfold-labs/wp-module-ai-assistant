<?php
/**
 * AI Site Assistant Module Bootstrap
 *
 * @package NewfoldLabs\WP\Module\AIAssistant
 */

namespace NewfoldLabs\WP\Module\AIAssistant;

use NewfoldLabs\WP\Module\AIAssistant\Features\AISiteAssistantFeature;
use NewfoldLabs\WP\ModuleLoader\Container;

use function NewfoldLabs\WP\ModuleLoader\register;

if ( function_exists( 'add_action' ) ) {

	add_action(
		'plugins_loaded',
		function () {
			if ( ! defined( 'NFD_MODULE_AI_ASSISTANT_DIR' ) ) {
				define( 'NFD_MODULE_AI_ASSISTANT_DIR', __DIR__ );
			}

			if ( ! defined( 'NFD_MODULE_AI_ASSISTANT_VERSION' ) ) {
				define( 'NFD_MODULE_AI_ASSISTANT_VERSION', '1.0.0' );
			}

			if ( ! defined( 'NFD_MODULE_AI_ASSISTANT_URL' ) ) {
				$plugin_path = dirname( dirname( dirname( __DIR__ ) ) );
				$plugin_url  = plugins_url( '', $plugin_path . '/wp-plugin-web.php' );
				define( 'NFD_MODULE_AI_ASSISTANT_URL', $plugin_url . '/vendor/newfold-labs/wp-module-ai-assistant/' );
			}

			register(
				array(
					'name'     => 'ai-assistant',
					'label'    => __( 'AI Site Assistant', 'wp-module-ai-assistant' ),
					'callback' => function ( Container $container ) {
						return new AISiteAssistant( $container );
					},
					'isActive' => true,
					'isHidden' => true,
				)
			);
		}
	);

	add_filter(
		'newfold/features/filter/register',
		function ( $features ) {
			return array_merge( $features, array( AISiteAssistantFeature::class ) );
		}
	);
}
