# AI-Enhanced Search for `wp-module-ai-assistant`

**Status:** Planning
**Owner:** AB
**Last updated:** 2026-05-27

## 1. Problem Statement

The AI assistant currently sends a flat corpus of post/page excerpts to the LLM on every visitor query. This works for small sites but breaks down at scale:

- **Payload bloat:** A 500-post blog or 5,000-product WooCommerce store can't fit in a prompt.
- **Hosting-provider constraint:** The plugin ships to thousands of Newfold-hosted sites (Crazy Domains, Vodien, Network Solutions). Solutions requiring central infrastructure (vector DBs, embedding services) are operationally impractical.
- **Site-type variety:** Same plugin must work for blogs, portfolios, WooCommerce stores, cafes, restaurants, service businesses — without per-type configuration.
- **Token cost:** Current approach dumps ~99 items per call. Most are irrelevant to the visitor's question.

## 2. Approach: BM25 Search Built Into the Module

We're building a **PHP-native BM25 search engine** as part of `wp-module-ai-assistant`. The AI assistant uses it internally to retrieve only the 5-7 most relevant items per visitor query. The same search is exposed as REST endpoints for use by other modules, WordPress core search, and future MCP tools.

### Why BM25 (over alternatives)

| Approach | Verdict | Reason |
|----------|---------|--------|
| Pure RAG (vector DB) | ❌ Rejected | Requires per-site infra or central service; impractical at hosting-provider scale |
| MySQL FULLTEXT | ❌ Rejected | Version-dependent quality, no field weighting in older versions, stopword/min-word issues |
| **PHP BM25 with custom tables** | ✅ **Chosen** | Zero infra, MySQL-version-agnostic, full control over scoring, proven by Relevanssi |
| Meilisearch sidecar | 🔄 Future | Best quality, but requires Newfold-operated infrastructure — viable as premium tier later |

### Why bundle inside the AI assistant module

- Stronger product story: "AI-enhanced site search" vs "BM25 module"
- AI assistant gets richer context; rest of the plugin/site gets a better search primitive as a side effect
- Single module to install and maintain
- Endpoints allow other modules (`wp-module-ai-page-designer`, `wp-module-mcp`) and themes to consume the same search

## 3. Architecture

### Module Structure

```
wp-module-ai-assistant/
├── src/
│   ├── Search/
│   │   ├── Contracts/
│   │   │   └── SearchProvider.php
│   │   ├── BM25/
│   │   │   ├── Indexer.php
│   │   │   ├── Tokenizer.php
│   │   │   ├── Scorer.php
│   │   │   ├── Provider.php
│   │   │   └── Schema.php
│   │   ├── Synonyms.php
│   │   └── SearchService.php
│   ├── REST/
│   │   ├── SearchController.php
│   │   └── AssistantController.php
│   ├── Assistant/
│   │   └── ContextBuilder.php
│   └── Hooks.php
```

### Key Abstraction: `SearchProvider` Interface

```php
interface SearchProvider {
    public function search(SearchQuery $query): SearchResults;
    public function index(int $post_id): void;
    public function remove(int $post_id): void;
    public function rebuild(): void;
}
```

- `BM25\Provider` is the default implementation.
- Future implementations (`MeilisearchProvider`, `RelevanssiProvider`) plug in via filter without touching consumers.
- `SearchService` is the public-facing API everything else talks to.

## 4. Database Schema

Two custom tables, both InnoDB. **Field frequencies are packed into columns rather than stored as separate rows** — this is a deliberate ~3x row-count reduction (see §11 on shared-hosting footprint).

```sql
-- Inverted index: one row per (term, post_id), per-field frequencies packed in
CREATE TABLE wp_aia_search_terms (
  term VARCHAR(64) NOT NULL,
  post_id BIGINT UNSIGNED NOT NULL,
  tf_title SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  tf_excerpt SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  tf_content SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (term, post_id),
  KEY post_id (post_id)
) ENGINE=InnoDB;

-- Per-document stats for BM25 length normalization
CREATE TABLE wp_aia_search_docs (
  post_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  post_type VARCHAR(20) NOT NULL,
  doc_length SMALLINT UNSIGNED NOT NULL,
  indexed_at DATETIME NOT NULL,
  KEY post_type (post_type)
) ENGINE=InnoDB;
```

Notes:
- Table prefix `wp_aia_` (ai-assistant) keeps these clearly owned by this module and avoids collision with other Newfold modules' tables.
- Storing `tf_title` / `tf_excerpt` / `tf_content` as columns (instead of a `field` ENUM row dimension) cuts the index to roughly one-third the rows. BM25 scoring reads all three at once anyway.
- Corpus-wide stats (total docs, average document length) cached in `wp_options` and invalidated on index changes.

**Multisite:** Out of scope for v1. Single-site only. Revisit if any brand property adopts multisite.

## 5. The BM25 Scoring Formula

For query Q with terms q₁...qₙ against document D:

```
score(D, Q) = Σ IDF(qᵢ) · (f(qᵢ, D) · (k₁ + 1)) / (f(qᵢ, D) + k₁ · (1 - b + b · |D|/avgdl))

IDF(qᵢ) = ln((N - n(qᵢ) + 0.5) / (n(qᵢ) + 0.5) + 1)
```

Parameters (exposed as filters for tuning):
- `k₁` ≈ 1.2-2.0 — term frequency saturation
- `b` ≈ 0.75 — length normalization
- Field weights: title ×3, excerpt ×2, content ×1 (configurable)

## 6. Indexing Lifecycle

### Incremental (steady state)
- `save_post` → re-index changed post
- `before_delete_post` → remove from index
- `transition_post_status` → handle publish/unpublish

### Bulk (activation, manual rebuild)
- WP-Cron event processes 50 posts per run
- Admin notice shows progress: "Indexing: 340/2,103 posts"
- Assistant runs in "limited mode" until indexing completes
- Manual "Rebuild Now" button uses Action Scheduler if available, else chunked AJAX

### Bulk-import protection
- Hook `import_start` / `import_end` (WP Importer, WP All Import, WooCommerce CSV)
- Switch to deferred batch indexing during imports
- Prevents tokenization pass on every single insert

## 7. Query Flow

When the AI assistant receives a visitor message:

1. **Tokenize** visitor's message (same tokenizer used for indexing)
2. **Intent classify** (optional, fast path): navigational, transactional, informational, support
3. **Expand query** via synonym map (e.g., "wifi" → "internet", "gf" → "gluten free")
4. **BM25 search** — fetch candidate posts, score, rank
5. **Apply post-type boost** based on intent (transactional → products, navigational → pages)
6. **Return top 5-7** with full excerpts
7. **`ContextBuilder`** assembles the LLM payload:
   - Static business core (identity, hours, contact) — always included
   - Retrieved excerpts — query-specific
8. **Send to LLM**

## 8. REST API Endpoints

All routes are namespaced under `newfold-ai-assistant/v1` — scoped to the module that owns them, avoiding collision with other Newfold modules or future search endpoints. (The version segment sits on the namespace, versioning the module's API as a whole, which is the standard WordPress pattern.)

| Method | Endpoint | Purpose | Auth |
|--------|----------|---------|------|
| GET | `/wp-json/newfold-ai-assistant/v1/search` | Run a search; returns ranked post IDs + excerpts | Public (rate-limited) |
| POST | `/wp-json/newfold-ai-assistant/v1/search/rebuild` | Trigger full reindex | Admin |
| GET | `/wp-json/newfold-ai-assistant/v1/search/stats` | Index size, last rebuild, document count | Admin |
| GET | `/wp-json/newfold-ai-assistant/v1/search/synonyms` | Read synonym map | Admin |
| POST | `/wp-json/newfold-ai-assistant/v1/search/synonyms` | Update synonym map | Admin |

Query params for search endpoint: `q`, `types[]`, `limit`, `intent`, `boost` (field weight overrides).

Consumers:
- AI assistant (internal)
- `wp-module-ai-page-designer` (related content suggestions)
- `wp-module-mcp` (exposed as MCP tool)
- Theme/plugin developers
- Native WordPress search (via `pre_get_posts` filter, opt-in)

## 9. The "AI-Enhanced" Layer

Pure BM25 is keyword search. The "AI-enhanced" label is earned by these layers on top:

### Query expansion (LLM-assisted)
- LLM call returns answer + expanded search terms
- "dairy-free" → ["oat milk", "almond milk", "soy", "vegan", "lactose free"]
- Expansions cached per query pattern → synonym map self-populates over time
- Search quality improves without manual curation

### Intent classification
- Tag each query: navigational, transactional, informational, support
- Drive post-type weighting at search time
- Regex fast-path for common patterns; LLM fallback for ambiguous
- Cheap, noticeably improves perceived quality

### Auto-synonym discovery
- Track visitor queries that return zero/poor results
- Surface to admin for synonym map curation
- Optionally auto-suggest synonyms from LLM analysis

## 10. Tokenization Strategy

**v1 (ship this):**
- Lowercase
- Split on `\W+`
- Drop English stopwords (small list, ~150 words)
- Minimum length 2
- Strip shortcodes and HTML before tokenizing
- **Cap content indexing at first 500 content-tokens** (title + excerpt always indexed in full) — keeps the index lean and reduces boilerplate noise
  - The cap is applied to **content tokens** (post stopword/short-token removal), not raw tokens — at the same row cost this reaches ~50% deeper into the body than capping raw tokens, because budget isn't spent on stopwords
  - Exposed as a per-post-type filter so it can be tuned later without a code change:
    ```php
    apply_filters( 'newfold_aia_content_token_cap', 500, $post_type );
    ```
  - 500 is a deliberate starting default, not a validated optimum. It comfortably covers short content (products, pages, menus, most FAQs). It under-covers long-form blog posts — testing on a ~2,500-word structured guide showed a 500 content-token cap reaches only ~30% of the body, so answer-bearing tail content (e.g. a closing FAQ) can be present in the post but absent from the index. Revisit per post type once real zero-result query data exists; long-form `post` types are the likely candidates for a higher cap.

**Deferred to v2:**
- Porter stemming (opt-in)
- Phrase matching with adjacency boost
- CJK segmentation
- Currency/emoji handling

The temptation to over-engineer the tokenizer is real. Ship the simple thing; add complexity only when real visitor queries demonstrate need.

## 11. Performance Considerations

### Index size estimates

Row counts below assume the **packed-field schema** (one row per `term, post_id`, not per field) and **capped content indexing** (first 500 content-tokens of body, not full content).

Measured baseline: a ~2,500-word enterprise guide produces ~588 unique terms uncapped, or ~277 with the 500 content-token cap applied. So a content-heavy post costs roughly **275-600 index rows** depending on length and cap. Short content (products, menus, pages) costs far less — often under 100 rows.

| Site profile | Posts | Approx. index rows | Verdict on shared MySQL |
|---|---|---|---|
| Small blog | 50 | ~15K | Trivial |
| Cafe site (current example) | 99 | ~20K | Trivial |
| Medium blog | 500 | ~100K | Fine |
| WooCommerce store | 5,000 | ~1-1.5M | Acceptable, monitor |
| Large content site | 50,000 | ~10M | **Too heavy — graduate to sidecar** |

The earlier ~30M figure assumed the unpacked per-field schema and full-content indexing; both have been changed precisely because a fat index is a bad neighbor on shared MySQL.

### The shared-hosting ceiling (important)

On shared hosting, MySQL is a shared resource across tenants. A multi-million-row index table inflates backups, slows `mysqldump`, pressures the InnoDB buffer pool, and the cost lands on *other* sites on the same box, not just the one being searched. BM25-on-MySQL is therefore explicitly a strategy for **small-to-medium sites**, which are the long tail of Newfold's hosting base.

**Defined ceiling:** above roughly **20,000 indexable posts** (or an index exceeding ~5M rows), the self-hosted index stops being a polite tenant. This is the threshold where a site should be moved to the **Meilisearch sidecar / premium tier** (see §2) — index lives on Newfold-operated infrastructure, off the shared MySQL box entirely. The `SearchProvider` abstraction makes this transparent: large sites get `MeilisearchProvider`, everyone else uses `BM25\Provider`, and no consumer code changes.

This ceiling should be surfaced in admin: when a site crosses the threshold, warn the admin and (if available) prompt the upgrade path rather than silently letting the index bloat.

### Row-count reduction techniques (applied in the schema/indexer)

- **Packed-field columns** instead of a field-dimension row → ~3x fewer rows.
- **Capped content window** — index title + excerpt + first 500 content-tokens of body (filterable per post type via `newfold_aia_content_token_cap`). Most BM25 relevance for short visitor queries lives early; full-body indexing mostly adds low-value hapax terms and boilerplate noise. Trade-off noted in §10: this default under-covers long-form posts and is expected to be tuned upward for blog content once real query data exists.
- **Vocabulary pruning** — drop stopwords, pure numbers, over-length tokens (URLs/hashes/base64 junk), and collapse templated near-duplicate content (e.g. the "Description for Product N" placeholders in the example payload).
- **Published-only** — never index revisions, drafts, or autosaves.

### Query latency
- Typical visitor message: 5-8 meaningful tokens after stopwords
- Each token: one indexed lookup on the `(term, post_id)` PK
- BM25 scoring in PHP on candidate set (usually ≤500 posts)
- Target: <50ms on shared hosting

### Watch out for
- Bulk imports without deferred indexing → import slowdown
- Very long posts skewing avgdl — capped content window also helps here
- Index bloat from revisions — only index published posts

## 12. Implementation Plan

Sequential, each step independently shippable and testable.

### Phase 1: Foundation
- [ ] Schema + migration
- [ ] `Tokenizer` class
- [ ] `Indexer` class
- [ ] `save_post` / `before_delete_post` hooks
- [ ] **Outcome:** Index is built and maintained, but nothing queries it yet

### Phase 2: Search Engine
- [ ] `Scorer` (BM25 math)
- [ ] `BM25\Provider` implementing `SearchProvider`
- [ ] `SearchService` public API
- [ ] Field weighting
- [ ] **Outcome:** Search works via PHP calls

### Phase 3: Bulk Indexing
- [ ] WP-Cron chunked indexer
- [ ] Admin progress UI
- [ ] "Limited mode" flag for assistant during initial index
- [ ] Bulk-import hooks
- [ ] **Outcome:** Works correctly on existing sites with thousands of posts

### Phase 4: REST API
- [ ] `SearchController` with `/search`, `/rebuild`, `/stats`, `/synonyms`
- [ ] Auth + rate limiting
- [ ] **Outcome:** Other modules and themes can consume the search

### Phase 5: AI Assistant Integration
- [ ] `ContextBuilder` refactor — drop the full corpus dump
- [ ] Use `SearchService` to fetch top 5-7 per visitor query
- [ ] Static business core stays in every prompt
- [ ] **Outcome:** The original problem is solved. Token usage drops ~80%, answer quality improves.

### Phase 6: AI-Enhanced Layer
- [ ] Synonym map storage + admin UI
- [ ] LLM-driven query expansion
- [ ] Intent classification (regex + LLM fallback)
- [ ] Post-type boosting by intent
- [ ] **Outcome:** Search is meaningfully "AI-enhanced", not just keyword

### Phase 7: Polish
- [ ] `pre_get_posts` filter to replace WordPress core search (opt-in)
- [ ] Auto-synonym discovery from poor-result queries
- [ ] Admin analytics: top queries, zero-result queries, slow queries

## 13. Decisions to Lock In Before Coding

| Decision | Lean Toward | Why |
|---|---|---|
| Stemming in v1 | No | Adds complexity, hurts multilingual sites; opt-in later |
| Stopword list | Small English list | ~150 words; configurable via filter |
| Phrase matching | v2 | ~20 lines but adds tokenizer complexity |
| Synonyms storage | `wp_options` as JSON | Simple, cacheable, exportable |
| Multisite | Out of scope | Single-site only; revisit when needed |
| Indexed fields | title, excerpt, content | Standard set; meta fields opt-in later |
| Content indexing | Capped at 500 content-tokens, per-post-type filter | Lean default; tunable later without code change |
| Index schema | Packed-field columns | ~3x fewer rows than per-field rows |
| Large-site ceiling | ~20K posts / ~5M rows → sidecar | Above this, BM25-on-shared-MySQL is a bad neighbor |
| Indexed post types | Filterable, default to `post`, `page`, `product` | Site owner can extend |

## 14. Out of Scope (For Now)

- Multisite / network-level indexing
- Semantic/vector search
- Cross-site federated search
- Real-time index updates via WebSockets
- Faceted search UI
- Search analytics dashboard (basic stats only in v1)

## 15. Open Questions

- Should the search endpoint require authentication for the AI assistant's own use, or is rate-limited public access acceptable for anonymous visitors?
- What's the right default for max index size before warning the admin?
- Do we need a "pause indexing" mode for sites doing scheduled bulk content operations?
- How do we handle WooCommerce variations — index parent only, or each variation separately?
- Should custom post types be auto-discovered or require explicit opt-in?

---

*This plan is intentionally living — update as decisions are made and assumptions get validated against real implementation.*