<?php
/**
 * Resolves a usable accent color from the active theme.
 *
 * @package NewfoldLabs\WP\Module\AIAssistant\Services
 */

namespace NewfoldLabs\WP\Module\AIAssistant\Services;

/**
 * Picks a brand color dark enough for buttons and user message bubbles.
 */
class BrandColorResolver {

	const DEFAULT_COLOR = '#005FA3';

	/**
	 * Resolve the widget brand color.
	 *
	 * @return string Hex color.
	 */
	public static function resolve() {
		$candidates = self::collect_palette_colors();
		if ( empty( $candidates ) ) {
			return self::DEFAULT_COLOR;
		}

		$preferred_slugs = array( 'primary', 'accent', 'contrast', 'heading', 'foreground', 'text' );
		foreach ( $preferred_slugs as $slug ) {
			foreach ( $candidates as $candidate ) {
				if ( $slug === $candidate['slug'] && self::is_usable_on_white( $candidate['color'] ) ) {
					return $candidate['color'];
				}
			}
		}

		$darkest = null;
		$darkest_luminance = 1.0;
		foreach ( $candidates as $candidate ) {
			$luminance = self::relative_luminance( $candidate['color'] );
			if ( $luminance < $darkest_luminance ) {
				$darkest_luminance = $luminance;
				$darkest           = $candidate['color'];
			}
		}

		if ( null !== $darkest && self::is_usable_on_white( $darkest ) ) {
			return $darkest;
		}

		if ( null !== $darkest ) {
			return self::darken( $darkest, 0.35 );
		}

		return self::DEFAULT_COLOR;
	}

	/**
	 * Collect theme palette entries.
	 *
	 * @return array<int, array{slug:string,color:string}>
	 */
	private static function collect_palette_colors() {
		if ( ! function_exists( 'wp_get_global_settings' ) ) {
			return array();
		}

		$settings = wp_get_global_settings();
		$palette  = array_merge(
			$settings['color']['palette']['theme'] ?? array(),
			$settings['color']['palette']['default'] ?? array()
		);

		$colors = array();
		foreach ( $palette as $entry ) {
			$color = self::sanitize_hex( $entry['color'] ?? '' );
			if ( '' === $color ) {
				continue;
			}

			$colors[] = array(
				'slug'  => sanitize_key( $entry['slug'] ?? '' ),
				'color' => $color,
			);
		}

		return $colors;
	}

	/**
	 * Whether a color works as a button background with white text.
	 *
	 * @param string $hex Hex color.
	 * @return bool
	 */
	private static function is_usable_on_white( $hex ) {
		return self::relative_luminance( $hex ) <= 0.45;
	}

	/**
	 * Calculate relative luminance for sRGB hex colors.
	 *
	 * @param string $hex Hex color.
	 * @return float
	 */
	private static function relative_luminance( $hex ) {
		list( $r, $g, $b ) = self::hex_to_rgb( $hex );
		return ( 0.2126 * $r ) + ( 0.7152 * $g ) + ( 0.0722 * $b );
	}

	/**
	 * Darken a hex color by a percentage.
	 *
	 * @param string $hex     Hex color.
	 * @param float  $percent Amount to darken (0-1).
	 * @return string
	 */
	private static function darken( $hex, $percent ) {
		list( $r, $g, $b ) = self::hex_to_rgb( $hex, false );

		$r = max( 0, (int) round( $r * ( 1 - $percent ) ) );
		$g = max( 0, (int) round( $g * ( 1 - $percent ) ) );
		$b = max( 0, (int) round( $b * ( 1 - $percent ) ) );

		return sprintf( '#%02x%02x%02x', $r, $g, $b );
	}

	/**
	 * Convert hex to normalized or 0-255 RGB channels.
	 *
	 * @param string $hex        Hex color.
	 * @param bool   $normalized Return 0-1 channels when true.
	 * @return array{0:float|int,1:float|int,2:float|int}
	 */
	private static function hex_to_rgb( $hex, $normalized = true ) {
		$hex = ltrim( $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}

		$r = hexdec( substr( $hex, 0, 2 ) );
		$g = hexdec( substr( $hex, 2, 2 ) );
		$b = hexdec( substr( $hex, 4, 2 ) );

		if ( ! $normalized ) {
			return array( $r, $g, $b );
		}

		$convert = static function ( $channel ) {
			$channel = $channel / 255;
			return $channel <= 0.03928
				? $channel / 12.92
				: pow( ( $channel + 0.055 ) / 1.055, 2.4 );
		};

		return array( $convert( $r ), $convert( $g ), $convert( $b ) );
	}

	/**
	 * Normalize a hex color string.
	 *
	 * @param string $color Raw color.
	 * @return string
	 */
	private static function sanitize_hex( $color ) {
		$color = trim( (string) $color );
		if ( '' === $color ) {
			return '';
		}

		if ( 0 === strpos( $color, '#' ) ) {
			$color = substr( $color, 1 );
		}

		if ( preg_match( '/^[a-f0-9]{3}$/i', $color ) ) {
			return '#' . strtolower( $color );
		}

		if ( preg_match( '/^[a-f0-9]{6}$/i', $color ) ) {
			return '#' . strtolower( $color );
		}

		return '';
	}
}
