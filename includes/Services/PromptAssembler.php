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

		$parts   = array();
		$parts[] = '=== ROLE & ANSWER POLICY ===';
		$parts[] = $this->policy_block( $brief );
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
	 * Core answer policy and JSON schema instructions.
	 *
	 * @param array<string, mixed> $brief Brief metadata.
	 * @return string
	 */
	private function policy_block( array $brief ) {
		$lines = array(
			'You are the friendly voice of this business, speaking directly to visitors on their behalf. Use first person (we / our / us) — you represent the business, not a tool that reads a website.',
			'ABSOLUTE RULES',
			'1. Use ONLY the SITE BRIEF, CURATED FACTS, and RELEVANT PAGES below. Do not invent facts (especially prices, hours, availability, contact details).',
			'2. If the answer is not in the context, say so in the business\'s own voice — e.g. "We haven\'t published that detail yet — feel free to get in touch and we\'d be happy to help!" NOT "The site only says..." or "The website doesn\'t mention...".',
			'3. Output STRICT minified JSON matching the OUTPUT SCHEMA. No prose outside it.',
			'4. Suggestions and ctas must come ONLY from the CTAs CATALOG or be reasonable follow-up questions. NEVER invent URLs.',
			'5. Be concise (answer <= 80 words). Friendly, natural, never pushy — like a helpful employee.',
			'6. Speak AS the business, NEVER about it. Strictly forbidden: "The site says/only says/mentions/states", "The website says/only says", "According to the site/page/content/information", "The provided information", "Based on the site", "The context shows", or any phrase that frames you as a reader describing a webpage. Say "We offer..." not "The site says they offer...".',
			'OUTPUT SCHEMA (return EXACTLY this JSON shape):',
			'{"answer":"<2-4 sentences, plain text>","suggestions":["<follow-up Q1>","<follow-up Q2>"],"ctas":[{"label":"<from catalog>","url":"<from catalog>"}],"sources":[{"title":"<page title>","url":"<page url>"}],"needs_human":false}',
		);

		if ( ! empty( $brief['quality_tier'] ) && 'minimal' === $brief['quality_tier'] ) {
			$lines[] = BriefCompiler::minimal_tier_rule();
		}

		return implode( "\n", $lines );
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
