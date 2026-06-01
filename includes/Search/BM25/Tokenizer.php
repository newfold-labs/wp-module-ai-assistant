<?php
/**
 * Simple tokenizer for BM25 indexing and queries.
 *
 * @package NewfoldLabs\WP\Module\AIAssistant\Search\BM25
 */

namespace NewfoldLabs\WP\Module\AIAssistant\Search\BM25;

/**
 * Converts text to normalized search terms.
 */
class Tokenizer {

	/**
	 * Tokenize text after removing markup, shortcodes, stopwords, and junk terms.
	 *
	 * @param string $text      Raw text.
	 * @param int    $limit     Optional max tokens after filtering.
	 * @param string $post_type Post type for token-cap filters.
	 * @return array<int, string>
	 */
	public function tokenize( $text, $limit = 0, $post_type = '' ) {
		$text = is_string( $text ) ? $text : '';

		if ( function_exists( 'strip_shortcodes' ) ) {
			$text = strip_shortcodes( $text );
		}

		$text = wp_strip_all_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// Split on:
		// 1. Non-alphanumeric characters (spaces, punctuation, etc.)
		// 2. camelCase / PascalCase boundaries
		// 3. Letter-to-number and number-to-letter transitions
		// Case-sensitive splitting must happen BEFORE strtolower().
		$raw = preg_split(
			'/[^a-zA-Z0-9]+|(?<=[a-z])(?=\d)|(?<=\d)(?=[a-z])|(?<=[a-z])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/',
			$text
		);

		if ( ! is_array( $raw ) ) {
			return array();
		}

		$raw  = array_map( 'strtolower', $raw );
		$text = strtolower( $text ); // keep lowered copy for filter

		$stopwords = array_fill_keys( $this->stopwords(), true );
		$tokens    = array();

		foreach ( $raw as $term ) {
			$term = trim( $term );
			if ( '' === $term || strlen( $term ) < 2 || strlen( $term ) > 64 ) {
				continue;
			}

			if ( ctype_digit( $term ) || isset( $stopwords[ $term ] ) ) {
				continue;
			}

			$tokens[] = $term;
			if ( $limit > 0 && count( $tokens ) >= $limit ) {
				break;
			}
		}

		return apply_filters( 'newfold_aia_search_tokens', $tokens, $text, $limit, $post_type );
	}

	/**
	 * Tokenize a visitor query and dedupe terms.
	 *
	 * @param string $query Query text.
	 * @return array<int, string>
	 */
	public function tokenize_query( $query ) {
		return array_values( array_unique( $this->tokenize( $query ) ) );
	}

	/**
	 * Small English stopword list.
	 *
	 * @return array<int, string>
	 */
	private function stopwords() {
		$words = array(
			'a',
			'about',
			'above',
			'after',
			'again',
			'against',
			'all',
			'am',
			'an',
			'and',
			'any',
			'are',
			'as',
			'at',
			'be',
			'because',
			'been',
			'before',
			'being',
			'below',
			'between',
			'both',
			'but',
			'by',
			'can',
			'could',
			'did',
			'do',
			'does',
			'doing',
			'down',
			'during',
			'each',
			'few',
			'for',
			'from',
			'further',
			'had',
			'has',
			'have',
			'having',
			'he',
			'her',
			'here',
			'hers',
			'herself',
			'him',
			'himself',
			'his',
			'how',
			'i',
			'if',
			'in',
			'into',
			'is',
			'it',
			'its',
			'itself',
			'me',
			'more',
			'most',
			'my',
			'myself',
			'no',
			'nor',
			'not',
			'of',
			'off',
			'on',
			'once',
			'only',
			'or',
			'other',
			'our',
			'ours',
			'ourselves',
			'out',
			'over',
			'own',
			'same',
			'she',
			'should',
			'so',
			'some',
			'such',
			'than',
			'that',
			'the',
			'their',
			'theirs',
			'them',
			'themselves',
			'then',
			'there',
			'these',
			'they',
			'this',
			'those',
			'through',
			'to',
			'too',
			'under',
			'until',
			'up',
			'very',
			'was',
			'we',
			'were',
			'what',
			'when',
			'where',
			'which',
			'while',
			'who',
			'whom',
			'why',
			'with',
			'would',
			'you',
			'your',
			'yours',
			'yourself',
			'yourselves',
		);

		return apply_filters( 'newfold_aia_search_stopwords', $words );
	}
}
