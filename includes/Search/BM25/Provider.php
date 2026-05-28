<?php
/**
 * BM25 search provider.
 *
 * @package NewfoldLabs\WP\Module\AIAssistant\Search\BM25
 */

namespace NewfoldLabs\WP\Module\AIAssistant\Search\BM25;

use NewfoldLabs\WP\Module\AIAssistant\Search\Contracts\SearchProvider;
use NewfoldLabs\WP\Module\AIAssistant\Search\SearchQuery;
use NewfoldLabs\WP\Module\AIAssistant\Services\KnowledgeStore;
use NewfoldLabs\WP\Module\AIAssistant\Services\SnapshotBuilder;

/**
 * Searches the local BM25 index.
 */
class Provider implements SearchProvider {

	/**
	 * Tokenizer.
	 *
	 * @var Tokenizer
	 */
	private $tokenizer;

	/**
	 * Scorer.
	 *
	 * @var Scorer
	 */
	private $scorer;

	/**
	 * Indexer.
	 *
	 * @var Indexer
	 */
	private $indexer;

	/**
	 * Constructor.
	 *
	 * @param Tokenizer|null $tokenizer Optional tokenizer.
	 * @param Scorer|null    $scorer    Optional scorer.
	 * @param Indexer|null   $indexer   Optional indexer.
	 */
	public function __construct( ?Tokenizer $tokenizer = null, ?Scorer $scorer = null, ?Indexer $indexer = null ) {
		$this->tokenizer = $tokenizer ?: new Tokenizer();
		$this->scorer    = $scorer ?: new Scorer();
		$this->indexer   = $indexer ?: new Indexer( $this->tokenizer );
	}

	/**
	 * Search indexed content.
	 *
	 * @param SearchQuery $query Search query.
	 * @return array<int, array<string, mixed>>
	 */
	public function search( SearchQuery $query ) {
		Schema::maybe_create_tables();

		$tokens = $this->tokenizer->tokenize_query( $query->get_query() );
		if ( empty( $tokens ) ) {
			return array();
		}

		$stats = Schema::get_stats();
		if ( empty( $stats['total_docs'] ) ) {
			return array();
		}

		$types = $query->get_types();
		if ( empty( $types ) ) {
			$types = KnowledgeStore::indexable_post_types();
		}

		$rows = $this->fetch_term_rows( $tokens, $types );
		if ( empty( $rows ) ) {
			return array();
		}

		$matching_docs = $this->matching_docs_by_term( $rows );
		$scores        = array();

		foreach ( $rows as $row ) {
			$post_id = (int) $row['post_id'];
			$term    = (string) $row['term'];
			$tf      = $this->scorer->weighted_frequency( $row );

			if ( ! isset( $scores[ $post_id ] ) ) {
				$scores[ $post_id ] = 0.0;
			}

			$scores[ $post_id ] += $this->scorer->score_term(
				$tf,
				$matching_docs[ $term ],
				$stats['total_docs'],
				(int) $row['doc_length'],
				(float) $stats['avgdl']
			);
		}

		arsort( $scores, SORT_NUMERIC );
		$scores = array_slice( $scores, 0, $query->get_limit(), true );

		return $this->format_results( $scores );
	}

	/**
	 * Index one post.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function index( $post_id ) {
		$this->indexer->index( $post_id );
	}

	/**
	 * Remove one post.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function remove( $post_id ) {
		$this->indexer->remove( $post_id );
	}

	/**
	 * Rebuild index.
	 *
	 * @return void
	 */
	public function rebuild() {
		$this->indexer->rebuild();
	}

	/**
	 * Fetch rows for query terms and post types.
	 *
	 * @param array<int, string> $tokens Query tokens.
	 * @param array<int, string> $types  Post types.
	 * @return array<int, array<string, mixed>>
	 */
	private function fetch_term_rows( array $tokens, array $types ) {
		global $wpdb;

		$term_placeholders = implode( ', ', array_fill( 0, count( $tokens ), '%s' ) );
		$type_placeholders = implode( ', ', array_fill( 0, count( $types ), '%s' ) );
		$args              = array_merge( $tokens, $types );
		$terms_table       = Schema::terms_table();
		$docs_table        = Schema::docs_table();

		$sql = "SELECT t.term, t.post_id, t.tf_title, t.tf_excerpt, t.tf_content, d.doc_length, d.post_type
			FROM {$terms_table} t
			INNER JOIN {$docs_table} d ON t.post_id = d.post_id
			WHERE t.term IN ({$term_placeholders})
			AND d.post_type IN ({$type_placeholders})";

		return $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
	}

	/**
	 * Count matching documents per term.
	 *
	 * @param array<int, array<string, mixed>> $rows Index rows.
	 * @return array<string, int>
	 */
	private function matching_docs_by_term( array $rows ) {
		$counts = array();
		foreach ( $rows as $row ) {
			$term = (string) $row['term'];
			if ( ! isset( $counts[ $term ] ) ) {
				$counts[ $term ] = 0;
			}
			++$counts[ $term ];
		}
		return $counts;
	}

	/**
	 * Format post records for prompt assembly and REST consumers.
	 *
	 * @param array<int, float> $scores Scores keyed by post ID.
	 * @return array<int, array<string, mixed>>
	 */
	private function format_results( array $scores ) {
		$results = array();

		foreach ( $scores as $post_id => $score ) {
			$post = get_post( $post_id );
			if ( ! $post || 'publish' !== $post->post_status ) {
				continue;
			}

			$entry          = SnapshotBuilder::build_corpus_entry( $post );
			$entry['score'] = $score;
			$results[]      = $entry;
		}

		return $results;
	}
}
