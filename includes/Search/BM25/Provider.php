<?php
/**
 * BM25 search provider.
 *
 * @package NewfoldLabs\WP\Module\AIAssistant\Search\BM25
 */

namespace NewfoldLabs\WP\Module\AIAssistant\Search\BM25;

use NewfoldLabs\WP\Module\AIAssistant\Search\Contracts\SearchProvider;
use NewfoldLabs\WP\Module\AIAssistant\Search\IntentClassifier;
use NewfoldLabs\WP\Module\AIAssistant\Search\SearchQuery;
use NewfoldLabs\WP\Module\AIAssistant\Search\Synonyms;
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
			$this->log( 'search: no tokens from query "' . $query->get_query() . '"' );
			return array();
		}
		$tokens = ( new Synonyms( $this->tokenizer ) )->expand_tokens( $tokens );

		$stats = Schema::get_stats();
		if ( empty( $stats['total_docs'] ) ) {
			$this->log( 'search: total_docs is 0, no index to search' );
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
		$matched_terms = array();
		$scores        = array();
		$post_types    = array();

		foreach ( $rows as $row ) {
			$post_id = (int) $row['post_id'];
			$term    = (string) $row['term'];
			$tf      = $this->scorer->weighted_frequency( $row );

			if ( ! isset( $scores[ $post_id ] ) ) {
				$scores[ $post_id ]       = 0.0;
				$matched_terms[ $post_id ] = array();
				$post_types[ $post_id ]   = $row['post_type'];
			}
			$matched_terms[ $post_id ][] = $term;

			$scores[ $post_id ] += $this->scorer->score_term(
				$tf,
				$matching_docs[ $term ],
				$stats['total_docs'],
				(int) $row['doc_length'],
				(float) $stats['avgdl']
			);
		}

		// Apply intent-based post-type boosting.
		$intent = $query->get_intent();
		if ( '' === $intent ) {
			$classifier = new IntentClassifier();
			$intent     = $classifier->classify( $query->get_query() );
		}
		if ( '' !== $intent ) {
			$boosts = $this->get_intent_boosts( $intent );
			foreach ( $scores as $pid => $score ) {
				$ptype = isset( $post_types[ $pid ] ) ? $post_types[ $pid ] : 'page';
				$boost = isset( $boosts[ $ptype ] ) ? (float) $boosts[ $ptype ] : 1.0;
				$scores[ $pid ] = $score * $boost;
			}
		}

		arsort( $scores, SORT_NUMERIC );
		$scores = array_slice( $scores, 0, $query->get_limit(), true );

		return $this->format_results( $scores, $matched_terms );
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
	 * @param array<int, float>                 $scores        Scores keyed by post ID.
	 * @param array<int, array<int, string>>    $matched_terms Matched terms keyed by post ID.
	 * @return array<int, array<string, mixed>>
	 */
	private function format_results( array $scores, array $matched_terms ) {
		$results = array();

		foreach ( $scores as $post_id => $score ) {
			$post = get_post( $post_id );
			if ( ! $post || 'publish' !== $post->post_status ) {
				$this->log( 'format_results: post ' . $post_id . ' skipped (status: ' . ( $post ? $post->post_status : 'not found' ) . ')' );
				continue;
			}

			$entry          = SnapshotBuilder::build_corpus_entry( $post );
			$entry['excerpt'] = $this->build_relevant_excerpt(
				$post,
				isset( $matched_terms[ $post_id ] ) ? $matched_terms[ $post_id ] : array()
			);
			$entry['score'] = $score;
			$results[]      = $entry;
		}

		return $results;
	}

	/**
	 * Build an excerpt around matched terms instead of the top of the page.
	 *
	 * Hours / contact / location info is typically at the bottom of a page,
	 * so this finds the LAST occurrence of any matched term and builds the
	 * excerpt from just before it — naturally capturing footer-style content
	 * without hardcoded keywords.
	 *
	 * @param \WP_Post           $post  Post object.
	 * @param array<int, string> $terms Matched terms.
	 * @return string
	 */
	private function build_relevant_excerpt( \WP_Post $post, array $terms ) {
		$content = $this->prepare_excerpt_content( $post->post_content );
		if ( '' === $content ) {
			return SnapshotBuilder::build_corpus_entry( $post )['excerpt'];
		}

		$unique_terms = array_values(
			array_filter(
				array_unique( $terms ),
				function ( $t ) {
					return strlen( (string) $t ) >= 2;
				}
			)
		);

		if ( empty( $unique_terms ) ) {
			return SnapshotBuilder::build_corpus_entry( $post )['excerpt'];
		}

		// Find the LAST occurrence of any matched term across the full content.
		// Hours / contact info typically lives at the bottom of business pages,
		// so the last occurrence naturally targets that section.
		$last_pos = -1;
		foreach ( $unique_terms as $term ) {
			$pos = 0;
			while ( false !== ( $pos = stripos( $content, $term, $pos ) ) ) {
				if ( $pos > $last_pos ) {
					$last_pos = $pos;
				}
				++$pos;
			}
		}

		if ( -1 === $last_pos ) {
			return wp_trim_words( $content, 80, '...' );
		}

		// Build excerpt 500 chars before the last occurrence.
		$start   = max( 0, $last_pos - 500 );
		$snippet = substr( $content, $start, 4000 );
		$snippet = wp_trim_words( $snippet, 400, '...' );

		return $start > 0 ? '...' . $snippet : $snippet;
	}

	/**
	 * Convert block markup to visible text for excerpts.
	 *
	 * @param string $content Raw post content.
	 * @return string
	 */
	private function prepare_excerpt_content( $content ) {
		$content = (string) $content;

		if ( function_exists( 'do_blocks' ) && function_exists( 'has_blocks' ) && has_blocks( $content ) ) {
			$content = do_blocks( $content );
		}

		return trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $content ) ) );
	}

	/**
	 * Return boost multipliers by intent for each post type.
	 *
	 * Boost config can be overridden via the
	 * 'newfold_aia_bm25_intent_boosts' filter (e.g. for WooCommerce
	 * to add 'product' => 1.5 under transactional intent).
	 *
	 * @param string $intent One of navigational, transactional, informational, support.
	 * @return array<string, float> Post-type => boost factor.
	 */
	private function get_intent_boosts( $intent ) {
		$defaults = array(
			'navigational' => array(
				'page' => 1.3,
			),
			'transactional' => array(
				'page' => 1.0,
			),
			'informational' => array(
				'post' => 1.3,
				'page' => 1.0,
			),
			'support' => array(),
		);

		$boosts = isset( $defaults[ $intent ] ) ? $defaults[ $intent ] : array();

		/**
		 * Filter intent-based boost multipliers.
		 *
		 * @param array<string, float> $boosts Post-type => boost.
		 * @param string               $intent Current intent.
		 */
		return (array) apply_filters( 'newfold_aia_bm25_intent_boosts', $boosts, $intent );
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
			error_log( '[AI-Assistant BM25 Provider] ' . $message );
		}
	}
}
