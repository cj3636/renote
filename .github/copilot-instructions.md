# AI Assistant Guide (Renote)

This guide helps AI assistants contribute to Renote, a minimalist note-taking app. The priorities are data integrity, low latency, and simple, maintainable code.

## Quick Start & Developer Workflows

All configuration is through an `.env` file (see `.env.example`). Core dependencies are PHP, Redis, and MariaDB.

Use these `composer` scripts for common tasks:
- `composer test`: Run the PHPUnit test suite.
- `composer analyse`: Perform static analysis with PHPStan.
- `composer format`: Automatically format code with `php-cs-fixer`.
- `composer cs`: Check for PSR-12 coding style violations with `phpcs`.
- `composer lint`: Check for PHP syntax errors in key files.

To run the application locally:
1. `composer install`
2. Set up your `.env` file.
3. Run a local server: `php -S localhost:8080 index.php`
4. To process the write-behind queue during development, run: `php flush.php --once`

## Architecture Overview

- **Frontend**: `index.php` (main layout), `app.js` (vanilla JavaScript for all UI logic), and `styles.css`. The frontend uses `localStorage` for an offline-first experience and reconciles with the server.
- **Backend API**: `api.php` is a simple router for actions like saving, deleting, and loading cards.
- **Data Layer**:
    - **Redis**: The primary "hot" data store for cards. It holds card data in hashes (`card:<id>`), a sorted set for order (`cards:index`), and a stream for the write-behind queue (`cards:stream`).
    - **MariaDB**: The persistent, durable data store.
- **Configuration**: `config.php` loads settings from `.env` and defines them as constants.
- **Initialization**: `bootstrap.php` sets up database/Redis clients and provides core data functions.
- **Background Worker**: `flush.php` is a worker that reads from `cards:stream` and writes to MariaDB.

## Key Conventions & Patterns

- **Data Flow**: Client -> `api.php` -> Redis (`hash` + `zset` + `stream`) -> `flush.php` worker -> MariaDB.
- **Card Object**: `{ id, name, text, order, updated_at }`. `name` is optional. `updated_at` is a server-set Unix timestamp used for conflict resolution. The Redis hash also contains a legacy `txt` field which is a mirror of `text`.
- **API Actions**: The `api.php` switch statement handles all actions. Common actions include `state`, `save_card`, `bulk_save`, `delete_card`, and `health`. All responses are JSON: `{ ok: true, ... }` or `{ ok: false, error: "..." }`.
- **Deletion**: Deletions are "soft" on the frontend and in Redis (`delete_card_redis_only`). Cards are removed from the active set but remain in MariaDB until purged via the history UI (a debug feature).
- **Validation**: Card IDs and text length are validated in `api.php` using `card_validate_id_and_text()`. See `config.php` for constants like `APP_CARD_MAX_LEN`.
- **Coding Style**:
    - PHP: PSR-12. Use `declare(strict_types=1);` in new files.
    - Prefer small, pure functions and early returns.
    - Keep global helpers in namespaced files under `src/Support/`.
    - Do not introduce new frameworks or large dependencies without discussion.

## Security & Safety
- All data passed from the server to a script tag in `index.php` must be sanitized with `safe_json_for_script`.
- The frontend uses `textContent` to prevent XSS; do not render user-provided content as HTML.
- The Content Security Policy (CSP) in `index.php` uses a nonce. Be sure to maintain this if adding new scripts.

## Adding a Feature

1.  **Data Model**: Update the Redis hash and/or MariaDB schema if needed.
2.  **API**: Extend `api.php` with a new action, including validation.
3.  **Frontend**: Modify `app.js` to add the UI and call the new API endpoint.
4.  **Tests**: Add tests for validation, persistence, and API logic in the `tests/` directory.
5.  **Configuration**: If the feature is tunable, add a new variable to `.env.example` and `config.php`.
6.  **Documentation**: Update the `README.md` and `CHANGELOG.md`.

## Anti-Patterns to Avoid
- Do not add an ORM or any large framework.
- Avoid synchronous database writes; respect the write-behind architecture.
- Do not expand global state unnecessarily.

## Important Functions & Redis Keys

- **Core Functions**: `load_state()`, `redis_upsert_card()`, `delete_card_redis_only()`, `db_orphans()`, `card_validate_id_and_text()`.
- **Redis Keys**:
    - `cards:index` (zset): Stores the ordered list of card IDs.
    - `card:<id>` (hash): Stores the data for a single card.
    - `cards:stream`: The write-behind log for the `flush.php` worker.
    - `cards:stream:lastid`: The ID of the last entry processed by the worker.
    - `cards:last_flush_ts`: Timestamp of the last successful flush.
    - `metrics:saves`, `metrics:deletes`: Simple counters for operations.

*For more detailed context on architecture, deployment, or security, always consult the `README.md` file.*
