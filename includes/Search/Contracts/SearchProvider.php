<?php
/**
 * Search provider contract.
 *
 * @package NewfoldLabs\WP\Module\AIAssistant\Search\Contracts
 */

namespace NewfoldLabs\WP\Module\AIAssistant\Search\Contracts;

use NewfoldLabs\WP\Module\AIAssistant\Search\SearchQuery;

/**
 * Contract implemented by site-local and future remote search providers.
 */
interface SearchProvider {

	/**
	 * Search indexed site content.
	 *
	 * @param SearchQuery $query Search query.
	 * @return array<int, array<string, mixed>>
	 */
	public function search( SearchQuery $query );

	/**
	 * Index one post.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function index( $post_id );

	/**
	 * Remove one post from the index.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function remove( $post_id );

	/**
	 * Rebuild the full index.
	 *
	 * @return void
	 */
	public function rebuild();
}
