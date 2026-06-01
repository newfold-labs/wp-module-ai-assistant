<?php
/**
 * BM25 search integration tests.
 *
 * @package NewfoldLabs\WP\Module\AIAssistant
 */

use NewfoldLabs\WP\Module\AIAssistant\Search\BM25\Indexer;
use NewfoldLabs\WP\Module\AIAssistant\Search\BM25\Schema;
use NewfoldLabs\WP\Module\AIAssistant\Search\SearchService;
use NewfoldLabs\WP\Module\AIAssistant\Search\Synonyms;
use NewfoldLabs\WP\Module\AIAssistant\Services\KnowledgeStore;
use NewfoldLabs\WP\Module\AIAssistant\Services\Retriever;

/**
 * Verifies the BM25 index works end-to-end.
 */
class BM25SearchTest extends WP_UnitTestCase {

	/**
	 * Prepare schema and stable post types for each test.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		add_filter( 'nfd_ai_assistant_indexable_post_types', array( $this, 'indexable_post_types' ) );
		Schema::maybe_create_tables();
		$this->truncate_index();
		delete_option( Indexer::REBUILD_PROGRESS_OPTION );
		delete_option( Synonyms::OPTION );
		delete_option( 'nfd_ai_assistant_search_indexed_at' );
	}

	/**
	 * Clean up filters and index tables.
	 *
	 * @return void
	 */
	public function tear_down() {
		$this->truncate_index();
		remove_filter( 'nfd_ai_assistant_indexable_post_types', array( $this, 'indexable_post_types' ) );
		delete_option( KnowledgeStore::SNAPSHOT_OPTION );
		delete_option( Schema::STATS_OPTION );
		delete_option( Indexer::REBUILD_PROGRESS_OPTION );
		delete_option( Synonyms::OPTION );
		delete_option( 'nfd_ai_assistant_search_indexed_at' );

		parent::tear_down();
	}

	/**
	 * Use core post types for deterministic tests.
	 *
	 * @return array<int, string>
	 */
	public function indexable_post_types() {
		return array( 'post', 'page' );
	}

	/**
	 * SearchService returns the strongest BM25 match first.
	 */
	public function test_search_service_returns_ranked_bm25_results() {
		$espresso_id = self::factory()->post->create(
			array(
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_title'   => 'Espresso Catering',
				'post_content' => 'Espresso espresso catering bar for weddings and private events.',
			)
		);
		self::factory()->post->create(
			array(
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_title'   => 'Contact Our Team',
				'post_content' => 'Reach us for general questions about the business.',
			)
		);

		( new Indexer() )->rebuild();

		$results = ( new SearchService() )->search( 'espresso catering', 2, array( 'post' ) );

		$this->assertNotEmpty( $results );
		$this->assertSame( $espresso_id, (int) $results[0]['id'] );
		$this->assertGreaterThan( 0, (float) $results[0]['score'] );
	}

	/**
	 * Retriever falls back to the snapshot corpus when BM25 has no hit.
	 */
	public function test_retriever_falls_back_to_snapshot_corpus() {
		KnowledgeStore::set_snapshot(
			array(
				'corpus' => array(
					array(
						'id'        => 123,
						'title'     => 'Bakery Menu',
						'permalink' => 'https://example.com/menu',
						'excerpt'   => 'Fresh sourdough bread and pastries are available daily.',
					),
				),
			)
		);

		$results = ( new Retriever() )->top_k( 'sourdough bread', 1 );

		$this->assertCount( 1, $results );
		$this->assertSame( '123', $results[0]['id'] );
		$this->assertSame( 'Bakery Menu', $results[0]['title'] );
	}

	/**
	 * Synonyms expand visitor terms before BM25 lookup.
	 */
	public function test_search_service_expands_synonyms() {
		$page_id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Visit Our Cafe',
				'post_content' => 'Our cafe opening hours are 8am to 5pm every weekday.',
			)
		);

		( new Indexer() )->rebuild();

		$results = ( new SearchService() )->search( 'cafe timings', 1, array( 'page' ) );

		$this->assertNotEmpty( $results );
		$this->assertSame( $page_id, (int) $results[0]['id'] );
		$this->assertStringContainsString( 'Our Hours', $results[0]['excerpt'] );
		$this->assertStringContainsString( 'Tuesday 7:00 AM - 5:00 PM', $results[0]['excerpt'] );
	}

	/**
	 * Page indexing reaches important block content near the bottom of homepages.
	 */
	public function test_page_indexing_reaches_late_block_content() {
		$filler = str_repeat( '<!-- wp:paragraph --><p>Community coffee pastries Asheville sustainable beans local culture.</p><!-- /wp:paragraph -->', 120 );
		$page_id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Coffee Shop Homepage',
				'post_content' => $filler . '<!-- wp:heading --><h2>Our Hours</h2><!-- /wp:heading --><!-- wp:paragraph --><p>Tuesday 7:00 AM - 5:00 PM. Wednesday 7:00 AM - 5:00 PM. Friday 7:00 AM - 6:00 PM.</p><!-- /wp:paragraph -->',
			)
		);

		( new Indexer() )->rebuild();

		$results = ( new SearchService() )->search( 'cafe timings', 1, array( 'page' ) );

		$this->assertNotEmpty( $results );
		$this->assertSame( $page_id, (int) $results[0]['id'] );
	}

	/**
	 * Default timing synonyms avoid broad open-ended matches.
	 */
	public function test_default_timing_synonyms_avoid_broad_open_term() {
		$map = \NewfoldLabs\WP\Module\AIAssistant\Search\Synonyms::get_default_map();

		$this->assertContains( 'hours', $map['timings'] );
		$this->assertContains( 'opening', $map['timings'] );
		$this->assertNotContains( 'open', $map['timings'] );
	}

	/**
	 * Async rebuild records progress and completes over batches.
	 */
	public function test_indexer_tracks_batch_rebuild_progress() {
		add_filter( 'newfold_aia_search_rebuild_batch_size', array( $this, 'small_batch_size' ) );

		self::factory()->post->create_many(
			3,
			array(
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_content' => 'Seasonal menu item.',
			)
		);

		$indexer = new Indexer();
		$indexer->start_rebuild();

		$progress = Indexer::get_rebuild_progress();
		$this->assertSame( 'running', $progress['status'] );
		$this->assertSame( 3, (int) $progress['total'] );

		$indexer->process_rebuild_batch();
		$this->assertSame( 2, (int) Indexer::get_rebuild_progress()['processed'] );

		$indexer->process_rebuild_batch();
		$progress = Indexer::get_rebuild_progress();

		remove_filter( 'newfold_aia_search_rebuild_batch_size', array( $this, 'small_batch_size' ) );

		$this->assertSame( 'complete', $progress['status'] );
		$this->assertSame( 3, (int) $progress['processed'] );
		$this->assertNotEmpty( get_option( 'nfd_ai_assistant_search_indexed_at', '' ) );
	}

	/**
	 * Force small batches for progress tests.
	 *
	 * @return int
	 */
	public function small_batch_size() {
		return 2;
	}

	/**
	 * Empty BM25 tables and cached stats.
	 *
	 * @return void
	 */
	private function truncate_index() {
		global $wpdb;

		$wpdb->query( 'TRUNCATE TABLE ' . Schema::terms_table() );
		$wpdb->query( 'TRUNCATE TABLE ' . Schema::docs_table() );
		Schema::invalidate_stats();
	}
}
