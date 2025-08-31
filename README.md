# ReNotes

**ReNotes** is a minimalist web app for managing notes as draggable “cards.”
It is designed for reliability, instant saving, and low disk writes by combining **Redis** (fast, ephemeral store) with **MariaDB** (persistent store) in a **write-behind** architecture.

---

## Purpose

* Provide a **dark-mode single page web app** for creating and editing text cards.
* Every keystroke is **saved instantly** to localStorage (client), Redis (server), and eventually MariaDB (persistent DB).
* Cards can have:

  * **Name** (optional, shown in grid header)
  * **Text body**
  * **Order** (sortable via drag handle)
* Cards open in a modal editor with:

  * Double-confirm delete (soft delete in Redis first; DB purge happens on flush)
  * Fullscreen toggle for editing
* Cards deleted from Redis remain in MariaDB until a flush, so they can be **restored** via a History view.

---

## Architecture

### Frontend

* Served by **PHP** through Nginx.
* Single page app (`index.php`) loads:

  * **`app.js`** for logic (grid, modal, editor, drag & drop, flush/history UI).
  * **`modern.store.min.js`** for localStorage wrapper.
* State is bootstrapped safely as JSON (avoids script injection from note contents).
* Cards are draggable only via a **grab handle** in the top bar.

### Backend

* **PHP (index.php, api.php, bootstrap.php, flush\_redis\_to\_db.php)**
* **Redis**

  * Primary working store.
  * Holds card hashes: `card:<id>` with fields `id`, `name`, `text`, `order`, `updated_at`.
  * Sorted set `cards:index` stores card order.
  * Write-behind stream `cards:stream` records changes (`XADD`).
* **MariaDB**

  * Long-term persistence.
  * `cards` table with schema:

    ```sql
    CREATE TABLE cards (
      id CHAR(36) PRIMARY KEY,
      name VARCHAR(255) NULL,
      txt MEDIUMTEXT NOT NULL,
      `order` INT NOT NULL DEFAULT 0,
      updated_at INT NOT NULL
    );
    ```
  * Acts as durable store for reloads and history view.

---

## Data Flow

1. **User types in card modal**
   → JS saves locally (`localStorage`) and asynchronously to API (`save_card`).
   → API upserts into Redis hash & sorted set.
   → Redis also logs into stream (`XADD`) for write-behind persistence.

2. **Worker flush (flush\_redis\_to\_db.php)**

   * Continuously (systemd service/timer) or manually (API `flush_once`).
   * Reads Redis stream from last ID.
   * For each card ID:

     * If Redis hash is missing → DELETE from DB.
     * If text empty & pruning enabled → DELETE from DB.
     * Else UPSERT row.
   * Updates stats (`upserts`, `purges`, `skipped_empty`, `seen`).
   * Trims stream to max length.
   * Updates `REDIS_STREAM_LAST` + `REDIS_LAST_FLUSH_TS`.

3. **History view**

   * Queries DB for rows whose IDs are not in Redis index.
   * Allows user to **restore** (rehydrate into Redis) or **purge** (delete from DB permanently).

---

## Deployment

### Requirements

* **Nginx + PHP-FPM**
* **Redis** (preferably via UNIX socket for prod)
* **MariaDB** (with `pnotes` DB and `pnoteuser` user)

### Config

* `config.php` defines:

  * Redis connection (TCP or UNIX socket, password optional).
  * MariaDB connection (TCP+TLS or UNIX socket).
  * Feature toggles (write-behind enabled, debug UI, prune empty cards).
* `systemd` service + timer run the flush worker periodically:

  ```ini
  [Unit]
  Description=Flush Redis notes to MariaDB

  [Service]
  ExecStart=/usr/bin/php /var/www/pnote/flush_redis_to_db.php
  Restart=on-failure

  [Install]
  WantedBy=multi-user.target
  ```

---

## Key Files

* `index.php` – main SPA entry, includes partials (`partials/header.php`, `partials/modal.php`).
* `app.js` – frontend logic (grid, modal, drag & drop, save, flush/history).
* `api.php` – backend API endpoints:

  * `state`, `save_card`, `bulk_save`, `delete_card` (soft delete),
  * `flush_once`, `health`, `trim_stream`, `history`, `history_purge`, `history_restore`.
* `bootstrap.php` – initializes Redis + DB clients, contains core helpers:

  * `load_state()`, `redis_upsert_card()`, `delete_card_redis_only()`, `_db_upsert_card()`, `_db_delete_card()`.
  * Worker helpers: `worker_flush_event()`, `worker_commit_batch()`, `worker_commit_pending()`.
* `flush_redis_to_db.php` – background worker for write-behind persistence, prints stats when run manually.
* `styles.css` – dark-mode UI, modal, fullscreen, history drawer.
* `config.php` – defines constants for DB/Redis connection and app features.

---

## Features To Know

* **Every edit is instant**: saved locally + queued for Redis/DB flush.
* **Soft delete**: cards removed from Redis remain recoverable in DB until flushed.
* **History drawer**: view DB-only cards; restore or purge them.
* **Optional card names**: shown in grid header; saved to DB.
* **Drag-and-drop**: only via grab handle.
* **Flush stats**: visible in CLI and UI debug mode.

---

## TODO / Nice-to-haves

* Proper **versioning** of card edits (currently only latest state stored).
* Richer text formatting or markdown preview.
* Websocket push for live multi-client sync (currently poll-based).

---

✅ With this README, any dev/agent can understand **what PNotes is**, **how Redis + MariaDB are used**, and **where to look in the codebase**.

---

Do you want me to also generate a **systemd timer+service pair** (flush every X minutes) to include in the README so ops setup is fool-proof?
