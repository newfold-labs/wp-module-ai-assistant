<?php
/**
 * Generates synonym suggestions from site content and/or LLM.
 *
 * @package NewfoldLabs\WP\Module\AIAssistant\Search
 */

namespace NewfoldLabs\WP\Module\AIAssistant\Search;

use NewfoldLabs\WP\Module\AIAssistant\Search\BM25\Tokenizer;
use NewfoldLabs\WP\Module\AIAssistant\Services\AiAssistantWorker;
use NewfoldLabs\WP\Module\AIAssistant\Services\KnowledgeStore;

/**
 * Two strategies:
 *   content — statistical co-occurrence analysis of published content
 *   llm     — AiAssistantWorker prompt to suggest synonyms
 */
class SynonymSuggestor {

	/**
	 * Words that should never be suggested as synonyms — function words,
	 * generic verbs, and highly ambiguous terms that produce noise.
	 */
	const NOISE_WORDS = array(
		'within', 'without', 'instead', 'about', 'around', 'between',
		'through', 'during', 'before', 'after', 'above', 'below',
		'under', 'over', 'here', 'there', 'where', 'what', 'when',
		'which', 'who', 'whom', 'whose', 'this', 'that', 'these',
		'those', 'some', 'any', 'every', 'each', 'both', 'all',
		'much', 'many', 'more', 'most', 'few', 'several', 'such',
		'own', 'same', 'other', 'another', 'else', 'also', 'very',
		'just', 'only', 'even', 'still', 'already', 'yet', 'once',
		'hereby', 'herein', 'thereof', 'thereto', 'thence',
		'whereby', 'wherein', 'whereas', 'whereupon', 'whosoever',
		'getting', 'get', 'got', 'having', 'doing', 'being',
		'making', 'taking', 'going', 'coming', 'using',
		'based', 'used', 'given', 'following', 'regarding',
		'including', 'excluding', 'concerning', 'according',
		'depending', 'related', 'known', 'various',
		'certain', 'specific', 'particular', 'multiple', 'single',
		'available', 'possible', 'current', 'general', 'common',
		'typical', 'standard', 'regular', 'normal', 'simple',
		'basic', 'major', 'main', 'primary', 'key', 'total',
		'full', 'whole', 'entire', 'complete', 'partial',
		'respective', 'individual', 'separate', 'additional',
		'further', 'furthermore', 'moreover', 'nevertheless',
		'nonetheless', 'notwithstanding', 'hence', 'thus',
		'therefore', 'consequently', 'accordingly', 'besides',
		'likewise', 'similarly', 'conversely', 'rather',
	);

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
	 * Suggest synonyms using content analysis, LLM, or both.
	 *
	 * @param string $method 'content', 'llm', or 'both'.
	 * @return array<string, array<int, string>>
	 */
	public function suggest( $method = 'both' ) {
		$content = array();

		if ( in_array( $method, array( 'content', 'both' ), true ) ) {
			$content = $this->from_content();
		}

		if ( in_array( $method, array( 'llm', 'both' ), true ) ) {
			$llm = $this->from_llm();
			// Merge LLM suggestions into content results (LLM wins on conflict).
			foreach ( $llm as $term => $synonyms ) {
				$content[ $term ] = isset( $content[ $term ] )
					? array_values( array_unique( array_merge( $content[ $term ], $synonyms ) ) )
					: $synonyms;
			}
		}

		// Sort by key for readability.
		ksort( $content );

		return $content;
	}

	/**
	 * Generate suggestions by analyzing term co-occurrence in published content.
	 *
	 * Uses Jaccard similarity across documents to find distributionally similar
	 * term pairs, then filters out noise words for clean synonym candidates.
	 *
	 * @return array<string, array<int, string>>
	 */
	public function from_content() {
		$posts = $this->get_published_content( 200 );
		if ( empty( $posts ) ) {
			return array();
		}

		// Build term → document index and filter out noise words early.
		$term_docs = array();
		$doc_count = count( $posts );
		$doc_terms = array();

		foreach ( $posts as $index => $post ) {
			$text   = strtolower( $post->post_title . ' ' . $post->post_content );
			$tokens = $this->tokenizer->tokenize_query( $text );
			$unique = array_values(
				array_unique(
					array_diff( $tokens, self::NOISE_WORDS )
				)
			);
			$doc_terms[ $index ] = $unique;

			foreach ( $unique as $token ) {
				if ( ! isset( $term_docs[ $token ] ) ) {
					$term_docs[ $token ] = 0;
				}
				++$term_docs[ $token ];
			}
		}

		// Each term must appear in at least 5 documents with useful frequency.
		$candidate_terms = array_keys(
			array_filter(
				$term_docs,
				function ( $freq ) use ( $doc_count ) {
					return $freq >= 5 && $freq <= max( 5, $doc_count * 0.6 );
				}
			)
		);

		if ( count( $candidate_terms ) < 10 ) {
			return array();
		}

		/*
		 * Score pairs using Jaccard similarity: |A ∩ B| / |A ∪ B|.
		 * Unlike PMI, Jaccard naturally penalizes high-frequency generic words
		 * that happen to co-occur with many others.
		 */
		$pairs     = array();
		$limit     = count( $candidate_terms );
		$threshold = apply_filters( 'nfd_ai_assistant_synonym_jaccard_threshold', 0.08 );

		for ( $i = 0; $i < $limit; $i++ ) {
			$a   = $candidate_terms[ $i ];
			$fa  = $term_docs[ $a ];

			for ( $j = $i + 1; $j < $limit; $j++ ) {
				$b  = $candidate_terms[ $j ];
				$fb = $term_docs[ $b ];

				$co_occur = 0;
				foreach ( $doc_terms as $terms ) {
					if ( in_array( $a, $terms, true ) && in_array( $b, $terms, true ) ) {
						++$co_occur;
					}
				}

				if ( $co_occur < 3 ) {
					continue;
				}

				$union    = $fa + $fb - $co_occur;
				$jaccard  = $union > 0 ? $co_occur / $union : 0;

				if ( $jaccard >= $threshold ) {
					$pairs[] = array(
						'a'       => $a,
						'b'       => $b,
						'jaccard' => $jaccard,
					);
				}
			}
		}

		// Sort by Jaccard descending (best matches first).
		usort(
			$pairs,
			function ( $x, $y ) {
				return $y['jaccard'] <=> $x['jaccard'];
			}
		);

		// Map each term to its best pair matches (max 5 per term).
		$map = array();
		foreach ( $pairs as $pair ) {
			$a = $pair['a'];
			$b = $pair['b'];
			if ( ! isset( $map[ $a ] ) ) {
				$map[ $a ] = array();
			}
			if ( ! isset( $map[ $b ] ) ) {
				$map[ $b ] = array();
			}
			if ( count( $map[ $a ] ) < 5 ) {
				$map[ $a ][] = $b;
			}
			if ( count( $map[ $b ] ) < 5 ) {
				$map[ $b ][] = $a;
			}
		}

		// Only return entries with at least 2 synonyms for quality.
		$result = array();
		foreach ( $map as $term => $synonyms ) {
			$synonyms = array_values( array_unique( $synonyms ) );
			if ( count( $synonyms ) >= 2 ) {
				$result[ $term ] = $synonyms;
			}
		}

		return $result;
	}

	/**
	 * Generate suggestions by asking the AI Worker.
	 *
	 * @return array<string, array<int, string>>
	 */
	public function from_llm() {
		$snapshot  = KnowledgeStore::get_snapshot();
		$corpus    = ! empty( $snapshot['corpus'] ) ? $snapshot['corpus'] : array();
		$site_name = ! empty( $snapshot['identity']['name'] ) ? $snapshot['identity']['name'] : get_bloginfo( 'name' );

		// Build a compact content summary from corpus excerpts.
		$samples = array();
		foreach ( array_slice( $corpus, 0, 30 ) as $entry ) {
			$samples[] = $entry['title'] . ': ' . $entry['excerpt'];
		}
		$content_sample = implode( "\n", $samples );
		$content_sample = mb_substr( $content_sample, 0, 4000 );

		if ( '' === trim( $content_sample ) ) {
			return array();
		}

		$prompt = sprintf(
			'You are analyzing the content of "%s".'
			. "\n\n"
			. 'Task: Suggest domain-specific synonym pairs that would help a search engine'
			. ' match visitor questions to site pages.'
			. "\n\n"
			. 'RULES (strict):' . "\n"
			. '- A synonym MUST be substitutable for the original term in a sentence without changing its meaning.' . "\n"
			. '- GOOD pairs: pricing/cost, contact/reach, services/offerings, hours/timings.' . "\n"
			. '- BAD pairs (NEVER include these): hours/roasted, hours/service, pricing/coffee.' . "\n"
			. '  These are topically related but NOT substitutable — they are NOT synonyms.' . "\n"
			. '- Each pair must be from domain-specific terms relevant to this site.' . "\n"
			. '- Do NOT list words that merely co-occur on the same page.' . "\n"
			. '- Each root term must have at least 2 synonym suggestions.' . "\n"
			. '- Maximum 15 root terms per response.' . "\n"
			. "\n"
			. "SITE CONTENT SAMPLE:\n%s\n\n"
			. 'Output ONLY valid JSON matching this EXACT schema — no prose, no markdown:' . "\n"
			. '{"synonyms":{"term1":["synonym_a","synonym_b"],"term2":["synonym_c"]}}',
			$site_name,
			$content_sample
		);

		$raw = ( new AiAssistantWorker() )->ask( $prompt );
		if ( is_wp_error( $raw ) ) {
			$this->log( 'from_llm: Worker error — ' . $raw->get_error_message() );
			return array();
		}

		$raw_str = trim( (string) $raw );
		if ( '' === $raw_str ) {
			$this->log( 'from_llm: empty response from Worker' );
			return array();
		}

		$this->log( 'from_llm: raw response (first 500 chars) — ' . mb_substr( $raw_str, 0, 500 ) );

		// Parse JSON from response.
		$decoded = $this->parse_json_response( $raw_str );
		if ( empty( $decoded['synonyms'] ) || ! is_array( $decoded['synonyms'] ) ) {
			$this->log( 'from_llm: failed to parse synonyms from response' );
			return array();
		}

		$result = array();
		foreach ( Synonyms::sanitize_map( $decoded['synonyms'] ) as $term => $synonyms ) {
			if ( count( $synonyms ) >= 1 ) {
				$result[ $term ] = $synonyms;
			}
		}

		// Add inverse entries so every synonym is also searchable as a root term.
		$inverse = array();
		foreach ( $result as $term => $synonyms ) {
			foreach ( $synonyms as $synonym ) {
				if ( ! isset( $inverse[ $synonym ] ) ) {
					$inverse[ $synonym ] = array();
				}
				if ( ! in_array( $term, $inverse[ $synonym ], true ) ) {
					$inverse[ $synonym ][] = $term;
				}
			}
		}
		$result = array_merge( $result, $inverse );

		$this->log( 'from_llm: generated ' . count( $result ) . ' synonym groups' );

		return $result;
	}

	/**
	 * Conditionally log to debug.log when WP_DEBUG is on.
	 *
	 * @param string $message Log message.
	 * @return void
	 */
	private function log( $message ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[AI-Assistant SynonymSuggestor] ' . $message );
		}
	}

	/**
	 * Extract JSON from a potentially markdown-wrapped response.
	 *
	 * @param string $raw Raw LLM output.
	 * @return array<string, mixed>
	 */
	private function parse_json_response( $raw ) {
		$text = trim( $raw );
		$text = preg_replace( '/^```(?:json)?\s*/i', '', $text );
		$text = preg_replace( '/\s*```$/', '', $text );

		$start = strpos( $text, '{' );
		$end   = strrpos( $text, '}' );
		if ( false === $start || false === $end || $end <= $start ) {
			return array();
		}

		$json = substr( $text, $start, $end - $start + 1 );

		try {
			$decoded = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
			return is_array( $decoded ) ? $decoded : array();
		} catch ( \JsonException $e ) {
			return array();
		}
	}

	/**
	 * Get published posts/pages for content analysis.
	 *
	 * @param int $max Max posts to fetch.
	 * @return \WP_Post[]
	 */
	private function get_published_content( $max = 200 ) {
		$query = new \WP_Query(
			array(
				'post_type'      => KnowledgeStore::indexable_post_types(),
				'post_status'    => 'publish',
				'posts_per_page' => $max,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			)
		);

		return $query->posts;
	}
}
