<?php
/**
 * Intent classifier via Worker LLM.
 *
 * @package NewfoldLabs\WP\Module\AIAssistant\Search
 */

namespace NewfoldLabs\WP\Module\AIAssistant\Search;

use NewfoldLabs\WP\Module\AIAssistant\Services\AiAssistantWorker;

/**
 * Classifies visitor queries into navigational, transactional,
 * informational, or support using the AI Worker.
 */
class IntentClassifier {

	const INTENT_NAVIGATIONAL  = 'navigational';
	const INTENT_TRANSACTIONAL = 'transactional';
	const INTENT_INFORMATIONAL = 'informational';
	const INTENT_SUPPORT       = 'support';

	/**
	 * AI Worker instance.
	 *
	 * @var AiAssistantWorker
	 */
	private $worker;

	/**
	 * In-memory query→intent cache for same-page dedup.
	 *
	 * @var array<string, string>
	 */
	private static $intent_cache = array();

	/**
	 * Constructor.
	 *
	 * @param AiAssistantWorker|null $worker Optional worker instance.
	 */
	public function __construct( ?AiAssistantWorker $worker = null ) {
		$this->worker = $worker ?: new AiAssistantWorker();
	}

	/**
	 * Classify a visitor query into one of the four intents.
	 *
	 * @param string $query Raw visitor query.
	 * @return string Intent constant value or empty string on error.
	 */
	public function classify( $query ) {
		$query = trim( (string) $query );
		if ( '' === $query ) {
			return '';
		}

		// Check in-memory cache.
		$cache_key = strtolower( $query );
		if ( isset( self::$intent_cache[ $cache_key ] ) ) {
			return self::$intent_cache[ $cache_key ];
		}

		$prompt  = 'You are an intent classifier for a business website assistant. ';
		$prompt .= 'Classify the visitor query into exactly one intent: ';
		$prompt .= 'navigational (finding the business, directions, hours, contact), ';
		$prompt .= 'transactional (buying, booking, pricing, reservations), ';
		$prompt .= 'informational (learning about products, services, how things work), ';
		$prompt .= 'or support (help with an issue, refund, problem). ';
		$prompt .= 'Query: "' . $query . '" ';
		$prompt .= 'Respond with only the intent word.';

		$response = $this->worker->ask( $prompt );

		if ( is_wp_error( $response ) ) {
			// Fall back to informational on error.
			self::$intent_cache[ $cache_key ] = self::INTENT_INFORMATIONAL;
			return self::INTENT_INFORMATIONAL;
		}

		$intent = strtolower( trim( (string) $response ) );

		// Normalise to one of the four constants.
		$valid = array(
			self::INTENT_NAVIGATIONAL,
			self::INTENT_TRANSACTIONAL,
			self::INTENT_INFORMATIONAL,
			self::INTENT_SUPPORT,
		);

		if ( ! in_array( $intent, $valid, true ) ) {
			// Fuzzy-match partials (e.g. "navigation" → "navigational").
			foreach ( $valid as $candidate ) {
				if ( false !== strpos( $intent, $candidate ) || false !== strpos( $candidate, $intent ) ) {
					self::$intent_cache[ $cache_key ] = $candidate;
					return $candidate;
				}
			}
			// Still no match — fall back.
			$intent = self::INTENT_INFORMATIONAL;
		}

		self::$intent_cache[ $cache_key ] = $intent;
		return $intent;
	}
}
