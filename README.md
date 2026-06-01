# wp-module-ai-assistant

Public-facing AI site assistant for WordPress — featuring a self-contained BM25 full-text search engine with AI-enhanced query expansion, intent classification, and post-type boosting.

## Infrastructure

This is a **sparse-retrieval RAG** (Retrieval-Augmented Generation) implementation. Rather than requiring vector databases, embedding models, or external search infrastructure, it uses a PHP-native BM25 keyword search engine with custom MySQL tables — the same algorithmic family as Elasticsearch's `standard` similarity. Retrieved results are injected into the LLM prompt as context.
**R**etrieval → BM25 fetches the top 20 relevant pages
**A**ugmented → those pages are injected into the prompt as context
**G**eneration → the LLM answers using that context

BM25-based retrieval + LLM generation is still RAG — it's just the **sparse retrieval** flavour rather than dense. It's RAG. Just lightweight, shared-hosting-friendly RAG.

This design is intentional: it runs on **shared hosting with standard MySQL** (no FULLTEXT dependency, no vector extensions, no sidecars). Sites that outgrow this ceiling (~20K posts) can graduate to a dedicated search provider via the `SearchProvider` abstraction without any consumer code changes.

## Features

### AI Assistant
- Processes visitor questions through a Cloudflare Worker LLM pipeline
- RAG-style retrieval: fetches only the top-20 relevant pages per query (not the full corpus)
- Natural, conversational responses tuned per site brief
- Simultaneous streaming to the frontend via Server-Sent Events

### BM25 Search Engine

A **PHP-native BM25 search engine** built directly into the module with custom MySQL tables. No external services, vector databases, or infrastructure required.

- **Zero external infra** — runs on shared hosting with standard MySQL (no FULLTEXT dependency)
- **Custom BM25 scoring** with configurable k₁, b, and per-field weights (title ×3, excerpt ×2, content ×1)
- **Boundary-aware tokenizer** — handles Gutenberg-rendered fused content by splitting on camelCase, PascalCase, and letter-number transitions
- **Content capping** — configurable per post type (default: 500 content tokens for `post`, 2,000 for `page`)
- **Incremental indexing** via `save_post` / `delete_post` hooks + WP-Cron bulk rebuild
- **Import protection** — defers indexing during WP Importer/WooCommerce CSV imports
- **REST API** — public search endpoint, admin rebuild/stats endpoints

### AI-Enhanced Search

On top of pure BM25, three AI layers improve result quality:

- **Synonym expansion** — LLM-generated synonym map (e.g. "wifi" → "internet"); auto-populates on index rebuild via `SynonymSuggestor::from_llm()`
- **Intent classification** — Worker-based LLM classifies each query as navigational, transactional, informational, or support
- **Post-type boosting** — scores boosted per intent (pages ranked higher for navigational queries, posts for informational, etc.)

### Excerpt Targeting
- **Last-occurrence heuristic** — finds the LAST match of any query term in the full content and builds the excerpt 500 chars before it; naturally captures footer content (hours, contact, location) without hardcoded keywords

## Capability gating

Production access requires **both** Hiive `SiteCapabilities` flags: **`canAccessAI`** and **`hasAIAssistant`** (`SiteCapabilities::get( 'canAccessAI' ) && SiteCapabilities::get( 'hasAIAssistant' )`). The `nfd_ai_assistant_bypass_capability` filter is for local development only.

## Local development

For local testing without Hiive flags:

```php
add_filter( 'nfd_ai_assistant_bypass_capability', '__return_true' );
```

The Worker identifier `ai-site-assistant` must be allowlisted on `NFD_AI_SERVICE_BASE` before live AI responses work.

## Build

The v1 public widget ships as committed vanilla assets in `build/widget.js` and `build/widget.css`.

## Key filters

| Filter | Purpose | Default |
|--------|---------|--------|
| `newfold_aia_bm25_k1` | BM25 term frequency saturation | 1.5 |
| `newfold_aia_bm25_b` | BM25 length normalization | 0.75 |
| `newfold_aia_bm25_field_weights` | Per-field TF weights | title:3, excerpt:2, content:1 |
| `newfold_aia_bm25_intent_boosts` | Intent-based post-type boost map | varies by intent |
| `newfold_aia_content_token_cap` | Max content tokens per post type | post:500, page:2000 |
| `nfd_ai_assistant_indexable_post_types` | Post types included in search index | post, page |
| `nfd_ai_assistant_bypass_capability` | Bypass Hiive capability check, for testing purposes ONLY | false |
