<!--
AI-Facing Project Guide (Renote)
Purpose: Enable an automated AI agent to make SMALL & SAFE changes without rereading the whole repo. For anything structurally large (schema changes, major refactors) READ README.md fully.
Keep answers/action biased toward: correctness > latency > brevity. Avoid speculative abstraction.
-->

# Renote: AI Assistant Cheat Sheet

Core Goal: Minimalist note cards app. Priorities: data integrity, low latency, tiny code surface, predictable behavior.

## 1. High-Level Architecture
Frontend: `index.php` + `js/{store,app}.js` (vanilla, localStorage cache, debounced saves ~450ms).  
Backend API: `src/Api/index.php` (single action switch) + helpers in `src/Support/*`.  
Config: Environment variables loaded in `config.php` mapped to stable constants (NEVER hard‑code secrets).  
Persistence: Redis = hot authoritative working set. MariaDB = durability (write‑behind by stream worker in `bin/flush.php` when `APP_WRITE_BEHIND=true`).

## 2. Data Model
Redis hash `card:<id>` fields: `name` (optional), `text` (canonical body), legacy mirror `txt`, `order` (int), `updated_at` (int epoch).  
Sorted set `cards:index` scores = `order` for ordering.  
Stream `cards:stream` for write-behind (fields include id/name/text/order/updated_at or deletion marker).  
MariaDB table `cards(id, name, txt, order, updated_at)` mirrors Redis hash (text stored as `txt`).  
Client Card Object shape (API/state): `{ id, name, text, order, updated_at }`.

## 3. Runtime Flow (Save Path)
User types → debounce → `save_card` → API validates → update Redis hash + zset, increments metrics, sets `cards:updated_at`, optionally appends stream entry → client sets new `updated_at` from response.
Delete: API removes hash + zset only (soft); worker later purges DB on absence OR manual restore uses DB row.

## 4. API Actions (src/Api/index.php)
All responses JSON: success `{ ok:true, ... }`; failure `{ ok:false, error }`. Invalid JSON => `invalid_json` via `fail()`.
Actions: `state`, `save_card`, `bulk_save`, `delete_card`, `history`, `history_restore` (debug), `history_purge` (debug), `flush_once` (debug), `trim_stream` (debug), `health`, `metrics`.
Additions: Keep switch readable; slot new action alphabetically when reasonable; use helper functions (prefer small pure functions, early returns).

## 5. Validation & Limits
ID formats: hex 16–64 chars OR UUID v4; enforce UUID only if `APP_REQUIRE_UUID=true`.  
Length: `card_validate_id_and_text` enforces `APP_CARD_MAX_LEN` (default 262144).  
Rate limiting: If `APP_RATE_LIMIT_MAX` set, `rl_check()` (currently available) can be inserted in mutating endpoints (lightweight, soft‑fail if Redis down).  
Malformed JSON: must raise `invalid_json` (handled in `Renote\Support\json_input`).

## 6. Worker (bin/flush.php)
Consumes stream (batched `XRANGE` / optional `XREAD` blocking), coalesces by card id (`$FLUSH_QUEUE`).  
On commit: Upsert existing Redis-backed cards; delete DB row if Redis hash missing or prunable (`APP_PRUNE_EMPTY` + length < `APP_EMPTY_MINLEN`).  
Maintains `REDIS_STREAM_LAST` & `cards:last_flush_ts`; trims stream approximately (`XTRIM ~`).  
Stats keys: `metrics:saves`, `metrics:deletes` (simple counters incremented during Redis mutations, not in DB worker).  
Do NOT add per-event DB writes; batch inside transaction.

## 7. Configuration Patterns
All tunables live in environment -> constants. When adding a new knob:  
1. Add env name (UPPER_SNAKE) fallback in `config.php`.  
2. Reference the constant (NOT getenv inline) elsewhere.  
3. Document briefly in README & `.env.example` (and here only if essential for small changes).

Key Groups:  
Redis: `REDIS_*`  
DB: `DB_*`  
Worker: `APP_WRITE_BEHIND`, `APP_WRITE_BEHIND_MODE`, `APP_STREAM_MAXLEN`, `WORKER_*`  
Health Thresholds: `WORKER_MIN_OK_LAG`, `WORKER_MIN_DEGRADED_LAG`, `BATCH_FLUSH_EXPECTED_INTERVAL`  
Validation: `APP_CARD_MAX_LEN`, `APP_REQUIRE_UUID`, `PRUNE_EMPTY`, `EMPTY_MINLEN`  
Rate Limit: `RATE_LIMIT_WINDOW`, `RATE_LIMIT_MAX`  
Debug: `APP_DEBUG`

## 8. Coding Style & Conventions
Language: PHP 8+ (see composer platform config).  
Style: PSR-12 (use php-cs-fixer / phpcs; run before committing).  
`declare(strict_types=1);` for NEW files when safe (existing legacy globals kept lenient).  
Prefer: small pure functions; pipeline Redis calls for sets of keys; early returns; explicit casts.  
No frameworks or heavy deps without explicit instruction.  
Keep global helper functions in `Support/Bootstrap.php` until a justified refactor—avoid premature abstraction.  
When adding repository methods, keep `CardRepository` thin (wrapper only); first implement global then wrap.

## 9. Testing
Location: `tests/*.php` (PHPUnit).  
Add tests for: validation (invalid IDs, length), state shape changes, metrics, new endpoints.  
If a feature depends on Redis, gracefully skip when Redis unavailable (see existing tests).  
Minimal new test template: ensure side effects in Redis and response shape.

## 10. Performance Notes
Keep save debounce ≥350ms to reduce network thrash while feeling instant.  
Batch Redis operations (pipeline) for loops.  
Stream trimming is approximate; adjust `APP_STREAM_MAXLEN` not per-call micro-optimizations.  
Avoid per-keystroke DB writes—respect write-behind path unless explicitly toggled off.

## 11. Security & Safety
Output embedding: always use `safe_json_for_script` for server->DOM data injection.  
Never echo unsanitized user text as HTML—frontend uses `textContent`.  
Rate limiting optional; do not make features hard-fail if Redis errors (fail open for RL).  
Preserve CSP (nonce pattern) if modifying script tags in `index.php`.  
Do not introduce HTML rendering of notes (XSS risk) unless adding a sanitizer (then document here + README).

## 12. Making Small Changes (Examples)
Add a new field stored with each card:  
1. Confirm DB schema supports it (if not, this is NOT a small change → read README & migrate).  
2. Add to Redis hash writes (`redis_upsert_card` & hydration in `load_state`).  
3. Include in API responses (`save_card`, `bulk_save`, `state`).  
4. Update client state handling & rendering.  
5. Add/adjust validation if needed.  
6. Add test ensuring round-trip.

Add new API action:  
1. Add case in switch (alphabetical if sensible).  
2. Parse input via `json_input()`.  
3. Validate early & respond with `fail()` on error.  
4. Return structured data via `ok()`.  
5. Add test.  
6. Document briefly in README API table (larger context) if non-trivial.

## 13. When to Escalate (Read README)
If change impacts: DB schema, write-behind algorithm, concurrency semantics, security model, deployment/systemd, or introduces rich text / search / multi-user → read full README for context & constraints.

## 14. Anti-Patterns (Avoid)
- Adding ORM / framework.  
- Expanding global state without necessity.  
- Synchronous DB writes on every keystroke when write-behind active.  
- Silent catch of broad exceptions without logging (except where explicitly documented as soft-fail e.g. rate limit).  
- Mixing HTML in note text logic.

## 15. Quick References
Primary Helpers: `load_state()`, `redis_upsert_card()`, `delete_card_redis_only()`, `metrics_snapshot()`, `db_orphans()`, `card_validate_id_and_text()`.  
Redis Keys: `cards:index`, `cards:updated_at`, `cards:stream`, `cards:stream:lastid`, `cards:last_flush_ts`.  
Metrics counters: `metrics:saves`, `metrics:deletes` (integers).  
Worker Position: `REDIS_STREAM_LAST`.  
Health: lag classification vs `APP_WORKER_MIN_OK_LAG`, `APP_WORKER_MIN_DEGRADED_LAG`.

## 16. Commit Discipline
Single-responsibility patches. Update tests & `.env.example` + README for any new config or API shape. Keep this file concise—only add new lines if essential for small autonomous edits.

End of AI guide.
