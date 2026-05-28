<?php
/**
 * Tolerant JSON parser for model responses.
 *
 * @package NewfoldLabs\WP\Module\AIAssistant\Services
 */

namespace NewfoldLabs\WP\Module\AIAssistant\Services;

/**
 * Extracts structured assistant payloads from plain text model output.
 */
class ResponseParser {

	/**
	 * Parse and sanitize a model response.
	 *
	 * @param string                             $raw           Raw model text.
	 * @param array<int, array<string,string>> $allowed_ctas  CTA whitelist.
	 * @param array<int, array<string,string>> $allowed_sources Source whitelist.
	 * @return array<string, mixed>
	 */
	public function parse( $raw, array $allowed_ctas = array(), array $allowed_sources = array() ) {
		$decoded = $this->decode_json( $raw );

		if ( null === $decoded || empty( $decoded['answer'] ) ) {
			return array(
				'answer'      => wp_kses_post( trim( (string) $raw ) ),
				'suggestions' => array(),
				'ctas'        => array(),
				'sources'     => array(),
				'needs_human' => false,
			);
		}

		return array(
			'answer'      => wp_kses_post( (string) $decoded['answer'] ),
			'suggestions' => $this->sanitize_suggestions( $decoded['suggestions'] ?? array() ),
			'ctas'        => $this->sanitize_ctas( $decoded['ctas'] ?? array(), $allowed_ctas ),
			'sources'     => $this->sanitize_sources( $decoded['sources'] ?? array(), $allowed_sources ),
			'needs_human' => ! empty( $decoded['needs_human'] ),
		);
	}

	/**
	 * Attempt to decode JSON from messy model output.
	 *
	 * @param string $raw Raw text.
	 * @return array<string, mixed>|null
	 */
	private function decode_json( $raw ) {
		$text = trim( (string) $raw );
		$text = preg_replace( '/^```(?:json)?\s*/i', '', $text );
		$text = preg_replace( '/\s*```$/', '', $text );

		$start = strpos( $text, '{' );
		$end   = strrpos( $text, '}' );
		if ( false === $start || false === $end || $end <= $start ) {
			return null;
		}

		$json = substr( $text, $start, $end - $start + 1 );

		try {
			$decoded = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
			return is_array( $decoded ) ? $decoded : null;
		} catch ( \JsonException $exception ) {
			return null;
		}
	}

	/**
	 * Sanitize suggestion chips.
	 *
	 * @param mixed $suggestions Raw suggestions.
	 * @return array<int, string>
	 */
	private function sanitize_suggestions( $suggestions ) {
		if ( ! is_array( $suggestions ) ) {
			return array();
		}

		$clean = array();
		foreach ( array_slice( $suggestions, 0, 3 ) as $item ) {
			$item = $this->decode_display_text( $item );
			if ( '' !== $item ) {
				$clean[] = mb_substr( $item, 0, 80 );
			}
		}

		return $clean;
	}

	/**
	 * Sanitize CTA buttons against the catalog whitelist.
	 *
	 * @param mixed                              $ctas Raw CTAs.
	 * @param array<int, array<string,string>> $allowed_ctas Allowed CTAs.
	 * @return array<int, array<string,string>>
	 */
	private function sanitize_ctas( $ctas, array $allowed_ctas ) {
		if ( ! is_array( $ctas ) ) {
			return array();
		}

		$allowed_urls = array();
		foreach ( $allowed_ctas as $cta ) {
			$allowed_urls[ $cta['url'] ] = $this->decode_display_text( $cta['label'] );
		}

		$clean = array();
		foreach ( array_slice( $ctas, 0, 2 ) as $cta ) {
			if ( empty( $cta['url'] ) ) {
				continue;
			}
			$url = esc_url_raw( $cta['url'] );
			if ( ! isset( $allowed_urls[ $url ] ) ) {
				continue;
			}
			$clean[] = array(
				'label' => $this->decode_display_text( $cta['label'] ?: $allowed_urls[ $url ] ),
				'url'   => $url,
			);
		}

		return $clean;
	}

	/**
	 * Sanitize source citations against retrieved pages.
	 *
	 * @param mixed                              $sources Raw sources.
	 * @param array<int, array<string,string>> $allowed_sources Allowed sources.
	 * @return array<int, array<string,string>>
	 */
	private function sanitize_sources( $sources, array $allowed_sources ) {
		if ( ! is_array( $sources ) ) {
			return array();
		}

		$allowed_urls = array();
		foreach ( $allowed_sources as $source ) {
			$allowed_urls[ $source['url'] ] = $this->decode_display_text( $source['title'] );
		}

		$clean = array();
		foreach ( array_slice( $sources, 0, 3 ) as $source ) {
			if ( empty( $source['url'] ) ) {
				continue;
			}
			$url = esc_url_raw( $source['url'] );
			if ( ! isset( $allowed_urls[ $url ] ) ) {
				continue;
			}
			$clean[] = array(
				'title' => $this->decode_display_text( $source['title'] ?: $allowed_urls[ $url ] ),
				'url'   => $url,
			);
		}

		return $clean;
	}

	/**
	 * Decode HTML entities for human-readable UI labels.
	 *
	 * @param mixed $text Raw text.
	 * @return string
	 */
	private function decode_display_text( $text ) {
		return sanitize_text_field(
			html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' )
		);
	}
}
