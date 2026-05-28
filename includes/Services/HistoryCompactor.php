<?php
/**
 * Compacts long conversation histories via a summarization Worker call.
 *
 * @package NewfoldLabs\WP\Module\AIAssistant\Services
 */

namespace NewfoldLabs\WP\Module\AIAssistant\Services;

/**
 * Summarizes older turns when a conversation exceeds the turn threshold.
 */
class HistoryCompactor {

	const TURN_THRESHOLD = 12;
	const KEEP_RECENT    = 4;

	/**
	 * Compact conversation history when over the turn threshold.
	 *
	 * @param string               $conversation_id Conversation UUID.
	 * @param array<string, mixed> $conversation    Conversation payload.
	 * @return array<string, mixed>
	 */
	public function maybe_compact( $conversation_id, array $conversation ) {
		$messages = $conversation['messages'] ?? array();
		if ( count( $messages ) <= self::TURN_THRESHOLD ) {
			return $conversation;
		}

		$older   = array_slice( $messages, 0, - self::KEEP_RECENT );
		$summary = $this->summarize( $older );

		if ( is_wp_error( $summary ) || '' === trim( $summary ) ) {
			return $conversation;
		}

		$conversation['summary']  = mb_substr( trim( $summary ), 0, 300 );
		$conversation['messages'] = array_slice( $messages, - self::KEEP_RECENT );

		( new ConversationStore() )->save( $conversation_id, $conversation );

		return $conversation;
	}

	/**
	 * Build a summarization prompt and call the Worker.
	 *
	 * @param array<int, array<string, mixed>> $messages Older message turns.
	 * @return string|\WP_Error
	 */
	private function summarize( array $messages ) {
		$lines = array();
		foreach ( $messages as $message ) {
			$label   = 'user' === ( $message['role'] ?? '' ) ? 'User' : 'Assistant';
			$lines[] = $label . ': ' . (string) ( $message['content'] ?? '' );
		}

		$prompt = "Summarize the following visitor chat with a website assistant in at most 300 words. "
			. "Keep key questions, answers, and any stated preferences.\n\n"
			. implode( "\n", $lines );

		return ( new AiAssistantWorker() )->ask( $prompt );
	}
}
