<?php
/**
 * Compiles the site brief and quality tier.
 *
 * @package NewfoldLabs\WP\Module\AIAssistant\Services
 */

namespace NewfoldLabs\WP\Module\AIAssistant\Services;

/**
 * Produces a compact markdown brief stored with a content-hash version.
 */
class BriefCompiler {

	/**
	 * Compile brief text and metadata from a business profile.
	 *
	 * @param BusinessProfile                  $profile Business profile.
	 * @param array<int, array<string,string>> $ctas    CTA catalog.
	 * @return array<string, mixed>
	 */
	public static function compile( BusinessProfile $profile, array $ctas = array() ) {
		$mode              = (string) $profile->get( 'site_mode' );
		$description       = (string) $profile->get( 'description' );
		$description_source = (string) $profile->get( 'description_source' );
		$content_count     = (int) $profile->get( 'content_count' );
		$quality_tier      = self::evaluate_quality_tier( $description_source, $content_count, $description );
		$curated           = (string) $profile->get( 'curated_facts' );
		$contact           = (array) $profile->get( 'contact' );

		$lines   = array();
		$lines[] = self::role_line( $profile, $mode );
		$lines[] = $description;

		if ( ! empty( $profile->get( 'type' ) ) ) {
			$lines[] = sprintf(
				/* translators: %s: business type */
				__( 'Type: %s', 'wp-module-ai-assistant' ),
				$profile->get( 'type' )
			);
		}

		if ( ! empty( $contact['phone'] ) ) {
			$lines[] = sprintf(
				/* translators: %s: phone number */
				__( 'Phone: %s', 'wp-module-ai-assistant' ),
				$contact['phone']
			);
		}

		if ( $curated ) {
			$lines[] = $curated;
		}

		$snapshot   = KnowledgeStore::get_snapshot();
		$page_titles = array();
		if ( ! empty( $snapshot['corpus'] ) ) {
			foreach ( array_slice( $snapshot['corpus'], 0, 12 ) as $entry ) {
				$page_titles[] = $entry['title'];
			}
		}
		if ( $page_titles ) {
			$lines[] = sprintf(
				/* translators: %s: comma-separated page titles */
				__( 'Site map: %s', 'wp-module-ai-assistant' ),
				implode( ', ', $page_titles )
			);
		}

		$text = trim( implode( "\n", array_filter( $lines ) ) );
		$hash = substr( sha1( $text . wp_json_encode( $ctas ) ), 0, 12 );

		return array(
			'text'          => $text,
			'brief_version' => $hash,
			'quality_tier'  => $quality_tier,
			'site_mode'     => $mode,
			'compiled_at'   => gmdate( 'c' ),
		);
	}

	/**
	 * Evaluate quality tier thresholds.
	 *
	 * @param string $description_source Source key.
	 * @param int    $content_count      Published pages + posts.
	 * @param string $description        Description text.
	 * @return string rich|minimal|insufficient
	 */
	public static function evaluate_quality_tier( $description_source, $content_count, $description ) {
		if ( 'sentinel' === $description_source && $content_count < 3 ) {
			return 'insufficient';
		}

		if ( 'derived' === $description_source || ( $content_count >= 3 && $content_count <= 4 ) ) {
			return 'minimal';
		}

		if ( in_array( $description_source, array( 'sitegen', 'onboarding', 'woocommerce', 'admin', 'homepage' ), true ) && $content_count >= 5 ) {
			return 'rich';
		}

		if ( 'sentinel' === $description_source ) {
			return 'minimal';
		}

		return $content_count >= 5 ? 'rich' : 'minimal';
	}

	/**
	 * Opening role line templated on site mode.
	 *
	 * @param BusinessProfile $profile Profile.
	 * @param string          $mode    Site mode.
	 * @return string
	 */
	private static function role_line( BusinessProfile $profile, $mode ) {
		$name = $profile->get( 'name' );
		$url  = $profile->get( 'url' );

		switch ( $mode ) {
			case 'personal_blog':
				return sprintf(
					/* translators: 1: site name, 2: site URL */
					__( 'You are the assistant for **%1$s** (%2$s), a personal blog. Help visitors find posts on topics they care about.', 'wp-module-ai-assistant' ),
					$name,
					$url
				);
			case 'hybrid':
				return sprintf(
					/* translators: 1: site name, 2: site URL */
					__( 'You are the assistant for **%1$s** (%2$s). Help visitors with services and content discovery.', 'wp-module-ai-assistant' ),
					$name,
					$url
				);
			default:
				return sprintf(
					/* translators: 1: site name, 2: site URL */
					__( 'You are the assistant for **%1$s** (%2$s). Help visitors get answers about services, products, and contact options.', 'wp-module-ai-assistant' ),
					$name,
					$url
				);
		}
	}

	/**
	 * Extra system rule for minimal tier.
	 *
	 * @return string
	 */
	public static function minimal_tier_rule() {
		return 'Site profile is sparse. Be extra cautious — if a fact is not in the RELEVANT PAGES, say you do not have that info and suggest the visitor browse the site or contact the author/business. Prefer short answers.';
	}
}
