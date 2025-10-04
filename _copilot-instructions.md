# AI Assistant Guide (Renote)

Goal: Small, focused note card app. Priorities: data integrity, minimal latency, simple code. Avoid speculative complexity.

## Architecture Snapshot
Frontend: `index.php` + `app.js` (vanilla JS, localStorage cache + periodic reconciliation).  
Backend: `api.php` (JSON actions), `bootstrap.php` (clients + helpers), `config.php` (env→constants), `flush.php` (write‑behind worker).  
Data: Redis (hash `card:<id>`, zset `cards:index`, stream `cards:stream`) + MariaDB `cards` table for durability.

## Configuration
All runtime config via `.env` → constants in `config.php` (see `.env.example`). Add new tunables as ENV first; keep constant names stable for legacy code. Never hard‑code secrets.

Key ENV groups: Redis (REDIS_*), DB (DB_*), Worker (WORKER_*/BATCH_FLUSH_EXPECTED_INTERVAL), Limits & Validation (APP_CARD_MAX_LEN etc.).

## Card Object Fields
`{ id, name, text, order, updated_at }`  
`name` optional; `text` is canonical content. `updated_at` from server used for conflict & deletion reconciliation.

## API Actions
state, save_card, bulk_save, delete_card, history, history_restore, history_purge, flush_once, trim_stream, health, metrics.  
All responses: `{ ok: true, ... }` or `{ ok:false, error }`.  
Rejects malformed JSON (`invalid_json`). Enforce ID & length validation.

## Worker Behavior (flush.php)
Batches stream entries; coalesces by id; upserts or deletes DB row if Redis hash missing or prunable (empty text & pruning enabled). Updates lag metadata keys and trims stream approximately.

## Sync & Deletion Semantics
Client reconciliation merges remote cards; updates local on newer `updated_at`; prunes cards missing remotely if previously synced (grace ~5s). Saves are debounced (~450ms) and update `updated_at` after server ACK.

## Coding Style / Practices
- PHP: PSR-12, explicit `declare(strict_types=1);` on new files where safe.  
- Use small pure functions; keep global helpers in namespaced support files (e.g. `Renote\Support\Http`).  
- Prefer early returns & guard clauses.  
- Keep API action switch cohesive; add new actions alphabetically if reasonable.  
- Do not introduce frameworks unless explicit request.

## Adding Features Checklist
1. Update data model (Redis hash + DB schema) if needed.  
2. Extend API payload & validation.  
3. Adjust `app.js` state shape & rendering.  
4. Add tests (validation, persistence).  
5. Update `.env.example` & README sections.  
6. Document changes in CHANGELOG.

## Safety & Security
- All dynamic script data via JSON script tag sanitized by `safe_json_for_script`.  
- Rate limiting relies on Redis; handle failure gracefully (do not block).  
- Keep text output escaped by using `textContent` on client.  
- Avoid executing or rendering HTML inside notes unless sanitizer added.

## Performance Notes
- Debounce threshold: keep ≥350ms to balance network vs perceived latency.  
- Avoid N+1 Redis calls (use pipeline).  
- Stream trimming approximate for speed; adjust `APP_STREAM_MAXLEN` for retention.

## When Unsure
Prefer minimal incremental change with doc comments. Surface tradeoffs instead of large refactors. Ask only when blocked by missing domain context.

## Do NOT
- Add heavy dependencies for trivial tasks.  
- Break constant names consumed elsewhere without mapping.  
- Store secrets in repo.

## Quick References
Redis Keys: `cards:index`, `cards:updated_at`, `cards:stream`, `cards:stream:lastid`, `cards:last_flush_ts`  
Primary Helpers: `load_state()`, `redis_upsert_card()`, `delete_card_redis_only()`, `db_orphans()`, `metrics_snapshot()`

End of guide.
