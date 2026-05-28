<?php
/**
 * Extracts a homepage description fallback from DB content.
 *
 * @package NewfoldLabs\WP\Module\AIAssistant\Services
 */

namespace NewfoldLabs\WP\Module\AIAssistant\Services;

/**
 * Reads front-page or latest-post content without HTTP calls.
 */
class HomepageExtractor {

	/**
	 * Produce a clean 600–1,000 character homepage extract.
	 *
	 * @return string
	 */
	public static function extract() {
		$parts = array();

		$name        = get_bloginfo( 'name' );
		$tagline     = get_bloginfo( 'description' );
		$default_tag = __( 'Just another WordPress site', 'default' );

		if ( $name ) {
			$parts[] = $name;
		}

		if ( $tagline && $tagline !== $default_tag ) {
			$parts[] = $tagline;
		}

		$content = self::get_front_content();
		if ( $content ) {
			$parts[] = $content;
		}

		$extract = trim( implode( ' ', $parts ) );
		return self::trim_to_sentence_boundary( $extract, 1000 );
	}

	/**
	 * Get representative front-page content.
	 *
	 * @return string
	 */
	private static function get_front_content() {
		if ( 'page' === get_option( 'show_on_front' ) ) {
			$page_id = (int) get_option( 'page_on_front' );
			if ( $page_id ) {
				$post = get_post( $page_id );
				if ( $post instanceof \WP_Post ) {
					return self::clean_post_content( $post->post_content );
				}
			}
		}

		$posts = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => 5,
			)
		);

		$chunks = array();
		foreach ( $posts as $post ) {
			$excerpt = has_excerpt( $post ) ? get_the_excerpt( $post ) : wp_trim_words( self::clean_post_content( $post->post_content ), 30, '' );
			$chunks[] = $post->post_title . ': ' . $excerpt;
		}

		return implode( ' ', $chunks );
	}

	/**
	 * Strip blocks, shortcodes, and HTML from post content.
	 *
	 * @param string $content Raw post content.
	 * @return string
	 */
	private static function clean_post_content( $content ) {
		if ( function_exists( 'excerpt_remove_blocks' ) ) {
			$content = excerpt_remove_blocks( $content );
		}
		$content = strip_shortcodes( $content );
		$content = wp_strip_all_tags( $content );
		$content = preg_replace( '/\s+/', ' ', $content );

		return trim( (string) $content );
	}

	/**
	 * Trim text to a max length at a sentence boundary.
	 *
	 * @param string $text    Input text.
	 * @param int    $max_len Max characters.
	 * @return string
	 */
	private static function trim_to_sentence_boundary( $text, $max_len ) {
		if ( strlen( $text ) <= $max_len ) {
			return $text;
		}

		$truncated = substr( $text, 0, $max_len );
		$last_stop = max( strrpos( $truncated, '.' ), strrpos( $truncated, '!' ), strrpos( $truncated, '?' ) );

		if ( false !== $last_stop && $last_stop > (int) ( $max_len * 0.5 ) ) {
			return trim( substr( $truncated, 0, $last_stop + 1 ) );
		}

		return trim( $truncated );
	}
}
