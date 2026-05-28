<?php
/**
 * Public search service facade.
 *
 * @package NewfoldLabs\WP\Module\AIAssistant\Search
 */

namespace NewfoldLabs\WP\Module\AIAssistant\Search;

use NewfoldLabs\WP\Module\AIAssistant\Search\BM25\Provider;
use NewfoldLabs\WP\Module\AIAssistant\Search\Contracts\SearchProvider;

/**
 * Facade used by assistant and future REST/search consumers.
 */
class SearchService {

	/**
	 * Search provider.
	 *
	 * @var SearchProvider
	 */
	private $provider;

	/**
	 * Constructor.
	 *
	 * @param SearchProvider|null $provider Optional provider.
	 */
	public function __construct( ?SearchProvider $provider = null ) {
		$this->provider = $provider ?: $this->default_provider();
	}

	/**
	 * Search content.
	 *
	 * @param string             $query Query text.
	 * @param int                $limit Max result count.
	 * @param array<int, string> $types Post types.
	 * @return array<int, array<string, mixed>>
	 */
	public function search( $query, $limit = 5, array $types = array() ) {
		return $this->provider->search( new SearchQuery( $query, $types, $limit ) );
	}

	/**
	 * Rebuild the provider index.
	 *
	 * @return void
	 */
	public function rebuild() {
		$this->provider->rebuild();
	}

	/**
	 * Resolve provider, allowing future modules to replace BM25.
	 *
	 * @return SearchProvider
	 */
	private function default_provider() {
		$provider = apply_filters( 'newfold_aia_search_provider', null );
		if ( $provider instanceof SearchProvider ) {
			return $provider;
		}

		return new Provider();
	}
}
