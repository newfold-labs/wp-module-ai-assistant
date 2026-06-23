<?php
/**
 * Assembles the flat prompt sent to the Worker.
 *
 * @package NewfoldLabs\WP\Module\AIAssistant\Services
 */

namespace NewfoldLabs\WP\Module\AIAssistant\Services;

/**
 * Stable head + volatile tail prompt builder.
 */
class PromptAssembler {

	/**
	 * Build the full flat prompt for one turn.
	 *
	 * @param string               $question     Current question.
	 * @param array<int, array<string,string>> $retrieved Retrieved pages.
	 * @param array<string, mixed> $conversation Conversation state.
	 * @return string
	 */
	public function build( $question, array $retrieved, array $conversation ) {
		$brief    = KnowledgeStore::get_brief();
		$snapshot = KnowledgeStore::get_snapshot();
		$business = ! empty( $snapshot['business'] ) ? $snapshot['business'] : array();
		$ctas     = ! empty( $snapshot['ctas_catalog'] ) ? $snapshot['ctas_catalog'] : array();
		$curated  = ! empty( $business['curated_facts'] ) ? $business['curated_facts'] : '';

		// The static role, answer policy, and OUTPUT SCHEMA now live in the
		// worker-side prompt (resolved by its prompt id), so they are no longer
		// sent on every request — only per-site and per-turn dynamic content is.
		$parts = array();

		// The minimal-tier caution depends on this site's quality tier, so it
		// stays client-side: the shared worker prompt cannot know the tier.
		if ( ! empty( $brief['quality_tier'] ) && 'minimal' === $brief['quality_tier'] ) {
			$parts[] = '=== ANSWER MODE ===';
			$parts[] = BriefCompiler::minimal_tier_rule();
		}

		$parts[] = '=== SITE BRIEF (v: ' . ( $brief['brief_version'] ?? 'unknown' ) . ') ===';
		$parts[] = $brief['text'] ?? '';
		$parts[] = '=== CURATED FACTS ===';
		$parts[] = $curated ? $curated : '(none)';
		$parts[] = '=== CTAs CATALOG (the only URLs you may suggest) ===';
		$parts[] = $this->format_ctas( $ctas );
		$parts[] = '=== RELEVANT PAGES ===';
		$parts[] = $this->format_pages( $retrieved );
		$parts[] = '=== CONVERSATION SO FAR ===';
		$parts[] = ( new ConversationStore() )->format_history( $conversation ) ?: '(none)';
		$parts[] = '=== CURRENT QUESTION ===';
		$parts[] = $question;

		return implode( "\n\n", array_filter( $parts, 'strlen' ) );
	}

	/**
	 * Format CTA catalog lines.
	 *
	 * @param array<int, array<string,string>> $ctas CTA catalog.
	 * @return string
	 */
	private function format_ctas( array $ctas ) {
		if ( empty( $ctas ) ) {
			return '(empty — omit ctas[] from response when appropriate)';
		}

		$lines = array();
		foreach ( $ctas as $cta ) {
			$lines[] = '- ' . $cta['label'] . ' -> ' . $cta['url'];
		}
		return implode( "\n", $lines );
	}

	/**
	 * Format retrieved page excerpts.
	 *
	 * @param array<int, array<string,string>> $pages Retrieved pages.
	 * @return string
	 */
	private function format_pages( array $pages ) {
		if ( empty( $pages ) ) {
			return '(none)';
		}

		$lines = array();
		foreach ( $pages as $page ) {
			$lines[] = '- [' . $page['title'] . '] ' . $page['url'] . ' — ' . $page['excerpt'];
		}
		return implode( "\n", $lines );
	}
}
