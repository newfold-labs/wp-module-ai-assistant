<?php
/**
 * BM25 search table schema.
 *
 * @package NewfoldLabs\WP\Module\AIAssistant\Search\BM25
 */

namespace NewfoldLabs\WP\Module\AIAssistant\Search\BM25;

/**
 * Owns database table names and schema installation.
 */
class Schema {

	const VERSION        = '1';
	const VERSION_OPTION = 'nfd_ai_assistant_search_schema_version';
	const STATS_OPTION   = 'nfd_ai_assistant_search_stats';

	/**
	 * Inverted index table name.
	 *
	 * @return string
	 */
	public static function terms_table() {
		global $wpdb;
		return $wpdb->prefix . 'aia_search_terms';
	}

	/**
	 * Document stats table name.
	 *
	 * @return string
	 */
	public static function docs_table() {
		global $wpdb;
		return $wpdb->prefix . 'aia_search_docs';
	}

	/**
	 * Ensure BM25 tables exist.
	 *
	 * @return void
	 */
	public static function maybe_create_tables() {
		$current = get_option( self::VERSION_OPTION );
		if ( self::VERSION === $current ) {
			return;
		}

		self::create_tables();
		update_option( self::VERSION_OPTION, self::VERSION, false );
	}

	/**
	 * Create or update BM25 tables.
	 *
	 * @return void
	 */
	public static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$terms_table     = self::terms_table();
		$docs_table      = self::docs_table();

		dbDelta(
			"CREATE TABLE {$terms_table} (
				term varchar(64) NOT NULL,
				post_id bigint(20) unsigned NOT NULL,
				tf_title smallint(5) unsigned NOT NULL DEFAULT 0,
				tf_excerpt smallint(5) unsigned NOT NULL DEFAULT 0,
				tf_content smallint(5) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (term, post_id),
				KEY post_id (post_id)
			) {$charset_collate};"
		);

		dbDelta(
			"CREATE TABLE {$docs_table} (
				post_id bigint(20) unsigned NOT NULL,
				post_type varchar(20) NOT NULL,
				doc_length smallint(5) unsigned NOT NULL DEFAULT 0,
				indexed_at datetime NOT NULL,
				PRIMARY KEY  (post_id),
				KEY post_type (post_type)
			) {$charset_collate};"
		);
	}

	/**
	 * Drop cached corpus stats.
	 *
	 * @return void
	 */
	public static function invalidate_stats() {
		delete_option( self::STATS_OPTION );
	}

	/**
	 * Return corpus-wide stats used by BM25 scoring.
	 *
	 * @return array{total_docs:int,avgdl:float}
	 */
	public static function get_stats() {
		$cached = get_option( self::STATS_OPTION, array() );
		if ( is_array( $cached ) && isset( $cached['total_docs'], $cached['avgdl'] ) ) {
			return array(
				'total_docs' => (int) $cached['total_docs'],
				'avgdl'      => (float) $cached['avgdl'],
			);
		}

		global $wpdb;
		$docs_table = self::docs_table();
		$row        = $wpdb->get_row( "SELECT COUNT(*) AS total_docs, AVG(doc_length) AS avgdl FROM {$docs_table}", ARRAY_A );

		$stats = array(
			'total_docs' => ! empty( $row['total_docs'] ) ? (int) $row['total_docs'] : 0,
			'avgdl'      => ! empty( $row['avgdl'] ) ? (float) $row['avgdl'] : 0.0,
		);

		update_option( self::STATS_OPTION, $stats, false );

		return $stats;
	}
}
