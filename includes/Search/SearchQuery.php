<?php
/**
 * Search query value object.
 *
 * @package NewfoldLabs\WP\Module\AIAssistant\Search
 */

namespace NewfoldLabs\WP\Module\AIAssistant\Search;

/**
 * Normalizes search inputs before they reach a provider.
 */
class SearchQuery {

	/**
	 * Raw query text.
	 *
	 * @var string
	 */
	private $query;

	/**
	 * Post types to search.
	 *
	 * @var array<int, string>
	 */
	private $types;

	/**
	 * Max result count.
	 *
	 * @var int
	 */
	private $limit;

	/**
	 * Constructor.
	 *
	 * @param string             $query Raw query text.
	 * @param array<int, string> $types Post types to search.
	 * @param int                $limit Max result count.
	 */
	public function __construct( $query, array $types = array(), $limit = 5 ) {
		$this->query = trim( (string) $query );
		$this->types = array_values(
			array_filter(
				array_map( 'sanitize_key', $types )
			)
		);
		$this->limit = max( 1, min( 20, absint( $limit ) ) );
	}

	/**
	 * Raw query text.
	 *
	 * @return string
	 */
	public function get_query() {
		return $this->query;
	}

	/**
	 * Post types to search.
	 *
	 * @return array<int, string>
	 */
	public function get_types() {
		return $this->types;
	}

	/**
	 * Max result count.
	 *
	 * @return int
	 */
	public function get_limit() {
		return $this->limit;
	}
}
