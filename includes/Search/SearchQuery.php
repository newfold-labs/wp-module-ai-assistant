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
	 * Allowable intent values.
	 */
	const INTENT_NAVIGATIONAL  = 'navigational';
	const INTENT_TRANSACTIONAL = 'transactional';
	const INTENT_INFORMATIONAL = 'informational';
	const INTENT_SUPPORT       = 'support';

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
	 * Query intent.
	 *
	 * @var string
	 */
	private $intent;

	/**
	 * Constructor.
	 *
	 * @param string             $query  Raw query text.
	 * @param array<int, string> $types  Post types to search.
	 * @param int                $limit  Max result count.
	 * @param string             $intent Query intent (navigational|transactional|informational|support).
	 */
	public function __construct( $query, array $types = array(), $limit = 5, $intent = '' ) {
		$this->query  = trim( (string) $query );
		$this->types  = array_values(
			array_filter(
				array_map( 'sanitize_key', $types )
			)
		);
		$this->limit  = max( 1, min( 20, absint( $limit ) ) );
		$this->intent = in_array( $intent, array( self::INTENT_NAVIGATIONAL, self::INTENT_TRANSACTIONAL, self::INTENT_INFORMATIONAL, self::INTENT_SUPPORT ), true ) ? $intent : '';
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

	/**
	 * Query intent.
	 *
	 * @return string
	 */
	public function get_intent() {
		return $this->intent;
	}

	/**
	 * Set query intent (fluent).
	 *
	 * @param string $intent Intent value.
	 * @return $this
	 */
	public function set_intent( $intent ) {
		if ( in_array( $intent, array( self::INTENT_NAVIGATIONAL, self::INTENT_TRANSACTIONAL, self::INTENT_INFORMATIONAL, self::INTENT_SUPPORT ), true ) ) {
			$this->intent = $intent;
		}
		return $this;
	}
}
