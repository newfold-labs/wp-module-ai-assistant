# Intent Classification + Post-type Boosting

**Status:** Implemented
**Date:** 2026-05-29

## Approach: LLM-only via Worker

- Piggybacks on the existing Worker `ask` endpoint with a compact intent-classification prompt
- Returns `{"intent": "navigational|transactional|informational|support"}`
- In-memory deduplication cache: same query within the same page load skips a second LLM call

## Files Changed

### `includes/Search/SearchQuery.php`
- Added `$intent` property with 4 class constants (`INTENT_NAVIGATIONAL`, `INTENT_TRANSACTIONAL`, `INTENT_INFORMATIONAL`, `INTENT_SUPPORT`)
- Optional 4th constructor parameter for explicit intent
- `get_intent()` getter and `set_intent()` fluent setter

### `includes/Search/IntentClassifier.php` (new)
- Uses `AiAssistantWorker::ask()` with a compact prompt to classify queries
- Four intent types: navigational, transactional, informational, support
- Falls back to `informational` on Worker errors
- In-memory cache (`array<string, string>`) deduplicates same-query calls within a page load

### `includes/Search/BM25/Provider.php`
- Captures `post_type` from the docs table during the scoring loop
- Auto-classifies intent via `IntentClassifier` when `SearchQuery` has no explicit intent
- Applies post-type boost multipliers to scores before `arsort()`:

| Intent | post_type | Boost |
|--------|-----------|-------|
| navigational | page | 1.3 |
| transactional | page | 1.0 |
| informational | post | 1.3 |
| informational | page | 1.0 |
| support | any | 1.0 |

- Boost map is filterable via `newfold_aia_bm25_intent_boosts` for WooCommerce and other post types

## Outcome

BM25 search now ranks pages higher for navigational queries ("find us", "directions"), posts higher for informational queries ("how is coffee roasted"), and maintains a flat boost for transactional/support queries. No manual configuration needed.
