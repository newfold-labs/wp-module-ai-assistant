<?php
/**
 * Resolves admin form prefill values from stored options and fallbacks.
 *
 * @package NewfoldLabs\WP\Module\AIAssistant\Services
 */

namespace NewfoldLabs\WP\Module\AIAssistant\Services;

/**
 * Supplies display values for the Knowledge admin page.
 */
class KnowledgePrefill {

	/**
	 * Business description for the admin textarea.
	 *
	 * Uses the saved option first, then the same automatic sources as the snapshot.
	 *
	 * @return string
	 */
	public static function get_business_description() {
		$admin = trim( (string) get_option( 'nfd_ai_assistant_business_description', '' ) );
		if ( '' !== $admin ) {
			return $admin;
		}

		list( $description ) = self::resolve_automatic_description();
		return $description;
	}

	/**
	 * Curated facts for the admin textarea.
	 *
	 * Uses the saved option first, then contact details from onboarding / WooCommerce.
	 *
	 * @return string
	 */
	public static function get_curated_facts() {
		$stored = trim( (string) get_option( 'nfd_ai_assistant_curated_facts', '' ) );
		if ( '' !== $stored ) {
			return $stored;
		}

		return self::derive_curated_facts_from_contact();
	}

	/**
	 * Walk automatic description sources used by the snapshot builder.
	 *
	 * @return array{0:string,1:string}
	 */
	public static function resolve_automatic_description() {
		$sources = array(
			'sitegen'     => get_option( 'nfd-ai-site-gen-refinedsitedescription', '' ),
			'onboarding'  => self::onboarding_description(),
			'woocommerce' => get_option( 'woocommerce_store_description', '' ),
			'homepage'    => HomepageExtractor::extract(),
			'derived'     => BusinessProfileDeriver::derive_description(),
		);

		foreach ( $sources as $source => $value ) {
			$value = trim( (string) $value );
			if ( '' !== $value ) {
				return array( $value, $source );
			}
		}

		return array( '', '' );
	}

	/**
	 * Onboarding description helper.
	 *
	 * @return string
	 */
	private static function onboarding_description() {
		$data = get_option( 'nfd_module_onboarding_site_info', array() );
		return ! empty( $data['description'] ) ? (string) $data['description'] : '';
	}

	/**
	 * Build curated facts from known contact sources.
	 *
	 * @return string
	 */
	private static function derive_curated_facts_from_contact() {
		$onboarding = get_option( 'nfd_module_onboarding_site_info', array() );
		$onboarding = is_array( $onboarding ) ? $onboarding : array();
		$contact    = ! empty( $onboarding['contact'] ) && is_array( $onboarding['contact'] )
			? $onboarding['contact']
			: array();

		$lines = array();

		$phone = ! empty( $contact['phone'] ) ? sanitize_text_field( (string) $contact['phone'] ) : '';
		if ( '' === $phone && get_option( 'woocommerce_store_phone' ) ) {
			$phone = sanitize_text_field( (string) get_option( 'woocommerce_store_phone' ) );
		}
		if ( '' !== $phone ) {
			$lines[] = sprintf(
				/* translators: %s: phone number */
				__( 'Phone: %s', 'wp-module-ai-assistant' ),
				$phone
			);
		}

		$email = ! empty( $contact['email'] ) ? sanitize_email( (string) $contact['email'] ) : '';
		if ( '' === $email && get_option( 'woocommerce_email_from_address' ) ) {
			$email = sanitize_email( (string) get_option( 'woocommerce_email_from_address' ) );
		}
		if ( '' === $email ) {
			$email = sanitize_email( get_option( 'admin_email' ) );
		}
		if ( '' !== $email ) {
			$lines[] = sprintf(
				/* translators: %s: email address */
				__( 'Email: %s', 'wp-module-ai-assistant' ),
				$email
			);
		}

		$address = self::resolve_address( $contact );
		if ( '' !== $address ) {
			$lines[] = sprintf(
				/* translators: %s: physical address */
				__( 'Address: %s', 'wp-module-ai-assistant' ),
				$address
			);
		}

		$hours = ! empty( $contact['hours'] ) ? sanitize_text_field( (string) $contact['hours'] ) : '';
		if ( '' !== $hours ) {
			$lines[] = sprintf(
				/* translators: %s: business hours */
				__( 'Hours: %s', 'wp-module-ai-assistant' ),
				$hours
			);
		}

		return implode( "\n", $lines );
	}

	/**
	 * Resolve a mailing address from onboarding or WooCommerce store settings.
	 *
	 * @param array<string, mixed> $contact Onboarding contact block.
	 * @return string
	 */
	private static function resolve_address( array $contact ) {
		if ( ! empty( $contact['address'] ) ) {
			return sanitize_text_field( (string) $contact['address'] );
		}

		$parts = array_filter(
			array(
				get_option( 'woocommerce_store_address', '' ),
				get_option( 'woocommerce_store_address_2', '' ),
				get_option( 'woocommerce_store_city', '' ),
				get_option( 'woocommerce_store_postcode', '' ),
				get_option( 'woocommerce_store_country', '' ),
			),
			static function ( $part ) {
				return '' !== trim( (string) $part );
			}
		);

		if ( empty( $parts ) ) {
			return '';
		}

		return sanitize_text_field( implode( ', ', array_map( 'strval', $parts ) ) );
	}
}
