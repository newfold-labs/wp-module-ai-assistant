<?php
/**
 * Snapshot persistence and incremental refresh hooks.
 *
 * @package NewfoldLabs\WP\Module\AIAssistant\Services
 */

namespace NewfoldLabs\WP\Module\AIAssistant\Services;

/**
 * Manages the nfd_ai_assistant_snapshot option and related hooks.
 */
class KnowledgeStore {

	const SNAPSHOT_OPTION = 'nfd_ai_assistant_snapshot';
	const BRIEF_OPTION    = 'nfd_ai_assistant_brief';

	/**
	 * Register WordPress hooks for incremental updates.
	 *
	 * @return void
	 */
	public static function register_hooks() {
		add_action( 'save_post', array( __CLASS__, 'on_save_post' ), 20, 2 );
		add_action( 'deleted_post', array( __CLASS__, 'on_deleted_post' ) );
		add_action( 'transition_post_status', array( __CLASS__, 'on_transition_post_status' ), 10, 3 );
		add_action( 'update_option_blogname', array( __CLASS__, 'schedule_rebuild' ) );
		add_action( 'update_option_blogdescription', array( __CLASS__, 'schedule_rebuild' ) );
		add_action( 'update_option_nfd_module_onboarding_site_info', array( __CLASS__, 'schedule_rebuild' ) );
		add_action( 'update_option_nfd-ai-site-gen-refinedsitedescription', array( __CLASS__, 'schedule_rebuild' ) );
		add_action( 'update_option_nfd_ai_assistant_business_description', array( __CLASS__, 'schedule_rebuild' ) );
		add_action( 'update_option_nfd_ai_assistant_curated_facts', array( __CLASS__, 'schedule_rebuild' ) );
		add_action( 'update_option_nfd_ai_assistant_ctas', array( __CLASS__, 'schedule_rebuild' ) );
		add_action( 'update_option_nfd_ai_assistant_hidden_cta_urls', array( __CLASS__, 'schedule_rebuild' ) );
		add_action( 'update_option_nfd_ai_assistant_site_mode_override', array( __CLASS__, 'schedule_rebuild' ) );
		add_action( 'nfd_ai_assistant_rebuild_snapshot', array( __CLASS__, 'run_scheduled_rebuild' ) );

		if ( function_exists( 'as_schedule_recurring_action' ) && ! as_next_scheduled_action( 'nfd_ai_assistant_daily_rebuild' ) ) {
			as_schedule_recurring_action( time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, 'nfd_ai_assistant_daily_rebuild' );
		}
		add_action( 'nfd_ai_assistant_daily_rebuild', array( __CLASS__, 'run_scheduled_rebuild' ) );
		add_action( 'nfd_ai_assistant_daily_rebuild', array( __CLASS__, 'run_transient_gc' ) );
	}

	/**
	 * Get the cached snapshot.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_snapshot() {
		$snapshot = get_option( self::SNAPSHOT_OPTION, array() );
		return is_array( $snapshot ) ? $snapshot : array();
	}

	/**
	 * Resolve the published contact page URL from the CTA catalog, if any.
	 *
	 * @return string
	 */
	public static function get_contact_page_url() {
		$snapshot = self::get_snapshot();
		$ctas     = ! empty( $snapshot['ctas_catalog'] ) ? $snapshot['ctas_catalog'] : array();

		foreach ( $ctas as $cta ) {
			if ( empty( $cta['url'] ) ) {
				continue;
			}

			$label = ! empty( $cta['label'] ) ? (string) $cta['label'] : '';
			$url   = (string) $cta['url'];
			$path  = wp_parse_url( $url, PHP_URL_PATH );
			$path  = is_string( $path ) ? $path : '';

			if (
				false !== stripos( $label, 'contact' ) ||
				false !== stripos( $path, '/contact' ) ||
				false !== stripos( $path, 'contact/' )
			) {
				return esc_url_raw( $url );
			}
		}

		return '';
	}

	/**
	 * Persist snapshot.
	 *
	 * @param array<string, mixed> $snapshot Snapshot payload.
	 * @return bool
	 */
	public static function set_snapshot( array $snapshot ) {
		if ( ! get_option( self::SNAPSHOT_OPTION, false ) ) {
			add_option( self::SNAPSHOT_OPTION, $snapshot, '', false );
			return true;
		}
		return update_option( self::SNAPSHOT_OPTION, $snapshot, false );
	}

	/**
	 * Get compiled brief metadata.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_brief() {
		$brief = get_option( self::BRIEF_OPTION, array() );
		return is_array( $brief ) ? $brief : array();
	}

	/**
	 * Persist compiled brief.
	 *
	 * @param array<string, mixed> $brief Brief payload.
	 * @return bool
	 */
	public static function set_brief( array $brief ) {
		if ( ! get_option( self::BRIEF_OPTION, false ) ) {
			add_option( self::BRIEF_OPTION, $brief, '', false );
			return true;
		}
		return update_option( self::BRIEF_OPTION, $brief, false );
	}

	/**
	 * Current quality tier from snapshot or brief.
	 *
	 * @return string
	 */
	public static function get_quality_tier() {
		$brief = self::get_brief();
		if ( ! empty( $brief['quality_tier'] ) ) {
			return (string) $brief['quality_tier'];
		}
		$snapshot = self::get_snapshot();
		return ! empty( $snapshot['quality_tier'] ) ? (string) $snapshot['quality_tier'] : 'minimal';
	}

	/**
	 * Upsert a single corpus entry on save.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 * @return void
	 */
	public static function on_save_post( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || 'publish' !== $post->post_status ) {
			return;
		}

		if ( ! in_array( $post->post_type, self::indexable_post_types(), true ) ) {
			return;
		}

		$snapshot = self::get_snapshot();
		if ( empty( $snapshot['corpus'] ) || ! is_array( $snapshot['corpus'] ) ) {
			self::schedule_rebuild();
			return;
		}

		$entry = SnapshotBuilder::build_corpus_entry( $post );
		$found = false;
		foreach ( $snapshot['corpus'] as $index => $item ) {
			if ( (int) $item['id'] === (int) $post_id ) {
				$snapshot['corpus'][ $index ] = $entry;
				$found                        = true;
				break;
			}
		}
		if ( ! $found ) {
			$snapshot['corpus'][] = $entry;
		}

		$snapshot['built_at'] = gmdate( 'c' );
		self::set_snapshot( $snapshot );
	}

	/**
	 * Remove corpus entry when a post is deleted.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function on_deleted_post( $post_id ) {
		$snapshot = self::get_snapshot();
		if ( empty( $snapshot['corpus'] ) ) {
			return;
		}

		$snapshot['corpus'] = array_values(
			array_filter(
				$snapshot['corpus'],
				function ( $item ) use ( $post_id ) {
					return (int) $item['id'] !== (int) $post_id;
				}
			)
		);
		self::set_snapshot( $snapshot );
	}

	/**
	 * Rebuild when a post leaves publish state.
	 *
	 * @param string  $new_status New status.
	 * @param string  $old_status Old status.
	 * @param \WP_Post $post       Post object.
	 * @return void
	 */
	public static function on_transition_post_status( $new_status, $old_status, $post ) {
		if ( 'publish' === $old_status && 'publish' !== $new_status ) {
			self::on_deleted_post( $post->ID );
		}
	}

	/**
	 * Queue a deferred rebuild.
	 *
	 * @return void
	 */
	public static function schedule_rebuild() {
		if ( ! wp_next_scheduled( 'nfd_ai_assistant_rebuild_snapshot' ) ) {
			wp_schedule_single_event( time() + 30, 'nfd_ai_assistant_rebuild_snapshot' );
		}
	}

	/**
	 * Run a full snapshot rebuild.
	 *
	 * @return void
	 */
	public static function run_scheduled_rebuild() {
		( new SnapshotBuilder() )->build_full();
	}

	/**
	 * Remove stale conversation transients older than 24 hours.
	 *
	 * @return void
	 */
	public static function run_transient_gc() {
		global $wpdb;

		$like = $wpdb->esc_like( '_transient_nfd_aia_conv_' ) . '%';
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
				$like
			)
		);

		if ( empty( $rows ) ) {
			return;
		}

		$cutoff = time() - DAY_IN_SECONDS;
		foreach ( $rows as $row ) {
			$data = maybe_unserialize( $row->option_value );
			if ( ! is_array( $data ) || empty( $data['last_seen'] ) ) {
				continue;
			}
			$last_seen = strtotime( (string) $data['last_seen'] );
			if ( false !== $last_seen && $last_seen < $cutoff ) {
				delete_option( $row->option_name );
				delete_option( str_replace( '_transient_', '_transient_timeout_', $row->option_name ) );
			}
		}
	}

	/**
	 * Post types indexed in the corpus.
	 *
	 * @return array<int, string>
	 */
	public static function indexable_post_types() {
		$types = array( 'page', 'post' );
		return apply_filters( 'nfd_ai_assistant_indexable_post_types', $types );
	}
}
