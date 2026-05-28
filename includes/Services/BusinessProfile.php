<?php
/**
 * Business profile value object.
 *
 * @package NewfoldLabs\WP\Module\AIAssistant\Services
 */

namespace NewfoldLabs\WP\Module\AIAssistant\Services;

/**
 * Immutable-ish business profile assembled from site sources.
 */
class BusinessProfile {

	/**
	 * Profile data.
	 *
	 * @var array<string, mixed>
	 */
	private $data;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $data Profile fields.
	 */
	public function __construct( array $data = array() ) {
		$this->data = wp_parse_args(
			$data,
			array(
				'name'           => '',
				'url'            => '',
				'language'       => '',
				'description'    => '',
				'description_source' => '',
				'type'           => '',
				'industry'       => '',
				'content_count'  => 0,
				'site_mode'      => 'business',
				'contact'        => array(),
				'curated_facts'  => '',
				'ctas_catalog'   => array(),
			)
		);
	}

	/**
	 * Get a profile field.
	 *
	 * @param string $key Field key.
	 * @return mixed
	 */
	public function get( $key ) {
		return $this->data[ $key ] ?? null;
	}

	/**
	 * Export as array.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array() {
		return $this->data;
	}

	/**
	 * Whether description is the insufficient sentinel.
	 *
	 * @return bool
	 */
	public function has_insufficient_description() {
		$description = (string) $this->get( 'description' );
		return false !== strpos( $description, 'Site details have not been configured' );
	}
}
