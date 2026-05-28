<?php
/**
 * BM25 scoring.
 *
 * @package NewfoldLabs\WP\Module\AIAssistant\Search\BM25
 */

namespace NewfoldLabs\WP\Module\AIAssistant\Search\BM25;

/**
 * Calculates weighted BM25 scores for candidate documents.
 */
class Scorer {

	/**
	 * Score one term/document pair.
	 *
	 * @param int   $term_frequency Weighted term frequency in document.
	 * @param int   $matching_docs  Number of docs containing the term.
	 * @param int   $total_docs     Total docs in corpus.
	 * @param int   $doc_length     Document length.
	 * @param float $avgdl          Average document length.
	 * @return float
	 */
	public function score_term( $term_frequency, $matching_docs, $total_docs, $doc_length, $avgdl ) {
		$term_frequency = (float) $term_frequency;
		$matching_docs  = max( 0, (int) $matching_docs );
		$total_docs     = max( 0, (int) $total_docs );
		$doc_length     = max( 1, (int) $doc_length );
		$avgdl          = max( 1.0, (float) $avgdl );

		if ( $term_frequency <= 0 || $total_docs <= 0 ) {
			return 0.0;
		}

		$k1  = (float) apply_filters( 'newfold_aia_bm25_k1', 1.5 );
		$b   = (float) apply_filters( 'newfold_aia_bm25_b', 0.75 );
		$idf = log( ( ( $total_docs - $matching_docs + 0.5 ) / ( $matching_docs + 0.5 ) ) + 1 );

		$denominator = $term_frequency + $k1 * ( 1 - $b + $b * ( $doc_length / $avgdl ) );

		return $idf * ( ( $term_frequency * ( $k1 + 1 ) ) / $denominator );
	}

	/**
	 * Apply configured field weights to term frequencies.
	 *
	 * @param array<string, int> $row Index row.
	 * @return float
	 */
	public function weighted_frequency( array $row ) {
		$weights = apply_filters(
			'newfold_aia_bm25_field_weights',
			array(
				'title'   => 3.0,
				'excerpt' => 2.0,
				'content' => 1.0,
			)
		);
		$weights = wp_parse_args(
			$weights,
			array(
				'title'   => 3.0,
				'excerpt' => 2.0,
				'content' => 1.0,
			)
		);

		return ( (int) $row['tf_title'] * (float) $weights['title'] )
			+ ( (int) $row['tf_excerpt'] * (float) $weights['excerpt'] )
			+ ( (int) $row['tf_content'] * (float) $weights['content'] );
	}
}
