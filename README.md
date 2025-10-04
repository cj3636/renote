# Renote

A minimalist, fast note cards web app. Every keystroke is saved instantly to Redis (hot working set) and persisted asynchronously to MariaDB via a write‑behind stream worker. Offline‑friendly (localStorage), dark UI, drag & drop ordering, soft delete with recovery history.



---

## Badges

![Language](https://img.shields.io/badge/PHP-8.1%2B-777bb3?logo=php&style=for-the-badge)
![Redis](https://img.shields.io/badge/Redis-6%2B-red?logo=redis&style=for-the-badge)
![MariaDB](https://img.shields.io/badge/MariaDB-10.5%2B-003545?logo=mariadb&style=for-the-badge)
[![License](https://img.shields.io/badge/License-MIT-green?style=for-the-badge)](LICENSE)
![Status](https://img.shields.io/badge/Status-Early%20Release-blue?style=for-the-badge)

---

## Table of Contents

- Features
- Quick Start (Development)
- Production Deployment
- Configuration
- Data Model & Architecture
- API Overview
- Background Worker
- History / Soft Delete Semantics
- Security & Hardening
- Operations & Health
- Development Tooling (Linting / Tests)
- Roadmap & Upcoming Work
- Removed / Legacy Artifacts
- Missing / Deferred Features (from early design notes)
- License

---

## Features

- Instant persistence: localStorage + Redis per keystroke (debounced network ~450ms)
- Asynchronous durability via Redis Streams → MariaDB write‑behind worker
- Dark single‑page UI (no framework) with semantic HTML & minimal JS
- Draggable ordering (handle only) with bulk order synchronization to avoid divergence
- Modal editor with fullscreen toggle and double‑confirm delete
- Optional card name (title) + automatic blurb preview (first sentence)
- Soft delete: removed from Redis but recoverable from MariaDB until flush purges
- History drawer (UI) to restore or purge DB‑only cards
- Health indicator (lag classification: ok / degraded / backlog)
- Adaptive stream flush batch sizing
- Stream trimming to prevent unbounded growth

---

## Quick Start (Development)

Assumes: PHP 8.1+, Redis, MariaDB running locally (TCP or sockets). For quick experimentation you can skip MariaDB initially (some features will be limited).

1. Create your env file

```
cp .env.example .env
# Edit .env with Redis/MariaDB credentials (never commit real secrets)
```

2. Install dependencies

```
composer install
```

3. Create database + table (adjust database/user if changed):

```sql
CREATE DATABASE IF NOT EXISTS pnotes CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE pnotes;
CREATE TABLE IF NOT EXISTS cards (
  id         VARCHAR(64) PRIMARY KEY,
  name       VARCHAR(255) NULL,
  txt        MEDIUMTEXT NOT NULL,
  `order`    INT NOT NULL DEFAULT 0,
  updated_at BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

4. Run a PHP dev server (or use Apache/Nginx):

```
php -S localhost:8080 index.php
```

5. Open <http://localhost:8080> and start adding cards.

6. Manually flush (in debug mode) via the ⟳ button or CLI:

```
php flush.php --once
```

> For development you may set `APP_DEBUG=true` in `.env` to reveal debug & history controls.

---

## Production Deployment

Recommend Nginx → PHP‑FPM. Place project in `/var/www/renote` (or similar). Use systemd timer to run `flush.php --once` every few minutes (batch mode) or run continuously in continuous mode.

Example Nginx server block (minimal):

```
server {
  listen 80;
  server_name notes.example.com;
  root /var/www/renote;
  index index.php;

  location / { try_files $uri /index.php; }

  location ~ \.php$ {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
    fastcgi_pass unix:/run/php/php-fpm.sock; # adjust
  }
}
```

### systemd (batch flush)

`docs/renote.service` (oneshot) and `docs/renote.timer` are provided. Install & enable:

```
sudo cp docs/renote.service docs/renote.timer /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now renote.timer
```

Adjust interval in `renote.timer` to match `APP_BATCH_FLUSH_EXPECTED_INTERVAL` (default 180s) for accurate health status.

---

## Configuration

All runtime settings now come from environment variables (`.env` in development). `config.php` loads and maps them to legacy constants so existing code continues to function. See `.env.example` for the full list and descriptions.

Key environment variables:

- Redis: `REDIS_CONNECTION`, `REDIS_SOCKET`, `REDIS_HOST`, `REDIS_PORT`, `REDIS_USERNAME`, `REDIS_PASSWORD`
- MariaDB: `DB_USE_SOCKET`, `DB_SOCKET`, `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`, `DB_SSL_ENABLE`, `DB_SSL_VERIFY`, `DB_SSL_CA`, `DB_SSL_CRT`, `DB_SSL_KEY`
- Worker: `APP_WRITE_BEHIND`, `APP_WRITE_BEHIND_MODE`, `APP_STREAM_MAXLEN`, `WORKER_MAX_BATCH`, `WORKER_TRIM_EVERY`, `WORKER_BLOCK_MS`
- Health thresholds: `WORKER_MIN_OK_LAG`, `WORKER_MIN_DEGRADED_LAG`, `BATCH_FLUSH_EXPECTED_INTERVAL`
- Pruning / limits: `PRUNE_EMPTY`, `EMPTY_MINLEN`, `APP_CARD_MAX_LEN`
- Rate limiting & validation: `RATE_LIMIT_WINDOW`, `RATE_LIMIT_MAX`, `APP_REQUIRE_UUID`
- Debug UI: `APP_DEBUG`

Systemd: copy `.env` to `/etc/renote.env` (or similar) and the provided `renote.service` will load it via `EnvironmentFile=-/etc/renote.env`.

---

## Data Model & Architecture

| Layer | Purpose |
|-------|---------|
| Browser localStorage | Immediate offline resilience / rapid UX feedback |
| Redis Hash `card:<id>` | Authoritative hot state (fields: id, name, text, order, updated_at) |
| Redis Sorted Set `cards:index` | Global ordering of card IDs |
| Redis Stream `cards:stream` | Append‑only change log for write‑behind worker |
| MariaDB `cards` table | Durable persistence & history recovery |

Flow:

1. User types → JS updates local state + debounced `save_card` to API
2. API writes Redis hash + index and appends to stream (or writes DB directly if write‑behind disabled)
3. Worker consumes stream entries grouping by card id (coalesced) → UPSERT / DELETE in MariaDB
4. Deletions: removing from Redis (soft delete) is later detected as missing hash during flush and DB row is purged (unless restored first)

---

## API Overview (JSON)

| Action | Method | Params / Body | Notes |
|--------|--------|---------------|-------|
| `state` | GET | – | Full current state (Redis bootstrap) |
| `save_card` | POST | `{id,name?,text,order}` | Upsert one card (ID + size validated) |
| `bulk_save` | POST | `{cards:[...]}` | Order + batch upserts (invalid cards skipped) |
| `delete_card` | POST | `{id}` | Soft delete (Redis only) |
| `history` | GET | – | DB rows not in Redis |
| `history_restore` | POST | `{id}` | Rehydrate row into Redis (debug mode only) |
| `history_purge` | POST | `{id}` | Permanently remove DB row (debug mode only) |
| `flush_once` | GET | – | Run one worker batch (debug mode only) |
| `trim_stream` | GET | `keep` | Approximate trim (debug mode only) |
| `health` | GET | – | Lag & status classification |
| `metrics` | GET | – | Cumulative saves/deletes + stream stats |

All responses: `{ ok: boolean, ... }` or `{ ok:false, error: string }`.

### Validation & Limits

- IDs: hex (16–64 chars) or UUID v4; strict UUID enforced if `APP_REQUIRE_UUID=true`.
- Text length: capped by `APP_CARD_MAX_LEN` (default 256KB) → oversize returns `text_too_long`.
- Rate limiting: mutating endpoints limited per IP via Redis counters (`APP_RATE_LIMIT_MAX` per `APP_RATE_LIMIT_WINDOW`).

---

## Background Worker (`flush.php`)

- Reads stream with `XRANGE` batches (adaptive) in batch or continuous mode
- Coalesces events per card id before a DB transaction
- Missing hash ⇒ DELETE row; empty text (below `APP_EMPTY_MINLEN`) ⇒ pruned
- Updates `REDIS_STREAM_LAST` only after successful DB commit
- Trims stream every `APP_WORKER_TRIM_EVERY` processed events (approximate trim)
- Exposes stats (CLI output when run manually)

---

## History / Soft Delete

- Deleting a card removes its Redis hash + index membership (soft)
- Until the worker processes the stream entry (or detects absence) the MariaDB row remains
- History drawer lists DB rows whose ids are not in Redis so they can be restored or purged

---

## Security & Hardening

| Area | Recommendation |
|------|----------------|
| Secrets | Keep `config.php` outside repo; mount or template it per env |
| Redis | Use UNIX socket with restricted group + ACL / password; enable AOF everysec |
| DB | Least privilege user limited to `cards` table; prefer UNIX socket |
| Input | ID + size validation enforced server-side (see API overview) |
| Output Escaping | JSON embedded via `safe_json_for_script` to prevent tag breakouts |
| Headers | Strong CSP (nonce), X-Frame-Options DENY, nosniff, strict referrer, trimmed Permissions-Policy |
| Rate Limiting | Enabled for mutating endpoints (tunable) |
| Future | Add per-card version limit & optional HTML sanitizer if markdown rendering added |

---

## Operations & Health

Health endpoint computes backlog estimates from `REDIS_STREAM_LAST` → classifies lag thresholds:

- ok: `< APP_WORKER_MIN_OK_LAG`
- degraded: `< APP_WORKER_MIN_DEGRADED_LAG`
- backlog: otherwise

In batch mode status relaxes if still within expected interval since last flush.

Systemd health timer (`docs/renote-health.service` / `.timer`) can curl the health endpoint and journal results.

---

## Development Tooling

Composer dev dependencies (after update): phpstan, phpunit, php-cs-fixer.

```
composer install
composer run lint     # (if script added)
composer test
```

Static Analysis (phpstan): Adjust level / paths in `phpstan.neon.dist`.

Coding Style (php-cs-fixer): Run `vendor/bin/php-cs-fixer fix` (dry‑run in CI first).

Testing: Basic smoke tests in `tests/` (extend with Redis integration tests using a disposable DB/schema).

Suggested CI steps:

1. validate composer (install --no-interaction)
2. php -l *.php (syntax)
3. phpstan analyse
4. phpunit

---

## Roadmap & Upcoming Work

- Markdown rendering & preview panel
- Lightweight WYSIWYG formatting (inline bold/italic/code, lists)
- Text size limit + graceful truncation warnings
- Full audit/event timeline (version history per card)
- Search (full‑text index via MariaDB or external engine)
- WebSocket push (live multi‑client sync)
- Multi‑user/auth (namespaces per user)
- Attachments (object storage + signed URLs)
- Export/import (JSON / Markdown bundle)

---

## Removed / Legacy Artifacts

Legacy prototype files have been fully purged in this refactor:

- `api.redis.php` (direct Redis prototype)
- `api.js` (superseded by `app.js`)
- `legacy/` directory

No migration steps required; production behavior unchanged for supported endpoints.

---

## Missing / Deferred Features (from early design notes)

Items described in earlier internal AI specification but not currently implemented or intentionally deferred:

- Continuous worker service mode (supported by code but not shipped with a dedicated continuous systemd unit in `docs/` yet)
- Rich text / markdown editing (planned)
- Versioned edit history per card (only latest state stored)
- WebSocket real‑time collaboration (currently poll + manual refresh pattern)
- Full security hardening (CSP, rate limiting, size quotas)
- Multi‑user authentication & per‑user isolation
- Attachments / file uploads

---

## License

MIT. See LICENSE (to be added if not present).

---

## Contributing

PRs welcome. Please run linting & tests before submitting. For larger changes open an issue / discussion first.

---

## Quick Dev Commands (Reference)

```
# Run worker once (batch mode)
php flush.php --once --quiet

# Run worker continuously (experimental)
php flush.php --quiet --loop   # (would need enhancement to support --loop flag)

# Static analysis
vendor/bin/phpstan analyse

# Tests
composer test
```

> Thank you for using / contributing to Renote! 🎉
