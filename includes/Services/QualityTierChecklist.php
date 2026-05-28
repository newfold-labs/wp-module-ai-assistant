<?php
/**
 * Admin-facing quality tier checklist helpers.
 *
 * @package NewfoldLabs\WP\Module\AIAssistant\Services
 */

namespace NewfoldLabs\WP\Module\AIAssistant\Services;

/**
 * Builds checklist items showing what is missing to reach the next tier.
 */
class QualityTierChecklist {

	/**
	 * Build checklist rows for the Knowledge admin page.
	 *
	 * @param string               $quality_tier       Current tier.
	 * @param string               $description_source Description source key.
	 * @param int                  $content_count      Published pages + posts.
	 * @param string               $site_mode          Site mode.
	 * @param string               $business_description Admin-authored description.
	 * @return array<int, array<string, mixed>>
	 */
	public static function build( $quality_tier, $description_source, $content_count, $site_mode, $business_description ) {
		$is_blog     = 'personal_blog' === $site_mode;
		$desc_label  = $is_blog
			? __( 'Site description', 'wp-module-ai-assistant' )
			: __( 'Business description', 'wp-module-ai-assistant' );
		$content_label = $is_blog
			? __( 'At least 5 published posts or pages', 'wp-module-ai-assistant' )
			: __( 'At least 5 published pages or posts', 'wp-module-ai-assistant' );

		$has_rich_description = in_array(
			$description_source,
			array( 'sitegen', 'onboarding', 'woocommerce', 'admin', 'homepage' ),
			true
		) || ( '' !== trim( $business_description ) );

		$items = array(
			array(
				'id'    => 'description',
				'label' => $desc_label,
				'done'  => $has_rich_description,
			),
			array(
				'id'    => 'content_count',
				'label' => $content_label,
				'done'  => $content_count >= 5,
			),
		);

		if ( 'insufficient' === $quality_tier ) {
			$items[] = array(
				'id'    => 'minimum_content',
				'label' => __( 'At least 3 published pages or posts', 'wp-module-ai-assistant' ),
				'done'  => $content_count >= 3,
			);
		}

		return $items;
	}

	/**
	 * Human-readable next-tier hint.
	 *
	 * @param string $quality_tier Current tier.
	 * @return string
	 */
	public static function next_tier_hint( $quality_tier ) {
		switch ( $quality_tier ) {
			case 'insufficient':
				return __( 'Add a site description or publish at least 3 pages/posts to enable the assistant.', 'wp-module-ai-assistant' );
			case 'minimal':
				return __( 'Add a richer description or publish more content to reach the rich tier.', 'wp-module-ai-assistant' );
			default:
				return __( 'Your knowledge profile looks good.', 'wp-module-ai-assistant' );
		}
	}
}
