<!--
AI-Facing Project Guide (Renote)
Purpose: Enable an automated AI agent to make SMALL & SAFE changes. This is a constraints-first document. For anything structurally large (e.g., schema changes, major refactors), read the full README.md.
-->

# Renote: AI Agent Cheat Sheet

## 1. The Laws of the System (Invariants)

- **Redis is the authoritative hot state.** All reads and writes target Redis first.
- **MariaDB is write-behind durability only.** No direct DB writes on request path when `APP_WRITE_BEHIND=true`.
- **Ordering integrity:** Any card create/update/move must keep ZSET membership consistent: remove from old category ZSET, add to correct one; scores reflect `order`.
- **API actions are stateless + early-return.** Validate → mutate Redis → respond `{ok:true}` or `{ok:false,error}`. No hidden side effects.
- **Stream entries must be complete.** XADD fields must allow deterministic DB upsert/delete: include `id`, `text` (or deletion flag), `category_id`, `order`, `updated_at`.
- **Frontend consumes `textContent`.** Never inject raw HTML for note bodies without an approved sanitizer.

## 2. System Overview

- **Frontend:** Vanilla JS (`index.php`, `js/app.js`, `js/store.js`), localStorage cache.
- **Backend:** Single action switch: `src/Api/index.php`.
- **Redis Hot State:** Hash `card:<id>`; ZSETs: `cards:index`, `categories:index`, `cat:<id>:cards`; Stream `cards:stream`.
- **MariaDB Durable State:** Tables `cards`, `categories` populated by worker only (unless write-behind disabled).
- **Save Flow:** Debounced keystrokes → `save_card` → validate → HSET + ZADD(s) + XADD → return.
- **Delete Flow:** `delete_card` removes hash + ZSET members (soft) → worker later DELETE in DB.

## 3. API Actions (`src/Api/index.php`)

- **Core Mutations:** `save_card`, `bulk_save`, `save_category`, `delete_category`, `delete_card`, `version_restore`, `version_snapshot`.
- **History (DB orphans):** `history` (list), `history_restore` (rehydrate), `history_purge` (permanent delete). Restore/purge mutate DB only.
- **Reads / Diagnostics:** `state`, `health`, `metrics`, `versions_list`, `version_get`.
- **Debug Only:** `flush_once`, `trim_stream` (only when `APP_DEBUG=true`).
- **Add an action:** Insert alphabetically when practical; parse via `json_input()`, validate early, mutate Redis, return with `ok()`/`fail()`.

## 4. Constraints & Patterns

- **Validation:** IDs: hex 16–64 or UUIDv4 (strict if `APP_REQUIRE_UUID`). Text: enforce `APP_CARD_MAX_LEN`. Rate limit: `rl_check()` (fail-open if Redis down).
- **Prunable Empty:** If `PRUNE_EMPTY=true` and `strlen(text) < APP_EMPTY_MINLEN` card treated as empty for deletion during flush.
- **Worker (`bin/flush.php`):** Batch stream; coalesce by card id; single transaction; delete row if hash missing or prunable; trim stream (~); NEVER per-event DB writes.
- **Configuration Add:** 1) Add env + fallback in `config.php`; 2) Use constant (not `getenv()` inline); 3) Document in `.env.example` + README.
- **Testing:** `composer test`; add tests for new actions, validation paths, prunable behavior, ordering changes.
- **Style:** Small pure funcs, early returns, pipelined Redis ops, `declare(strict_types=1);` in new files, no frameworks.

## 5. Anti-Patterns (Do Not Do)

- Add ORM / framework.
- Synchronous DB writes during request when write-behind active.
- Inject HTML into note text path.
- Broad silent exception catches.

## 6. When to Escalate (Read README)

- DB schema changes (tables or versions storage).
- Worker algorithm / batching / trimming alterations.
- Concurrency or ordering semantics adjustments (ZSET scores, move logic).
- New Composer dependencies.
- Search, multi-user, rich text / HTML rendering.
- Security model changes (CSP, rate limiting overhaul).
