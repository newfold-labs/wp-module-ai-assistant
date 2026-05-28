<?php
/**
 * Retriever contract for question-dependent context lookup.
 *
 * @package NewfoldLabs\WP\Module\AIAssistant\Services
 */

namespace NewfoldLabs\WP\Module\AIAssistant\Services;

/**
 * Interface for retrieving top-K relevant corpus excerpts.
 */
interface RetrieverInterface {

	/**
	 * Return top-K relevant corpus excerpts for a question.
	 *
	 * @param string $question Visitor question.
	 * @param int    $k        Max results.
	 * @return array<int, array<string, string>>
	 */
	public function top_k( $question, $k = 3 );
}
