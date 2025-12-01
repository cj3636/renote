<!--
AI-Facing Project Guide (Renote)
Purpose: Enable an automated AI agent to make SMALL & SAFE changes. This is a constraints-first document. For anything structurally large (e.g., schema changes, major refactors), read the full README.md.
-->

# Renote: AI Agent Cheat Sheet

## 1. The Laws of the System (Invariants)

- **Redis is the authoritative hot state.** All reads and writes target Redis first.
- **MariaDB is for write-behind durability only.** Never write directly to the DB in the API request path when `APP_WRITE_BEHIND` is true.
- **API actions must be stateless and return early.** Validate input, perform the Redis mutation, and return `{ok: true}` or `{ok: false, error: '...'}`. No silent failures or side effects beyond the documented data flow.
- **The frontend consumes `textContent`, not `innerHTML`.** Do not introduce HTML rendering of note content without an approved sanitizer.

## 2. System Overview

- **Frontend:** `index.php` (host), `js/app.js` (logic), `js/store.js` (state/API). Vanilla JS with `localStorage` cache.
- **Backend:** `src/Api/index.php` (single `switch` statement for all actions).
- **Persistence:**
    - **Redis (Hot):**
        - `card:<id>` (HASH): `name`, `text`, `category_id`, `order`, `updated_at`.
        - `cards:index` (ZSET): Root card ordering.
        - `categories:index` (ZSET): Category ordering.
        - `cat:<id>:cards` (ZSET): Card ordering within a category.
        - `cards:stream` (STREAM): Change log for the write-behind worker.
    - **MariaDB (Durable):** `cards` and `categories` tables. Populated by the worker.
- **Save Flow:** Keystroke → `save_card` (debounced) → API validates → Redis HSET/ZADD + XADD to stream → Client OK.
- **Delete Flow:** `delete_card` → API removes from Redis hash/zset (soft delete) → Worker later purges from DB.

## 3. API Actions (`src/Api/index.php`)

- **Core Mutating Actions:** `save_card`, `bulk_save`, `save_category`, `delete_category`, `delete_card`, `version_restore`, `version_snapshot`.
- **Read/Debug Actions:** `state`, `history`, `history_restore`, `history_purge`, `flush_once`, `trim_stream`, `health`, `metrics`, `versions_list`, `version_get`.
- **Adding an action:** Add a `case` to the switch, parse with `json_input()`, validate, call a helper, return `ok()` or `fail()`.

## 4. Constraints & Patterns

- **Validation:**
    - IDs: 16-64 char hex string or UUIDv4 (`APP_REQUIRE_UUID`).
    - Text Length: Enforced by `card_validate_id_and_text` via `APP_CARD_MAX_LEN`.
    - Rate Limiting: Use `rl_check()` in mutating endpoints. It fails open if Redis is down.
- **Worker (`bin/flush.php`):** Consumes `cards:stream`. Coalesces events by card ID into a single DB transaction. Deletes DB row if Redis hash is missing or text is prunable. Trims stream.
- **Configuration:** To add a config knob: 1. Add `getenv()` with fallback in `config.php`. 2. Reference the resulting CONSTANT. 3. Document in `.env.example` and `README.md`.
- **Testing:** Run `composer test`. Add tests for new endpoints, validation rules, or state shape changes in `tests/`.
- **Style & Conventions:**
    - Run `composer run lint` to fix PSR-12 style.
    - Use `declare(strict_types=1);` in new files.
    - Prefer small, pure helper functions with early returns.
    - Use Redis pipelines for batched operations.
    - No frameworks (e.g., Laravel, Symfony).

## 5. Anti-Patterns (Do Not Do)

- Add an ORM or web framework.
- Write to the database synchronously from the API.
- Mix HTML into note text logic.
- Catch broad exceptions silently.

## 6. When to Escalate (Read the full README.md)

- Changing the DB schema (`cards` or `categories` tables).
- Altering the write-behind worker logic in `bin/flush.php`.
- Introducing new dependencies via Composer.
- Implementing features involving search, multi-user auth, or rich text.
