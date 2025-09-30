# Changelog

All notable changes to this project will be documented in this file.
The format loosely follows Keep a Changelog; versioning will follow SemVer once 1.0.0 is tagged.

## [Unreleased]
### Added
- Metrics endpoint (`action=metrics`) returning cumulative saves/deletes and stream stats.
- Centralized validation helper `card_validate_id_and_text()` (ID pattern + size enforcement).
- Rate limiting for mutating endpoints using Redis counters (configurable via `APP_RATE_LIMIT_MAX` / `APP_RATE_LIMIT_WINDOW`).
- Security headers: CSP with nonce, X-Frame-Options, Referrer-Policy, Permissions-Policy, nosniff.
- Namespaced `Renote\Domain\CardRepository` as a light abstraction layer.
- Integration smoke tests (validation and metrics) under `tests/`.

### Changed
- API `save_card` and `bulk_save` now validate IDs and text size (oversize returns `text_too_long`).
- Debug endpoints (`flush_once`, `trim_stream`, history restore/purge) gated behind `APP_DEBUG`.
- Deprecated legacy files isolated (to be removed before 1.0.0).

### Removed
- Direct Redis legacy prototype (`api.redis.php`) now returns 410 (scheduled for deletion before 1.0.0).
- Old `api.js` replaced by modern `app.js` (legacy stub retained temporarily).

### Security
- Added server-side rate limiting and size validation mitigating resource exhaustion.

### Pending Before 1.0.0
- Decide on markdown implementation scope.
- Add migration script if strict UUID enforcement (APP_REQUIRE_UUID=true) becomes default.
- Final removal of `legacy/` directory and deprecated stubs.

## [0.1.0] - 2025-09-29
Initial early release (baseline features: Redis hot path, write-behind worker, history restore, drag ordering, soft delete).

