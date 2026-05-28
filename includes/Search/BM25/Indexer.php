<?php
/**
 * BM25 post indexer.
 *
 * @package NewfoldLabs\WP\Module\AIAssistant\Search\BM25
 */

namespace NewfoldLabs\WP\Module\AIAssistant\Search\BM25;

use NewfoldLabs\WP\Module\AIAssistant\Services\KnowledgeStore;

/**
 * Maintains the local inverted index for published site content.
 */
class Indexer {

	/**
	 * Tokenizer.
	 *
	 * @var Tokenizer
	 */
	private $tokenizer;

	/**
	 * Constructor.
	 *
	 * @param Tokenizer|null $tokenizer Optional tokenizer.
	 */
	public function __construct( ?Tokenizer $tokenizer = null ) {
		$this->tokenizer = $tokenizer ?: new Tokenizer();
	}

	/**
	 * Register index maintenance hooks.
	 *
	 * @return void
	 */
	public static function register_hooks() {
		add_action( 'init', array( __CLASS__, 'maybe_create_schema' ), 5 );
		add_action( 'save_post', array( __CLASS__, 'on_save_post' ), 25, 2 );
		add_action( 'before_delete_post', array( __CLASS__, 'on_delete_post' ) );
		add_action( 'transition_post_status', array( __CLASS__, 'on_transition_post_status' ), 10, 3 );
		add_action( 'nfd_ai_assistant_rebuild_search_index', array( __CLASS__, 'run_scheduled_rebuild' ) );
	}

	/**
	 * Ensure schema exists.
	 *
	 * @return void
	 */
	public static function maybe_create_schema() {
		Schema::maybe_create_tables();
	}

	/**
	 * Handle post saves.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 * @return void
	 */
	public static function on_save_post( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || ! $post instanceof \WP_Post ) {
			return;
		}

		if ( 'publish' !== $post->post_status || ! in_array( $post->post_type, KnowledgeStore::indexable_post_types(), true ) ) {
			( new self() )->remove( $post_id );
			return;
		}

		( new self() )->index( $post_id );
	}

	/**
	 * Handle post deletion.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function on_delete_post( $post_id ) {
		( new self() )->remove( $post_id );
	}

	/**
	 * Remove posts leaving published state.
	 *
	 * @param string   $new_status New status.
	 * @param string   $old_status Old status.
	 * @param \WP_Post $post       Post object.
	 * @return void
	 */
	public static function on_transition_post_status( $new_status, $old_status, $post ) {
		if ( 'publish' === $old_status && 'publish' !== $new_status && $post instanceof \WP_Post ) {
			( new self() )->remove( $post->ID );
		}
	}

	/**
	 * Schedule a full rebuild if one is not already queued.
	 *
	 * @return void
	 */
	public static function schedule_rebuild() {
		if ( ! wp_next_scheduled( 'nfd_ai_assistant_rebuild_search_index' ) ) {
			wp_schedule_single_event( time() + 30, 'nfd_ai_assistant_rebuild_search_index' );
		}
	}

	/**
	 * Run scheduled full rebuild.
	 *
	 * @return void
	 */
	public static function run_scheduled_rebuild() {
		( new self() )->rebuild();
	}

	/**
	 * Index one post.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function index( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status || ! in_array( $post->post_type, KnowledgeStore::indexable_post_types(), true ) ) {
			$this->remove( $post_id );
			return;
		}

		Schema::maybe_create_tables();

		$fields     = $this->tokenize_post_fields( $post );
		$doc_length = count( $fields['title'] ) + count( $fields['excerpt'] ) + count( $fields['content'] );

		global $wpdb;
		$terms_table = Schema::terms_table();
		$docs_table  = Schema::docs_table();

		$this->remove( $post_id );

		if ( 0 === $doc_length ) {
			return;
		}

		$frequencies = $this->build_term_frequencies( $fields );

		foreach ( $frequencies as $term => $counts ) {
			$wpdb->replace(
				$terms_table,
				array(
					'term'       => $term,
					'post_id'    => (int) $post_id,
					'tf_title'   => min( 65535, (int) $counts['title'] ),
					'tf_excerpt' => min( 65535, (int) $counts['excerpt'] ),
					'tf_content' => min( 65535, (int) $counts['content'] ),
				),
				array( '%s', '%d', '%d', '%d', '%d' )
			);
		}

		$wpdb->replace(
			$docs_table,
			array(
				'post_id'    => (int) $post_id,
				'post_type'  => (string) $post->post_type,
				'doc_length' => min( 65535, $doc_length ),
				'indexed_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%d', '%s' )
		);

		Schema::invalidate_stats();
	}

	/**
	 * Remove one post from the index.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function remove( $post_id ) {
		Schema::maybe_create_tables();

		global $wpdb;
		$post_id     = (int) $post_id;
		$terms_table = Schema::terms_table();
		$docs_table  = Schema::docs_table();

		$wpdb->delete( $terms_table, array( 'post_id' => $post_id ), array( '%d' ) );
		$wpdb->delete( $docs_table, array( 'post_id' => $post_id ), array( '%d' ) );

		Schema::invalidate_stats();
	}

	/**
	 * Rebuild the full index synchronously.
	 *
	 * @return void
	 */
	public function rebuild() {
		Schema::maybe_create_tables();

		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Schema::terms_table() );
		$wpdb->query( 'TRUNCATE TABLE ' . Schema::docs_table() );

		$page = 1;
		do {
			$query = new \WP_Query(
				array(
					'post_type'      => KnowledgeStore::indexable_post_types(),
					'post_status'    => 'publish',
					'posts_per_page' => 50,
					'paged'          => $page,
					'fields'         => 'ids',
					'no_found_rows'  => false,
				)
			);

			foreach ( $query->posts as $post_id ) {
				$this->index( $post_id );
			}

			++$page;
		} while ( $page <= (int) $query->max_num_pages );

		Schema::invalidate_stats();
		update_option( 'nfd_ai_assistant_search_indexed_at', gmdate( 'c' ), false );
	}

	/**
	 * Tokenize post fields.
	 *
	 * @param \WP_Post $post Post object.
	 * @return array{title:array<int,string>,excerpt:array<int,string>,content:array<int,string>}
	 */
	private function tokenize_post_fields( \WP_Post $post ) {
		$excerpt = has_excerpt( $post ) ? get_the_excerpt( $post ) : wp_trim_words( wp_strip_all_tags( $post->post_content ), 40, '' );
		$cap     = (int) apply_filters( 'newfold_aia_content_token_cap', 500, $post->post_type );

		return array(
			'title'   => $this->tokenizer->tokenize( get_the_title( $post ), 0, $post->post_type ),
			'excerpt' => $this->tokenizer->tokenize( $excerpt, 0, $post->post_type ),
			'content' => $this->tokenizer->tokenize( $post->post_content, max( 1, $cap ), $post->post_type ),
		);
	}

	/**
	 * Pack per-field term frequencies into one row per term/document.
	 *
	 * @param array<string, array<int, string>> $fields Tokenized fields.
	 * @return array<string, array{title:int,excerpt:int,content:int}>
	 */
	private function build_term_frequencies( array $fields ) {
		$frequencies = array();

		foreach ( array( 'title', 'excerpt', 'content' ) as $field ) {
			foreach ( $fields[ $field ] as $term ) {
				if ( ! isset( $frequencies[ $term ] ) ) {
					$frequencies[ $term ] = array(
						'title'   => 0,
						'excerpt' => 0,
						'content' => 0,
					);
				}
				++$frequencies[ $term ][ $field ];
			}
		}

		return $frequencies;
	}
}
