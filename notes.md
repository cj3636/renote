Implemented documentation and code modernization; here’s a concise status, findings, and recommended next steps.
Checklist (original request)
Analyze project & architecture: Done (see summary below).
Replace README with production‑grade doc: Done (modern badges, quickstart, architecture, API, worker, roadmap, legacy +
missing features).
Update AI (copilot) instructions to reflect current code & keep them lean: Done (concise 16‑section guide).
Identify & address legacy / unused / broken code: Flagged and partially cleaned (details below).
Update docs systemd units to current worker: Already pointed at flush.php (OK).
Recommend next steps for structure, bugs, tooling, linting/tests: Provided below.
Add basic linting/test scaffolding: Added (phpstan, phpunit, cs-fixer configs, placeholder test, extension requirements,
LICENSE).
Key code changes & fixes
Fixed name propagation and DB upsert parameter order mismatch in _db_upsert_card.
Added name handling to save_card / bulk_save responses and persistence path.
Ensured load_state() selects name column; hydration bug fixed.
Simplified flush worker; added “library mode” to prevent accidental run when included (used by flush_once action).
Sanitized config.php (removed real secrets, replaced with CHANGEME placeholders).
Added composer dev tools and required extensions (ext-pdo, ext-mbstring).
Added MIT LICENSE, phpstan, phpunit, basic test harness, cs-fixer scaffold.
Rewrote README and copilot instructions; removed obsolete multi-step scaffolding instructions.
Added defensive global $stats initialization to avoid undefined variable when using flush_once.
Remaining legacy or cleanup targets
api.js (old frontend script) – superseded by app.js.
api.redis.php – early direct Redis extension prototype (should be deleted or moved to /legacy).
Any references in docs or comments to flush_redis_to_db.php (removed in code; confirm no stale references outside README).
Commented dev mode block in config.php is unused—can remove or convert to env-based logic.
Potential subtle issues / future hardening
No input size validation: very large text bodies could inflate Redis memory; add max length check (e.g. 256KB).
No UUID validation for id; enforce a regex or generate server-side if necessary.
Soft delete semantics rely on eventual flush; a very slow worker could create a long history window—document retention expectations or add “grace period” timestamp logic.
flush_once endpoint currently accessible whenever APP_DEBUG isn’t explicitly checked; consider restricting (auth or debug guard).
Health endpoint backlog estimation uses chunked XRANGE scanning; for very large streams a capped XRANGE or XINFO approach may be more efficient.
Missing CSP / rate limiting headers; potential to add a minimal middleware layer for security headers.
Quality gates (current state)
Syntax: get_errors reports only expected “table not found” pseudo-warnings (because static analyzer can’t see the live DB). No PHP parse errors.
Tests: Placeholder SanityTest exists and will pass; no integration tests yet.
Static analysis: phpstan config added (not executed here). Some dynamic globals ($stats) intentionally tolerated via
ignore.
Formatting: cs-fixer config added (not yet run).
README coverage: All requested sections included (features, quickstart, architecture, API, worker, history, security,
operations, roadmap, legacy, missing features).
Mapping original README (legacy instructions) to implemented/missing features Implemented (or partially):
Write-behind Redis→MariaDB sync
Debounced per-keystroke saves
Soft delete with restore history
Stream trimming
Drag & drop ordering overhaul Missing / Deferred (listed in README “Missing / Deferred Features”):
Versioned edit history
Markdown / WYSIWYG editing
WebSocket live sync
Multi-user isolation
Attachments
Advanced security hardening (CSP, rate limiting)
Conflict resolution (vector clocks) / full audit log
Full-text search
Recommended next steps (prioritized roadmap)
Immediate cleanup
Delete or move api.js and api.redis.php to legacy/ (adjust README if removed).
Strip unused commented legacy code from config.php.
Configuration & deployment hygiene
Introduce .env (phpdotenv) or environment variable loader → generate config.php from template.
Add a production APP_DEBUG guard around flush_once, trim_stream, and debug endpoints.
Testing & CI
Add Docker Compose for ephemeral Redis + MariaDB.
Write integration tests: save / delete / history restore / worker flush convergence.
GitHub Actions (or other CI): composer install, phpstan, phpunit, cs-fixer dry run.
Domain refactor (modularization)
Introduce src/Domain/CardRepository.php + src/Worker/StreamFlusher.php for clearer separation.
Namespacing for future scaling.
Validation & security
Enforce max text length server-side; reject oversize payload (422).
Validate IDs (UUIDv4). Optionally generate server-side if absent.
Add simple rate limiting (Redis INCR with TTL).
Add CSP & security headers in index.php (nonce the bootstrap script).
Observability
Add minimal metrics endpoint (counts: saves, deletes, lag snapshot).
Optional structured logging for worker batches (JSON lines).
Markdown / formatting groundwork
Decide: client-only vs. server pre-render; add card field format (e.g. plain|md) without breaking existing schema.
If adding server rendering, sanitize with a library (e.g. league/commonmark) and store in separate derived key
render_html.
Version history
New table card_versions(id PK auto, card_id, revision, txt, updated_at) with capped retention or GC policy.
Extend worker to optionally append previous state on change.
Performance & scaling
Add background continuous mode systemd unit (long-lived worker) for low-latency flush variant.
Evaluate switching from XRANGE pagination to consumer groups if multi-worker scaling needed (not urgent).
Documentation
Add architecture diagram (PNG/SVG) under docs/.
Add CHANGELOG.md after first tagged release.
Security & compliance quick wins
Replace CHANGEME placeholders in deployment pipeline (never commit secrets).
Ensure file permissions: config.php 600 (or restrict via directory ACL).
Document backup/restore procedure for MariaDB + Redis AOF in README (Ops section extension).
Optional micro-improvements now
Add Makefile / composer scripts alias: composer ci chaining lint/analyse/test.
Provide a stub Dockerfile for reproducible container builds.
If you’d like, I can proceed next with: A) Removing legacy files and introducing a legacy/ folder. B) Adding a Docker
Compose + integration test skeleton. C) Implementing a minimal CardRepository refactor.
Let me know which track you’d like to tackle first, or if you prefer a single PR-style diff covering the cleanup set (
A + partial B).