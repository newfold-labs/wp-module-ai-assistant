<?php
/**
 * Derives business profile fields from site signals.
 *
 * @package NewfoldLabs\WP\Module\AIAssistant\Services
 */

namespace NewfoldLabs\WP\Module\AIAssistant\Services;

/**
 * Auto-inference helpers for sparse business profiles.
 */
class BusinessProfileDeriver {

	/**
	 * Build a one-line description from blog name and nav pages.
	 *
	 * @return string
	 */
	public static function derive_description() {
		$name  = get_bloginfo( 'name' );
		$pages = self::get_nav_page_titles( 5 );

		if ( empty( $pages ) ) {
			return $name;
		}

		return sprintf(
			/* translators: %1$s: site name, %2$s: comma-separated page titles */
			__( '%1$s — site with sections: %2$s.', 'wp-module-ai-assistant' ),
			$name,
			implode( ', ', $pages )
		);
	}

	/**
	 * Infer business type from active plugins.
	 *
	 * @return string
	 */
	public static function infer_type_from_plugins() {
		if ( self::is_plugin_active( 'woocommerce/woocommerce.php' ) ) {
			return 'e-commerce';
		}
		if ( self::is_plugin_active( 'learndash/learndash.php' ) || self::is_plugin_active( 'tutor/tutor.php' ) ) {
			return 'online courses';
		}
		if ( self::is_plugin_active( 'the-events-calendar/the-events-calendar.php' ) ) {
			return 'events';
		}
		if ( self::is_plugin_active( 'restaurant-reservations/restaurant-reservations.php' ) || self::is_plugin_active( 'restropress/restropress.php' ) ) {
			return 'restaurant';
		}
		if ( self::is_plugin_active( 'bookingpress/bookingpress.php' ) || self::is_plugin_active( 'ameliabooking/ameliabooking.php' ) ) {
			return 'appointments';
		}

		return '';
	}

	/**
	 * Infer type from published page slugs.
	 *
	 * @return string
	 */
	public static function infer_type_from_slugs() {
		$slug_map = array(
			'menu'         => 'restaurant',
			'reservation'  => 'restaurant',
			'booking'      => 'appointments',
			'appointment'  => 'appointments',
			'portfolio'    => 'creative services',
			'services'     => 'services',
			'shop'         => 'e-commerce',
			'courses'      => 'online courses',
			'listings'     => 'listings',
		);

		$pages = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'posts_per_page' => 50,
				'fields'         => 'ids',
			)
		);

		foreach ( $pages as $page_id ) {
			$slug = get_post_field( 'post_name', $page_id );
			foreach ( $slug_map as $needle => $type ) {
				if ( false !== strpos( $slug, $needle ) ) {
					return $type;
				}
			}
		}

		return '';
	}

	/**
	 * Default type label for a site mode.
	 *
	 * @param string $mode Site mode.
	 * @return string
	 */
	public static function default_type_for_mode( $mode ) {
		switch ( $mode ) {
			case 'personal_blog':
				return 'personal blog';
			case 'hybrid':
				return 'general business';
			default:
				return 'general business';
		}
	}

	/**
	 * Get titles from the primary nav menu.
	 *
	 * @param int $limit Max items.
	 * @return array<int, string>
	 */
	private static function get_nav_page_titles( $limit = 5 ) {
		$locations = get_nav_menu_locations();
		if ( empty( $locations['primary'] ) ) {
			return array();
		}

		$items = wp_get_nav_menu_items( $locations['primary'] );
		if ( empty( $items ) ) {
			return array();
		}

		$titles = array();
		foreach ( $items as $item ) {
			if ( 'post_type' === $item->type && 'page' === $item->object ) {
				$titles[] = $item->title;
			}
			if ( count( $titles ) >= $limit ) {
				break;
			}
		}

		return $titles;
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
