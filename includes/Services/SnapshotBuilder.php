<?php
/**
 * Builds and refreshes the assistant knowledge snapshot.
 *
 * @package NewfoldLabs\WP\Module\AIAssistant\Services
 */

namespace NewfoldLabs\WP\Module\AIAssistant\Services;

/**
 * Assembles identity, business profile, and content corpus into one snapshot.
 */
class SnapshotBuilder {

	/**
	 * Build the full snapshot and recompile the brief.
	 *
	 * @return array<string, mixed>
	 */
	public function build_full() {
		$mode    = SiteModeDetector::detect();
		$profile = $this->build_business_profile( $mode );
		$corpus  = $this->build_corpus();
		$ctas    = $this->build_ctas_catalog( $mode, $profile );

		$profile_data               = $profile->to_array();
		$profile_data['ctas_catalog'] = $ctas;

		$brief_data = BriefCompiler::compile( $profile, $ctas );

		$snapshot = array(
			'built_at'      => gmdate( 'c' ),
			'site_mode'     => $mode,
			'quality_tier'  => $brief_data['quality_tier'],
			'identity'      => $this->build_identity(),
			'business'      => $profile_data,
			'corpus'        => $corpus,
			'ctas_catalog'  => $ctas,
			'content_count' => (int) $profile->get( 'content_count' ),
		);

		KnowledgeStore::set_snapshot( $snapshot );
		KnowledgeStore::set_brief( $brief_data );

		return $snapshot;
	}

	/**
	 * Build live identity facts.
	 *
	 * @return array<string, string>
	 */
	public function build_identity() {
		return array(
			'name'        => get_bloginfo( 'name' ),
			'description' => get_bloginfo( 'description' ),
			'url'         => get_bloginfo( 'url' ),
			'language'    => get_bloginfo( 'language' ),
		);
	}

	/**
	 * Build business profile using fallback chains.
	 *
	 * @param string $mode Site mode.
	 * @return BusinessProfile
	 */
	public function build_business_profile( $mode ) {
		list( $description, $description_source ) = $this->resolve_description();

		$type = $this->resolve_type( $mode );
		$contact = $this->resolve_contact();

		$content_count = (int) wp_count_posts( 'page' )->publish + (int) wp_count_posts( 'post' )->publish;

		return new BusinessProfile(
			array(
				'name'               => get_bloginfo( 'name' ),
				'url'                => get_bloginfo( 'url' ),
				'language'           => get_bloginfo( 'language' ),
				'description'        => $description,
				'description_source' => $description_source,
				'type'               => $type,
				'industry'           => $type,
				'content_count'      => $content_count,
				'site_mode'          => $mode,
				'contact'            => $contact,
				'curated_facts'      => (string) get_option( 'nfd_ai_assistant_curated_facts', '' ),
			)
		);
	}

	/**
	 * Walk the description fallback chain.
	 *
	 * @return array{0:string,1:string}
	 */
	private function resolve_description() {
		$admin = trim( (string) get_option( 'nfd_ai_assistant_business_description', '' ) );
		if ( '' !== $admin ) {
			return array( $admin, 'admin' );
		}

		list( $description, $source ) = KnowledgePrefill::resolve_automatic_description();
		if ( '' !== $description ) {
			return array( $description, $source );
		}

		return array(
			'Site details have not been configured. Answer only from the relevant page excerpts below; never invent facts.',
			'sentinel',
		);
	}

	/**
	 * Resolve business type / industry.
	 *
	 * @param string $mode Site mode.
	 * @return string
	 */
	private function resolve_type( $mode ) {
		$onboarding = get_option( 'nfd_module_onboarding_site_info', array() );
		if ( ! empty( $onboarding['site_type'] ) ) {
			return (string) $onboarding['site_type'];
		}

		$from_plugins = BusinessProfileDeriver::infer_type_from_plugins();
		if ( $from_plugins ) {
			return $from_plugins;
		}

		$from_slugs = BusinessProfileDeriver::infer_type_from_slugs();
		if ( $from_slugs ) {
			return $from_slugs;
		}

		return BusinessProfileDeriver::default_type_for_mode( $mode );
	}

	/**
	 * Resolve optional contact block.
	 *
	 * @return array<string, string>
	 */
	private function resolve_contact() {
		$onboarding = get_option( 'nfd_module_onboarding_site_info', array() );
		$contact    = array();

		if ( ! empty( $onboarding['contact']['email'] ) ) {
			$contact['email'] = sanitize_email( $onboarding['contact']['email'] );
		} elseif ( get_option( 'woocommerce_email_from_address' ) ) {
			$contact['email'] = sanitize_email( get_option( 'woocommerce_email_from_address' ) );
		} else {
			$contact['email'] = sanitize_email( get_option( 'admin_email' ) );
		}

		if ( ! empty( $onboarding['contact']['phone'] ) ) {
			$contact['phone'] = sanitize_text_field( $onboarding['contact']['phone'] );
		}

		return $contact;
	}

	/**
	 * Build corpus entries for all published indexable posts.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function build_corpus() {
		$entries = array();
		$types   = KnowledgeStore::indexable_post_types();
		$page    = 1;
		$limit   = (int) apply_filters( 'nfd_ai_assistant_corpus_limit', 1000 );

		do {
			$query = new \WP_Query(
				array(
					'post_type'      => $types,
					'post_status'    => 'publish',
					'posts_per_page' => 100,
					'paged'          => $page,
					'orderby'        => 'modified',
					'order'          => 'DESC',
					'no_found_rows'  => false,
				)
			);

			foreach ( $query->posts as $post ) {
				$entries[] = self::build_corpus_entry( $post );
				if ( count( $entries ) >= $limit ) {
					break 2;
				}
			}

			++$page;
		} while ( $page <= (int) $query->max_num_pages );

		return $entries;
	}

	/**
	 * Build one corpus entry from a post.
	 *
	 * @param \WP_Post $post Post object.
	 * @return array<string, mixed>
	 */
	public static function build_corpus_entry( $post ) {
		$excerpt = has_excerpt( $post ) ? get_the_excerpt( $post ) : wp_trim_words( wp_strip_all_tags( $post->post_content ), 40, '' );
		if ( function_exists( 'excerpt_remove_blocks' ) ) {
			$excerpt = excerpt_remove_blocks( $excerpt );
		}
		$excerpt = wp_strip_all_tags( $excerpt );
		if ( strlen( $excerpt ) > 600 ) {
			$excerpt = substr( $excerpt, 0, 597 ) . '...';
		}

		return array(
			'id'            => (int) $post->ID,
			'title'         => get_the_title( $post ),
			'permalink'     => self::safe_permalink( $post ),
			'excerpt'       => $excerpt,
			'last_modified' => get_post_modified_time( 'c', true, $post ),
			'post_type'     => $post->post_type,
		);
	}

	/**
	 * Resolve a permalink when rewrite rules may not be ready yet.
	 *
	 * @param \WP_Post|int $post Post object or ID.
	 * @return string
	 */
	private static function safe_permalink( $post ) {
		global $wp_rewrite;

		$post = get_post( $post );
		if ( ! $post ) {
			return '';
		}

		if ( null === $wp_rewrite ) {
			if ( 'page' === $post->post_type ) {
				return home_url( '?page_id=' . (int) $post->ID );
			}

			return home_url( '?p=' . (int) $post->ID );
		}

		$permalink = get_permalink( $post );

		return $permalink ? $permalink : home_url( '?p=' . (int) $post->ID );
	}

	/**
	 * Auto-detect CTAs for the site mode.
	 *
	 * @param string           $mode    Site mode.
	 * @param BusinessProfile  $profile Business profile.
	 * @return array<int, array<string, string>>
	 */
	public function build_ctas_catalog( $mode, BusinessProfile $profile ) {
		$admin_ctas = get_option( 'nfd_ai_assistant_ctas', array() );
		$admin_ctas = is_array( $admin_ctas ) ? $admin_ctas : array();

		$detected = array();

		if ( 'personal_blog' === $mode ) {
			$detected = array_merge( $detected, $this->detect_blog_ctas() );
		} else {
			$detected = array_merge( $detected, $this->detect_business_ctas() );
		}

		$detected = $this->filter_hidden_ctas( $detected );
		$merged   = array_merge( $detected, $admin_ctas );
		$merged = $this->normalize_ctas( $merged );

		return array_slice( $merged, 0, 6 );
	}

	/**
	 * Detect CTAs for business sites.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function detect_business_ctas() {
		$ctas = array();

		if ( function_exists( 'wc_get_page_id' ) ) {
			$shop_id = wc_get_page_id( 'shop' );
			if ( $shop_id > 0 ) {
				$ctas[] = array(
					'label' => __( 'Shop', 'wp-module-ai-assistant' ),
					'url'   => self::safe_permalink( $shop_id ),
				);
			}
		}

		$slug_patterns = array( 'contact', 'book', 'booking', 'reservation', 'order', 'menu', 'appointment' );
		$pages         = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'posts_per_page' => 20,
			)
		);

		foreach ( $pages as $page ) {
			foreach ( $slug_patterns as $pattern ) {
				if ( false !== strpos( $page->post_name, $pattern ) ) {
					$ctas[] = array(
						'label' => get_the_title( $page ),
						'url'   => self::safe_permalink( $page ),
					);
					break;
				}
			}
		}

		return $ctas;
	}

	/**
	 * Detect CTAs for personal blogs.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function detect_blog_ctas() {
		$ctas    = array();
		$patterns = array( 'about', 'contact', 'subscribe', 'newsletter' );

		$pages = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'posts_per_page' => 20,
			)
		);

		foreach ( $pages as $page ) {
			foreach ( $patterns as $pattern ) {
				if ( false !== strpos( $page->post_name, $pattern ) ) {
					$ctas[] = array(
						'label' => get_the_title( $page ),
						'url'   => self::safe_permalink( $page ),
					);
					break;
				}
			}
		}

		$latest = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
			)
		);
		if ( ! empty( $latest[0] ) ) {
			$ctas[] = array(
				'label' => __( 'Read latest post', 'wp-module-ai-assistant' ),
				'url'   => self::safe_permalink( $latest[0] ),
			);
		}

		return $ctas;
	}

	/**
	 * Normalize and dedupe CTA entries.
	 *
	 * @param array<int, array<string, string>> $ctas Raw CTAs.
	 * @return array<int, array<string, string>>
	 */
	private function normalize_ctas( array $ctas ) {
		$seen    = array();
		$clean   = array();

		foreach ( $ctas as $cta ) {
			if ( empty( $cta['url'] ) || empty( $cta['label'] ) ) {
				continue;
			}
			$url = esc_url_raw( $cta['url'] );
			if ( isset( $seen[ $url ] ) ) {
				continue;
			}
			$seen[ $url ] = true;
			$clean[]      = array(
				'label' => sanitize_text_field(
					html_entity_decode( (string) $cta['label'], ENT_QUOTES | ENT_HTML5, 'UTF-8' )
				),
				'url'   => $url,
			);
		}

		return $clean;
	}

	/**
	 * Remove auto-detected CTAs hidden by the admin.
	 *
	 * @param array<int, array<string, string>> $ctas CTAs.
	 * @return array<int, array<string, string>>
	 */
	private function filter_hidden_ctas( array $ctas ) {
		$hidden = get_option( 'nfd_ai_assistant_hidden_cta_urls', array() );
		if ( ! is_array( $hidden ) || empty( $hidden ) ) {
			return $ctas;
		}

		$hidden_map = array_fill_keys( $hidden, true );

		return array_values(
			array_filter(
				$ctas,
				function ( $cta ) use ( $hidden_map ) {
					return empty( $hidden_map[ $cta['url'] ] );
				}
			)
		);
	}
}
