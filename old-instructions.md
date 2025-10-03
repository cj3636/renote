# AI Assistant Guide — Renote

Purpose: Minimal single‑user notes SPA. Hot path = Redis; durability via write‑behind flush to MariaDB. Keep changes small & focused unless a redesign is explicitly requested.

## 1. Core Components
- Frontend: `index.php` + `partials/`, `app.js`, `styles.css`, `modern.store.min.js` (no build step).
- Backend: `api.php` (HTTP actions), `bootstrap.php` (singletons + helpers), `flush.php` (stream worker), `config.php` (constants), optional systemd units in `docs/`.

## 2. Data Flow
Browser (localStorage immediate save) → API → Redis (hash + sorted set + stream) → Worker consumes stream → MariaDB durable store. Soft delete: remove Redis hash; DB row remains until worker decides (missing hash ⇒ purge) or user restores from history.

## 3. Redis Keys / Structures
- Hash: `card:<id>` fields: `name`, `text` (canonical), `txt` (legacy alias), `order`, `updated_at`.
- Sorted Set: `cards:index` (score=`order`).
- Stream: `cards:stream` events: fields include `id`, `name`, `text`, `order`, `updated_at` OR delete marker: `id`, `op=del`, `ts`.
- Meta keys: `cards:updated_at`, `cards:stream:lastid`, `cards:last_flush_ts`.

## 4. MariaDB Table `cards`
`(id VARCHAR(64) PK, name VARCHAR(255) NULL, txt MEDIUMTEXT NOT NULL, order INT, updated_at BIGINT)`

## 5. Key PHP Helpers (bootstrap.php)
- `redis_client()` Predis singleton (UNIX socket preferred).
- `db()` PDO singleton.
- `load_state()` — prefer Redis; hydrate from DB if empty (writes back to Redis).
- `redis_upsert_card($id, $text, $order, $name='')` → hash + zset + stream (or DB fallback if write‑behind disabled).
- `delete_card_redis_only($id)` soft delete + enqueue del event.
- `_db_upsert_card(...)` / `_db_delete_card(...)` internal only.
- `db_orphans()` DB rows not in Redis (history view).
- `safe_json_for_script($value)` escape for embedding.

## 6. API Actions (api.php)
| Action | Method | Notes |
|--------|--------|-------|
| state | GET | Boot state (Redis) |
| save_card | POST `{id,name?,text,order}` | Upsert (ID + size validated) |
| bulk_save | POST `{cards:[...]}` | Batch upsert (invalid entries skipped) |
| delete_card | POST `{id}` | Soft delete (Redis only) |
| history | GET | DB orphans list |
| history_restore | POST `{id}` | Restore card (debug mode only) |
| history_purge | POST `{id}` | Permanently delete row (debug mode only) |
| flush_once | GET | One worker batch (debug mode only) |
| trim_stream | GET `keep` | Approx stream trim (debug mode only) |
| health | GET | Lag + status (ok / degraded / backlog) |
| metrics | GET | `{metrics:{saves,deletes},stream_length,last_flushed_id}` |

Response schema: success `{ ok:true, ... }`; failure `{ ok:false, error }`.

### Validation / Limits
- ID must be hex (16–64) or UUID v4 (strict if `APP_REQUIRE_UUID=true`).
- Text length ≤ `APP_CARD_MAX_LEN` (default 256 KB) else `text_too_long`.
- Mutating endpoints rate‑limited via Redis counters (`APP_RATE_LIMIT_MAX` per `APP_RATE_LIMIT_WINDOW` seconds); soft-fails if Redis unavailable.

## 7. Worker (flush.php)
- Reads with `XRANGE` batches; adaptive expansion if backlog.
- Coalesces by id (`$FLUSH_QUEUE`).
- Missing hash ⇒ DELETE row. Empty text below `APP_EMPTY_MINLEN` ⇒ prune.
- Commits transaction then updates `REDIS_STREAM_LAST` & `REDIS_LAST_FLUSH_TS`.
- Trims stream every `APP_WORKER_TRIM_EVERY` processed events (approximate `MAXLEN ~`).
- Library mode: define `REN0TE_WORKER_LIBRARY_MODE` before include to expose functions without running loop (used by `flush_once`).

## 8. Configuration (config.php)
Additions of note: `APP_CARD_MAX_LEN`, `APP_RATE_LIMIT_MAX`, `APP_RATE_LIMIT_WINDOW`, `APP_REQUIRE_UUID`.

## 9. Security & Headers
- CSP with nonce (script-src self + nonce, default-src none, restrictive sources).
- X-Frame-Options DENY, X-Content-Type-Options nosniff, Referrer-Policy strict-origin-when-cross-origin, Permissions-Policy minimal.
- Rate limiting and input size enforcement now part of baseline.

## 10. Adding Features
If introducing a new card field:
1. Extend Redis hash write & hydrate read logic.
2. Update DB schema (migration) + worker UPSERT.
3. Include field in API payloads & frontend state.
4. Backfill or gracefully ignore missing legacy events.

For markdown / formatting (planned): store raw source in `text`; derive presentation client‑side or store separate field (e.g. `render_html`) — never overwrite raw text.

## 11. Testing Guidance
Integration tests should: create card → assert via `state`; edit → flush → ensure durability; delete → flush → ensure absence. When Redis/DB unavailable, tests may skip (see placeholders). Prefer lightweight fixtures; do not mock Predis unless isolating logic.

Add tests for: validation (invalid IDs, oversize text), metrics counters (saves/deletes), and history restore path (behind debug flag). Rate limit tests may require HTTP harness; keep unit-level logic isolated.

## 12. Performance Notes
- Client debounce ~450ms; keep ≥250ms to avoid write amplification.
- Bulk reorder writes all orders once to keep consistent order across clients.
- Approximate stream trimming chosen for speed; only switch to exact if retention auditing required.

## 13. Security Notes
- Enforce max card length before adding rich text (future).
- Use UNIX sockets + restricted groups for Redis & MariaDB in production.
- Avoid echoing user text directly; rely on JSON + DOM textContent.
- Do not disable CSP nonce without reviewing inline scripts.

## 14. Roadmap Hooks (do not silently implement):
- Markdown preview & lightweight WYSIWYG.
- Version history (append-only table).
- WebSocket push sync.
- Multi-user namespaces.
- Attachments / export.

## 15. Pre-Merge Checklist
1. `composer analyse` passes (or documented ignore).
2. `composer test` green / acceptable skips.
3. README updated if behavior or setup changes.
4. Health endpoint still returns tri‑state status & lag fields.
5. Stream field schema backward compatible.
6. Ensure CHANGELOG.md updated for externally visible changes.

## 16. Quick Reference
- Upsert: `redis_upsert_card($id, $text, $order, $name)`
- Soft delete: `delete_card_redis_only($id)`
- Bootstrap: `load_state()`
- History list: `db_orphans()`
- Manual flush cycle (API): `flush_once` action
- Metrics snapshot: `metrics_snapshot()` returns cumulative counters.

Legacy: `legacy/` directory slated for removal before 1.0.0; avoid reintroducing deprecated files. Use new namespaced `Renote\Domain\CardRepository` for advanced refactors.

Keep responses concise. If unsure, prefer code comments + minimal doc delta over speculative design.


---

START EXAMPLE:

---

# GitHub Copilot Custom Instructions for PHP Website

## Project Summary
This is a standard PHP web application managed with Composer. It uses a custom-built, lightweight MVC architecture. The public-facing entry point is `public/index.php`. Business logic is handled in the `src/` directory, and templates are located in `templates/`.

## Technology Stack
- **Language:** PHP 8.2 or higher
- **Dependency Manager:** Composer
- **Routing:** A custom router class located at `src/Router.php`.
- **Templating:** Pure PHP templates in the `templates/` directory.
- **Database:** Uses PDO for database interactions.
- **Security:** Follows modern security practices, including input validation and prepared statements.
- **Code Standards:** Adheres to the PSR-12 coding standard.

## File Structure
- `public/`: Web server document root.
- `src/`: Contains all application logic (controllers, models, services).
- `templates/`: Stores PHP view files.
- `vendor/`: Composer dependencies (managed by Composer).
- `composer.json`: Defines project dependencies.
- `composer.lock`: Records the exact versions of dependencies.
- `.env`: Stores environment-specific configuration.

## Coding Style and Best Practices
- **Type Hinting:** Always use strict type hints for function and method arguments and return types.
- **Dependency Injection:** Use dependency injection for services and controllers. Avoid using global variables or static calls for application services.
- **Database Interactions:** For SQL queries, always use parameterized queries with PDO to prevent SQL injection.
- **Error Handling:** Use custom exceptions for handling application-specific errors.
- **Variable Naming:** Use `camelCase` for variables and methods.
- **Function and Class Naming:** Use `PascalCase` for class names.
- **Comments:** Include DocBlocks for all new classes and public methods to explain their purpose, parameters, and return values.

## Copilot Actions and Prompts

### General
- "Generate a new service class for handling user data, including CRUD methods."
- "Write a PHPDoc block for this function explaining its purpose."
- "Refactor this code to follow PSR-12 coding standards."

### Composer
- "Add a new dependency to `composer.json` for a logging library, and suggest a simple usage example in a new service."
- "Generate a script in `composer.json` to run all project unit tests."

### Security
- "Write a function to sanitize a string input before storing it in the database."
- "Generate a new PDO prepared statement to insert data into the `users` table."

### Routing
- "In `src/Router.php`, add a new route for `/products/{id}` that maps to a `ProductController` method."

### Templates
- "Generate a new PHP template file that displays a list of items passed to it, and include basic HTML scaffolding."
