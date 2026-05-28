<?php
/**
 * Ephemeral conversation state in transients.
 *
 * @package NewfoldLabs\WP\Module\AIAssistant\Services
 */

namespace NewfoldLabs\WP\Module\AIAssistant\Services;

/**
 * Stores visitor chat history with sliding TTL.
 */
class ConversationStore {

	const TTL_SECONDS = 28800;

	/**
	 * Load a conversation by ID.
	 *
	 * @param string $conversation_id UUID.
	 * @return array<string, mixed>|null
	 */
	public function get( $conversation_id ) {
		if ( empty( $conversation_id ) ) {
			return null;
		}

		$data = get_transient( $this->key( $conversation_id ) );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Create a new conversation shell.
	 *
	 * @return array<string, mixed>
	 */
	public function create() {
		$brief = KnowledgeStore::get_brief();

		return array(
			'brief_version' => $brief['brief_version'] ?? '',
			'created_at'    => gmdate( 'c' ),
			'last_seen'     => gmdate( 'c' ),
			'messages'      => array(),
			'summary'       => '',
		);
	}

	/**
	 * Persist conversation with sliding TTL.
	 *
	 * @param string               $conversation_id UUID.
	 * @param array<string, mixed> $conversation    Conversation payload.
	 * @return void
	 */
	public function save( $conversation_id, array $conversation ) {
		$conversation['last_seen'] = gmdate( 'c' );
		set_transient( $this->key( $conversation_id ), $conversation, self::TTL_SECONDS );
	}

	/**
	 * Append a turn to the conversation.
	 *
	 * @param string               $conversation_id UUID.
	 * @param array<string, mixed> $conversation    Conversation payload.
	 * @param string               $role            user|assistant.
	 * @param string               $content         Message content.
	 * @return array<string, mixed>
	 */
	public function append_message( $conversation_id, array $conversation, $role, $content ) {
		$conversation['messages'][] = array(
			'role'    => $role,
			'content' => $content,
			'ts'      => time(),
		);

		$this->save( $conversation_id, $conversation );
		return $conversation;
	}

	/**
	 * Sync brief version if snapshot changed mid-conversation.
	 *
	 * @param array<string, mixed> $conversation Conversation payload.
	 * @return array<string, mixed>
	 */
	public function sync_brief_version( array $conversation ) {
		$brief = KnowledgeStore::get_brief();
		if ( ! empty( $brief['brief_version'] ) ) {
			$conversation['brief_version'] = $brief['brief_version'];
		}
		return $conversation;
	}

	/**
	 * Build a compact history block for the prompt.
	 *
	 * @param array<string, mixed> $conversation Conversation payload.
	 * @return string
	 */
	public function format_history( array $conversation ) {
		$lines    = array();
		$messages = $conversation['messages'] ?? array();

		if ( ! empty( $conversation['summary'] ) ) {
			$lines[] = 'Summary: ' . $conversation['summary'];
		}

		$recent = array_slice( $messages, -4 );
		foreach ( $recent as $message ) {
			$label = 'user' === $message['role'] ? 'User' : 'Assistant';
			$lines[] = $label . ': ' . $message['content'];
		}

		return implode( "\n", $lines );
	}

	/**
	 * Transient key helper.
	 *
	 * @param string $conversation_id UUID.
	 * @return string
	 */
	private function key( $conversation_id ) {
		return 'nfd_aia_conv_' . preg_replace( '/[^a-f0-9-]/i', '', $conversation_id );
	}
}
