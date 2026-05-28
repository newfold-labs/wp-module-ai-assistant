<?php
/**
 * Detects whether a site behaves like a business, blog, or hybrid.
 *
 * @package NewfoldLabs\WP\Module\AIAssistant\Services
 */

namespace NewfoldLabs\WP\Module\AIAssistant\Services;

/**
 * Site mode classifier used by brief compilation and CTA detection.
 */
class SiteModeDetector {

	/**
	 * Detect site mode from plugin and content signals.
	 *
	 * @return string business|personal_blog|hybrid
	 */
	public static function detect() {
		$override = get_option( 'nfd_ai_assistant_site_mode_override', '' );
		if ( in_array( $override, array( 'business', 'personal_blog', 'hybrid' ), true ) ) {
			return $override;
		}

		$business_score = self::business_signals();
		$blog_score     = self::blog_signals();

		if ( $business_score && $blog_score ) {
			return 'hybrid';
		}
		if ( $business_score ) {
			return 'business';
		}
		if ( $blog_score ) {
			return 'personal_blog';
		}

		return 'business';
	}

	/**
	 * Count strong business signals.
	 *
	 * @return int
	 */
	private static function business_signals() {
		$score = 0;

		if ( self::is_plugin_active( 'woocommerce/woocommerce.php' ) ) {
			++$score;
		}

		$business_plugins = array(
			'bookingpress/bookingpress.php',
			'ameliabooking/ameliabilling.php',
			'the-events-calendar/the-events-calendar.php',
			'restaurant-reservations/restaurant-reservations.php',
		);
		foreach ( $business_plugins as $plugin ) {
			if ( self::is_plugin_active( $plugin ) ) {
				++$score;
				break;
			}
		}

		$onboarding = get_option( 'nfd_module_onboarding_site_info', array() );
		if ( ! empty( $onboarding['site_type'] ) && 'personal' !== $onboarding['site_type'] ) {
			++$score;
		}

		$page_count = (int) wp_count_posts( 'page' )->publish;
		if ( $page_count > 5 ) {
			++$score;
		}

		return $score;
	}

	/**
	 * Count strong blog signals when no business signals exist.
	 *
	 * @return int
	 */
	private static function blog_signals() {
		if ( self::business_signals() > 0 ) {
			return 0;
		}

		$score      = 0;
		$page_count = (int) wp_count_posts( 'page' )->publish;
		$post_count = (int) wp_count_posts( 'post' )->publish;

		if ( $page_count <= 2 ) {
			++$score;
		}
		if ( $post_count >= 5 ) {
			++$score;
		}
		if ( $page_count > 0 && ( $post_count / max( 1, $page_count ) ) >= 5 ) {
			++$score;
		}
		if ( self::has_single_author() ) {
			++$score;
		}
		if ( self::bloggy_tagline() ) {
			++$score;
		}

		return $score >= 2 ? $score : 0;
	}

	/**
	 * Whether published content has a single author.
	 *
	 * @return bool
	 */
	private static function has_single_author() {
		global $wpdb;

		$authors = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT post_author) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ('post','page')"
		);

		return 1 === $authors;
	}

	/**
	 * Whether the tagline looks blog-oriented.
	 *
	 * @return bool
	 */
	private static function bloggy_tagline() {
		$tagline = get_bloginfo( 'description' );
		return (bool) preg_match( '/\b(thoughts|journal|writing|blog|stories|musings)\b/i', $tagline );
	}

	/**
	 * Safe plugin_active wrapper.
	 *
	 * @param string $plugin Plugin path.
	 * @return bool
	 */
	private static function is_plugin_active( $plugin ) {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return is_plugin_active( $plugin );
	}
}
