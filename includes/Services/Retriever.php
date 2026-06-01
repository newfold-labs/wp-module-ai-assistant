<?php
/**
 * Keyword retriever over the cached corpus.
 *
 * @package NewfoldLabs\WP\Module\AIAssistant\Services
 */

namespace NewfoldLabs\WP\Module\AIAssistant\Services;

use NewfoldLabs\WP\Module\AIAssistant\Search\SearchService;

/**
 * Scores snapshot corpus entries against a visitor question.
 */
class Retriever implements RetrieverInterface {

	/**
	 * Return top-K relevant corpus excerpts.
	 *
	 * Also ensures the site front page is always included — long homepages get
	 * unfairly penalised by BM25 length normalisation and the fallback doesn't
	 * run when BM25 returns any results.
	 *
	 * @param string $question Visitor question.
	 * @param int    $k        Max results.
	 * @return array<int, array<string, string>>
	 */
	public function top_k( $question, $k = 3 ) {
		$search_results = ( new SearchService() )->search( $question, $k, KnowledgeStore::indexable_post_types() );

		if ( ! empty( $search_results ) ) {
			return $this->format_results( $search_results );
		}

		$snapshot = KnowledgeStore::get_snapshot();
		$corpus   = ! empty( $snapshot['corpus'] ) ? $snapshot['corpus'] : array();

		if ( empty( $corpus ) ) {
			return array();
		}

		$tokens = $this->tokenize( $question );
		if ( empty( $tokens ) ) {
			return array_slice( $this->format_results( $corpus ), 0, $k );
		}

		$scored = array();
		foreach ( $corpus as $entry ) {
			$haystack = strtolower( $entry['title'] . ' ' . $entry['excerpt'] );
			$score    = 0;
			foreach ( $tokens as $token ) {
				if ( false !== strpos( $haystack, $token ) ) {
					++$score;
				}
			}
			if ( $score > 0 ) {
				$scored[] = array(
					'score' => $score,
					'entry' => $entry,
				);
			}
		}

		if ( empty( $scored ) ) {
			$wp_results = $this->query_wordpress_search( $question, $k );
			if ( ! empty( $wp_results ) ) {
				return $wp_results;
			}
			return array_slice( $this->format_results( $corpus ), 0, $k );
		}

		usort(
			$scored,
			function ( $a, $b ) {
				return $b['score'] <=> $a['score'];
			}
		);

		$entries = array_map(
			function ( $row ) {
				return $row['entry'];
			},
			array_slice( $scored, 0, $k )
		);

		return $this->format_results( $entries );
	}

	/**
	 * WP_Query search fallback.
	 *
	 * @param string $question Question text.
	 * @param int    $k        Max results.
	 * @return array<int, array<string, string>>
	 */
	private function query_wordpress_search( $question, $k ) {
		$query = new \WP_Query(
			array(
				's'              => $question,
				'post_type'      => KnowledgeStore::indexable_post_types(),
				'post_status'    => 'publish',
				'posts_per_page' => $k,
				'no_found_rows'  => true,
			)
		);

		$entries = array();
		foreach ( $query->posts as $post ) {
			$entries[] = SnapshotBuilder::build_corpus_entry( $post );
		}

		return $this->format_results( $entries );
	}

	/**
	 * Tokenize and strip stopwords.
	 *
	 * @param string $question Question text.
	 * @return array<int, string>
	 */
	private function tokenize( $question ) {
		$stopwords = array( 'a', 'an', 'the', 'is', 'are', 'was', 'were', 'what', 'how', 'when', 'where', 'who', 'why', 'do', 'does', 'can', 'could', 'would', 'should', 'i', 'me', 'my', 'you', 'your', 'we', 'our', 'they', 'their', 'it', 'its', 'to', 'of', 'in', 'on', 'for', 'and', 'or', 'but' );
		$words     = preg_split( '/\s+/', strtolower( preg_replace( '/[^a-z0-9\s]/i', ' ', $question ) ) );
		$tokens    = array();

		foreach ( $words as $word ) {
			$word = trim( $word );
			if ( strlen( $word ) < 3 || in_array( $word, $stopwords, true ) ) {
				continue;
			}
			$tokens[] = $word;
		}

		return array_values( array_unique( $tokens ) );
	}

	/**
	 * Normalize corpus rows for prompt assembly.
	 *
	 * @param array<int, array<string, mixed>> $entries Corpus entries.
	 * @return array<int, array<string, string>>
	 */
	private function format_results( array $entries ) {
		$formatted = array();
		foreach ( $entries as $entry ) {
			$formatted[] = array(
				'id'      => (string) $entry['id'],
				'title'   => (string) $entry['title'],
				'url'     => (string) $entry['permalink'],
				'excerpt' => (string) $entry['excerpt'],
			);
		}
		return $formatted;
	}
}
