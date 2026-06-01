<?php
/**
 * Search synonym map storage and expansion.
 *
 * @package NewfoldLabs\WP\Module\AIAssistant\Search
 */

namespace NewfoldLabs\WP\Module\AIAssistant\Search;

use NewfoldLabs\WP\Module\AIAssistant\Search\BM25\Tokenizer;

/**
 * Stores admin-managed synonyms and expands query terms.
 */
class Synonyms {

	const OPTION = 'nfd_ai_assistant_search_synonyms';

	/**
	 * Tokenizer.
	 *
	 * @var Tokenizer
	 */
	private $tokenizer;

	/**
	 * Constructor.
	 *
	 * @param Tokenizer|null $tokenizer Optional tokenizer.
	 */
	public function __construct( ?Tokenizer $tokenizer = null ) {
		$this->tokenizer = $tokenizer ?: new Tokenizer();
	}

	/**
	 * Get default plus admin-managed synonyms.
	 *
	 * @return array<string, array<int, string>>
	 */
	public static function get_map() {
		$custom = get_option( self::OPTION, array() );
		$custom = is_array( $custom ) ? $custom : array();

		return self::merge_maps(
			self::get_default_map(),
			self::sanitize_map( $custom )
		);
	}

	/**
	 * Get built-in synonyms.
	 *
	 * No longer ships with hardcoded defaults; returns an empty array.
	 * The filter 'newfold_aia_search_default_synonyms' is retained for
	 * backward compatibility with code that may add defaults via hooks.
	 *
	 * @return array<string, array<int, string>>
	 */
	public static function get_default_map() {
		return self::sanitize_map(
			apply_filters(
				'newfold_aia_search_default_synonyms',
				array()
			)
		);
	}

	/**
	 * Get only admin-managed synonyms.
	 *
	 * @return array<string, array<int, string>>
	 */
	public static function get_custom_map() {
		$custom = get_option( self::OPTION, array() );
		return self::sanitize_map( is_array( $custom ) ? $custom : array() );
	}

	/**
	 * Save admin-managed synonyms.
	 *
	 * @param mixed $map Raw map.
	 * @return array<string, array<int, string>>
	 */
	public static function update_custom_map( $map ) {
		$map = self::sanitize_map( is_array( $map ) ? $map : array() );
		update_option( self::OPTION, $map, false );

		return $map;
	}

	/**
	 * Expand tokenized query terms through the synonym map.
	 *
	 * @param array<int, string> $tokens Query tokens.
	 * @return array<int, string>
	 */
	public function expand_tokens( array $tokens ) {
		$map      = self::get_map();
		$expanded = array();
		$limit    = (int) apply_filters( 'newfold_aia_search_synonym_expansion_limit', 6 );

		foreach ( $tokens as $token ) {
			$expanded[] = $token;
			if ( empty( $map[ $token ] ) ) {
				continue;
			}

			foreach ( array_slice( $map[ $token ], 0, max( 0, $limit ) ) as $synonym ) {
				foreach ( $this->tokenizer->tokenize_query( $synonym ) as $synonym_token ) {
					$expanded[] = $synonym_token;
				}
			}
		}

		return array_values( array_unique( $expanded ) );
	}

	/**
	 * Sanitize a synonym map.
	 *
	 * @param mixed $map Raw map.
	 * @return array<string, array<int, string>>
	 */
	public static function sanitize_map( $map ) {
		if ( ! is_array( $map ) ) {
			return array();
		}

		$clean = array();
		foreach ( $map as $term => $synonyms ) {
			$term_tokens = self::normalize_terms( $term );
			if ( empty( $term_tokens ) ) {
				continue;
			}

			$key      = $term_tokens[0];
			$synonyms = is_array( $synonyms ) ? $synonyms : explode( ',', (string) $synonyms );
			$values   = array();
			foreach ( $synonyms as $synonym ) {
				$values = array_merge( $values, self::normalize_terms( $synonym ) );
			}

			$values = array_values( array_diff( array_unique( $values ), array( $key ) ) );
			if ( ! empty( $values ) ) {
				$clean[ $key ] = $values;
			}
		}

		return $clean;
	}

	/**
	 * Normalize one term or phrase into index-compatible tokens.
	 *
	 * @param mixed $value Raw term.
	 * @return array<int, string>
	 */
	private static function normalize_terms( $value ) {
		$value = wp_strip_all_tags( (string) $value );

		// Must match Tokenizer::tokenize() splitting.
		$parts = preg_split(
			'/[^a-zA-Z0-9]+|(?<=[a-z])(?=\\d)|(?<=\\d)(?=[a-z])|(?<=[a-z])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/',
			$value
		);

		if ( ! is_array( $parts ) ) {
			return array();
		}

		$parts = array_map( 'strtolower', $parts );

		return array_values(
			array_filter(
				array_map( 'sanitize_key', $parts ),
				function ( $term ) {
					return strlen( $term ) >= 2 && strlen( $term ) <= 64 && ! ctype_digit( $term );
				}
			)
		);
	}

	/**
	 * Merge maps while preserving both defaults and custom entries.
	 *
	 * @param array<string, array<int, string>> $base Base map.
	 * @param array<string, array<int, string>> $extra Extra map.
	 * @return array<string, array<int, string>>
	 */
	private static function merge_maps( array $base, array $extra ) {
		foreach ( $extra as $term => $synonyms ) {
			$base[ $term ] = array_values(
				array_unique(
					array_merge( isset( $base[ $term ] ) ? $base[ $term ] : array(), $synonyms )
				)
			);
		}

		return $base;
	}
}
