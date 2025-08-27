# Copilot Instructions — **pnotes** (PHP + Redis + MariaDB)

These instructions guide an AI coding assistant ("Copilot") to build, run, and maintain the **pnotes** single‑page notes app with **per‑keystroke Redis writes** and **write‑behind flush to MariaDB**.

---

## 0) Mission & Constraints

* **Goal:** Minimal, fast, reliable note cards grid with modal editor. Every keystroke persists **locally** and to **Redis**; periodic write‑behind flush to **MariaDB** for durability.
* **Infra:** Nginx, PHP‑FPM, Predis (UNIX socket), Redis (UNIX socket, AUTH or ACL), MariaDB (UNIX socket, `require_secure_transport=ON`).
* **UX:** Dark mode, draggable cards, 1‑line blurb on card, modal edit, double‑click/arm trash, add card button, instant local save.
* **Perf:** Debounce network saves (\~450 ms) while preserving every change; bulk save on page unload.
* **Simplicity:** Flat PHP (no framework), ESM front‑end, tiny localStorage shim.

---

## 1) Repository Layout

```
pnotes/
├─ index.php                 # Renders SPA and injects initial state
├─ api.php                   # REST API (Redis hot path + DB hydrating)
├─ bootstrap.php             # Predis + PDO singleton factories, helpers
├─ config.php                # Central config (sockets, creds, stream keys)
├─ flush_redis_to_db.php     # Worker: Redis→MariaDB write‑behind flusher
├─ styles.css                # Dark UI
├─ app.js                    # Grid + modal + drag + debounce saves
├─ modern.store.min.js       # minimal localStorage wrapper
├─ composer.json             # predis/predis
├─ public/ (optional)        # if using a public web root layout
└─ systemd/
   ├─ pnotes-flush.service
   └─ pnotes-flush.timer
```

---

## 2) Tech Stack & Versions

* **PHP** ≥ 8.1 with **PDO** and **unix sockets** enabled.
* **Redis** ≥ 6 (ACL capable), UNIX socket, AOF `everysec`.
* **MariaDB** ≥ 10.5, `require_secure_transport=ON`, UNIX socket `/run/mysqld/mysqld.sock`.
* **Nginx** as reverse proxy to PHP‑FPM.
* **Predis** (composer package) for Redis client.

---

## 3) Environment / Config

Create **`config.php`** with the following constants (Copilot must parameterize, do not hard‑code secrets in repo):

```php
<?php
// Redis via UNIX socket
const REDIS_SOCKET   = '/run/redis/redis-server.sock';
const REDIS_USERNAME = null;                    // e.g. 'pnotes'
const REDIS_PASSWORD = 'CHANGEME_REDIS_PASS';   // requirepass or ACL user pwd

// MariaDB via UNIX socket (secure with require_secure_transport)
const MYSQL_USE_SOCKET = true;
const MYSQL_SOCKET     = '/run/mysqld/mysqld.sock';
const MYSQL_DB         = 'pnotes';
const MYSQL_USER       = 'pnoteuser';
const MYSQL_PASS       = 'CHANGEME_DB_PASS';

// PDO common options
const PDO_COMMON = [
  PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  PDO::ATTR_EMULATE_PREPARES   => false,
];

// Redis keys
const REDIS_INDEX_KEY    = 'cards:index';
const REDIS_UPDATED_AT   = 'cards:updated_at';
const REDIS_STREAM       = 'cards:stream';
const REDIS_STREAM_LAST  = 'cards:stream:lastid';
```

> If Redis ACL users are configured, set both `REDIS_USERNAME` and `REDIS_PASSWORD`. If not, keep username null and only set password.

---

## 4) Dependencies

**composer.json**

```json
{
  "name": "tys/pnotes",
  "type": "project",
  "require": { "predis/predis": "^2.3" },
  "autoload": { "psr-4": { "": "." } }
}
```

**Install:**

```bash
composer install --no-dev --optimize-autoloader
```

---

## 5) Database Schema

Run as a privileged MariaDB user:

```sql
CREATE DATABASE IF NOT EXISTS pnotes CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pnotes.cards (
  id         VARCHAR(64) PRIMARY KEY,
  txt        MEDIUMTEXT NOT NULL,
  `order`    INT NOT NULL DEFAULT 0,
  updated_at BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

## 6) Redis Configuration Checklist

* Enable UNIX socket in `/etc/redis/redis.conf`:

  ```
  unixsocket /run/redis/redis-server.sock
  unixsocketperm 660
  requirepass <yourpass>           # or ACL users
  appendonly yes
  appendfsync everysec
  ```
* Ensure web/worker user can access socket: add to `redis` group or set `SupplementaryGroups=redis` in the service.

---

## 7) PHP Bootstrap Helpers (summary)

Copilot must ensure **`bootstrap.php`**:

* Requires `vendor/autoload.php` and `config.php`.
* Provides `redis_client()` using Predis with UNIX socket + auth.
* Provides `db()` using PDO + UNIX socket DSN.
* Implements:

  * `load_state()` → read all from Redis, else hydrate from DB to Redis.
  * `redis_upsert_card($id, $text, $order)` → `HMSET`, index add, stream `XADD`, update `REDIS_UPDATED_AT`.
  * `delete_card_everywhere($id)` → remove from Redis and delete from DB.

> Copilot must keep **no hard‑coded paths or secrets** other than defaults shown above.

---

## 8) API Contract

All endpoints return JSON and are routed through **`api.php`**.

### `GET /api.php?action=state`

* **200** `{ cards: [{id,text,order,updated_at}], updated_at }`
* Reads from Redis; if empty hydrates from DB.

### `POST /api.php?action=save_card`

* payload: `{ id: string, text: string, order: number }`
* Writes to Redis and enqueues to stream; returns `{ ok: true, updated_at }`.

### `POST /api.php?action=bulk_save`

* payload: `{ cards: [{id,text,order}, ...] }`
* Upserts each to Redis; returns `{ ok: true, updated_at }`.

### `POST /api.php?action=delete_card`

* payload: `{ id: string }`
* Deletes from Redis and DB; returns `{ ok: true }`.

---

## 9) Flusher Worker (Redis → MariaDB)

**File:** `flush_redis_to_db.php`

* Must `require 'bootstrap.php';` and **`redis_client()->ping()`** to validate AUTH/socket.
* Use **raw `XREAD`** via `executeRaw()` to avoid Predis signature pitfalls.
* Process up to N×1000 entries per run (configurable), coalescing by card id.
* For each seen id: `HGETALL card:{id}` → UPSERT; missing hash ⇒ DELETE row.
* Advance `REDIS_STREAM_LAST` **after** successful commit.

**systemd units** (timer-driven):

```
# systemd/pnotes-flush.service
[Unit]
Description=Flush Redis notes to MariaDB
After=redis-server.service mariadb.service

[Service]
Type=oneshot
User=www-data
Group=www-data
SupplementaryGroups=redis
WorkingDirectory=/var/www/pnote
ExecStart=/usr/bin/php /var/www/pnote/flush_redis_to_db.php

# systemd/pnotes-flush.timer
[Unit]
Description=Flush Redis notes to MariaDB (frequent)

[Timer]
OnBootSec=10s
OnUnitActiveSec=5s
Unit=pnotes-flush.service

[Install]
WantedBy=timers.target
```

**Enable:**

```bash
sudo cp systemd/* /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now pnotes-flush.timer
```

---

## 10) Front‑End Behavior (summary)

* **Grid** renders cards sorted by `order`.
* **Drag** reorders by card handle; on reorder, update `order`, save local, queue server save.
* **Modal** opens on card click; every keystroke updates localStorage and debounced `save_card`.
* **Delete** button is two‑step (arm→confirm); on confirm, call `delete_card` and close modal.
* **Add** creates new card, immediate `save_card`, opens modal.
* **Unload** fires `bulk_save` via `sendBeacon` if available.

> Copilot must keep `app.js` debounce \~450 ms and ensure local blurbs update live.

---

## 11) Nginx vhost (sample)

```
server {
  listen 80;
  server_name cards.local;  # change to your host
  root /var/www/pnote;      # project root
  index index.php;

  location / {
    try_files $uri /index.php;
  }

  location ~ \.php$ {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
    fastcgi_pass unix:/run/php/php-fpm.sock;  # adjust for your distro
  }
}
```

---

## 12) Makefile (optional developer QoL)

```
.PHONY: deps fmt lint flush test

deps:
	composer install --no-dev --optimize-autoloader

flush:
	php flush_redis_to_db.php

fmt:
	php -l index.php api.php bootstrap.php flush_redis_to_db.php || true

# lint target could integrate phpstan/psalm if added later
```

---

## 13) Testing Plan

* **Unit-ish** (CLI):

  * Add card → `POST save_card` → `GET state` includes it.
  * Edit text (simulate inputs) → verify Redis `HGETALL` shows updates; run flusher → row present in DB.
  * Delete card → verify hash gone; flusher → row absent.
* **Concurrency:** Rapid edits across two clients; ensure no exceptions and order stable.
* **Power loss simulation:** Stop flusher; make edits; restart worker; ensure DB converges.

**cURL examples:**

```bash
curl -s http://localhost/api.php?action=state | jq .

curl -s -X POST http://localhost/api.php?action=save_card \
  -H 'Content-Type: application/json' \
  -d '{"id":"abc123","text":"Hello","order":0}' | jq .

curl -s -X POST http://localhost/api.php?action=bulk_save \
  -H 'Content-Type: application/json' \
  -d '{"cards":[{"id":"abc123","text":"Hello again","order":0}]}' | jq .

curl -s -X POST http://localhost/api.php?action=delete_card \
  -H 'Content-Type: application/json' \
  -d '{"id":"abc123"}' | jq .
```

---

## 14) Security & Hardening

* **Secrets:** Mount `config.php` from a secure path or env; restrict perms to web user.
* **Redis:** UNIX socket, group‑restricted; AUTH or ACL users; AOF `everysec`.
* **DB:** UNIX socket; principle of least privilege (limit to needed schema).
* **Nginx:** deny access to any non‑public files if using `/public` root.
* **Input:** JSON only; validate types and lengths; limit card `text` size (e.g., 256 KB max) if needed.

---

## 15) Observability & Ops

* **Logs:**

  * systemd journal for worker; search `pnotes-flush.service`.
  * Nginx access/error logs for API.
* **Metrics (optional):** Add simple counters in Redis (`INCR`) for saves/deletes and expose as a debug endpoint.
* **Backups:**

  * MariaDB dumps on schedule.
  * Redis AOF + RDB snapshots; optional nightly JSON export.

---

## 16) Common Pitfalls & Fixes

* **`NOAUTH`**: Set `REDIS_PASSWORD` (and `REDIS_USERNAME` if ACLs) in `config.php`.
* **Socket perms**: Add web/worker user to `redis` group; set `unixsocketperm 660`; restart Redis and php‑fpm.
* **MariaDB `require_secure_transport`**: Use UNIX socket DSN in `db()`.
* **Predis `XREAD` signature errors**: Use `executeRaw(['XREAD', ...])` in the worker.
* **Checkpoint stuck**: Ensure `REDIS_STREAM_LAST` is updated **after** DB commit.

---

## 17) Definition of Done (DoD)

* App runs behind Nginx, saves every keystroke locally + to Redis, survives reloads.
* Flusher propagates updates to MariaDB within seconds; no data loss except acceptable AOF window.
* Deleting a card removes Redis hash + DB row.
* Clean logs (no fatal errors) for 24h under normal usage.
* Minimal bundle size; no external CDNs.

---

## 18) Future Enhancements (optional backlog)

* Multi‑user auth, per‑user namespaces.
* Conflict resolution (vector clocks) for simultaneous edits on same card.
* Full‑text search (MariaDB or external index).
* Attachments table and object storage (S3/R2) with signed URLs.
* WebSocket presence + live sync.

---

## 19) Copilot Execution Order (Checklist)

1. Create repo scaffold with files from §1; add `composer.json`.
2. Implement `config.php` with placeholders; document how to fill.
3. Implement `bootstrap.php` factories + helpers.
4. Implement `api.php` per §8.
5. Implement `index.php`, `app.js`, `styles.css`, `modern.store.min.js` (use existing versions).
6. Create DB schema (§5).
7. Configure Redis (§6).
8. Implement `flush_redis_to_db.php` with raw `XREAD`; add systemd units; enable timer.
9. End‑to‑end test with cURL commands (§13).
10. Write README quickstart and ops notes; confirm DoD (§17).

---

**End of instructions.**
