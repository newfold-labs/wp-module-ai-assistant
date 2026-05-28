# wp-module-ai-assistant

Public-facing AI site assistant for WordPress. Scaffolded in-repo under `vendor/newfold-labs/wp-module-ai-assistant/` until the standalone Composer package is published.

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
