<?php
// Configuration loader: derive constants from environment (.env) while keeping legacy constant names intact.
// Edits should be made in .env; this file remains safe to commit (no secrets).

// Load dotenv if available (soft-fail so existing deployments without vlucas/phpdotenv keep working)
if (class_exists('Dotenv\\Dotenv')) {
    try {
        Dotenv\Dotenv::createImmutable(__DIR__)->safeLoad();
    } catch (Throwable $e) {
        // Intentionally ignored: fall back to process environment / server config.
    }
}

// ---- Helpers ----------------------------------------------------------------
/**
 * Fetch a boolean from env; accepts 1/true/yes/on (case-insensitive).
 */
function env_bool(string $key, bool $default = false): bool
{
    $v = $_ENV[$key] ?? getenv($key);
    if ($v === null) {
        return $default;
    }
    $v = strtolower(trim((string)$v));
    return in_array($v, ['1', 'true', 'yes', 'on'], true);
}

function env_int(string $key, int $default): int
{
    $v = $_ENV[$key] ?? getenv($key);
    if ($v === null || $v === '') {
        return $default;
    }
    return (int)$v;
}

function env_str(string $key, string $default): string
{
    $v = $_ENV[$key] ?? getenv($key);
    return ($v === null || $v === '') ? $default : (string)$v;
}

/**
 * Define a constant only if not already provided by the host (legacy config.php overrides still work).
 */
function define_env(string $name, $value): void
{
    if (!defined($name)) {
        define($name, $value);
    }
}

// ---- Redis ------------------------------------------------------------------
// Prefer UNIX socket for latency; switch to TCP by setting REDIS_CONNECTION=tcp and host/port below.
define_env('REDIS_CONNECTION_TYPE', env_str('REDIS_CONNECTION', 'unix'));
define_env('REDIS_HOST', env_str('REDIS_HOST', '127.0.0.1'));
define_env('REDIS_PORT', env_int('REDIS_PORT', 6379));
define_env('REDIS_SOCKET', env_str('REDIS_SOCKET', '/var/run/redis/redis.sock'));
// Optional authentication; leave blank for local socket-only deployments.
define_env('REDIS_USERNAME', env_str('REDIS_USERNAME', ''));
define_env('REDIS_PASSWORD', env_str('REDIS_PASSWORD', ''));

// ---- MariaDB ---------------------------------------------------------------
// Prefer socket for local deployments; disable DB_USE_SOCKET to force TCP (required for remote hosts).
define_env('MYSQL_USE_SOCKET', env_bool('DB_USE_SOCKET', true));
define_env('MYSQL_HOST', env_str('DB_HOST', '127.0.0.1'));
define_env('MYSQL_PORT', env_int('DB_PORT', 3306));
define_env('MYSQL_SOCKET', env_str('DB_SOCKET', '/run/mysqld/mysqld.sock'));
define_env('MYSQL_DB', env_str('DB_NAME', 'pnotes'));
define_env('MYSQL_USER', env_str('DB_USER', 'pnoteuser'));
define_env('MYSQL_PASS', env_str('DB_PASS', ''));

// TLS (only relevant when TCP is in use). Do not disable verification unless you control the network.
define_env('MYSQL_SSL_ENABLE', env_bool('DB_SSL_ENABLE', true));
define_env('MYSQL_SSL_VERIFY_SERVER_CERT', env_bool('DB_SSL_VERIFY', false));
define_env('MYSQL_SSL_CA', env_str('DB_SSL_CA', 'ssl/ca.crt'));   // CA used to validate server cert
define_env('MYSQL_SSL_CRT', env_str('DB_SSL_CRT', 'ssl/client.crt')); // Optional client certificate
define_env('MYSQL_SSL_KEY', env_str('DB_SSL_KEY', 'ssl/client.key')); // Optional client key

define_env('PDO_COMMON', [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
]);
define_env('PDO_MYSQL_SSL_CIPHER', 'DHE-RSA-AES256-GCM-SHA384');

// ---- Redis keys (stable) ----------------------------------------------------
define_env('REDIS_INDEX_KEY', 'cards:index');
define_env('REDIS_CATEGORIES_INDEX', 'categories:index');
define_env('REDIS_CATEGORY_PREFIX', 'category:');
define_env('REDIS_UPDATED_AT', 'cards:updated_at');
define_env('REDIS_STREAM', 'cards:stream');
define_env('REDIS_STREAM_LAST', 'cards:stream:lastid');
define_env('REDIS_LAST_FLUSH_TS', 'cards:last_flush_ts');

// ---- Feature flags & tuning -------------------------------------------------
define_env('APP_DEBUG', env_bool('APP_DEBUG', false));
define_env('APP_WRITE_BEHIND', env_bool('APP_WRITE_BEHIND', true));
define_env('APP_WRITE_BEHIND_MODE', env_str('APP_WRITE_BEHIND_MODE', 'batch')); // batch | continuous
// Stream length guard; lowering risks evicting unflushed events if worker is stalled.
define_env('APP_STREAM_MAXLEN', env_int('APP_STREAM_MAXLEN', 20000));
// Worker behaviour (polling / batching)
define_env('APP_WORKER_BLOCK_MS', env_int('WORKER_BLOCK_MS', 5000));
define_env('APP_WORKER_MAX_BATCH', env_int('WORKER_MAX_BATCH', 1000));
define_env('APP_WORKER_TRIM_EVERY', env_int('WORKER_TRIM_EVERY', 500));
define_env('APP_WORKER_MIN_OK_LAG', env_int('WORKER_MIN_OK_LAG', 20));
define_env('APP_WORKER_MIN_DEGRADED_LAG', env_int('WORKER_MIN_DEGRADED_LAG', 200));
define_env('APP_BATCH_FLUSH_EXPECTED_INTERVAL', env_int('BATCH_FLUSH_EXPECTED_INTERVAL', 180));
// Validation / pruning
define_env('APP_PRUNE_EMPTY', env_bool('PRUNE_EMPTY', true));          // Removes near-empty cards during flush; disable to keep blanks
define_env('APP_EMPTY_MINLEN', env_int('EMPTY_MINLEN', 1));
define_env('APP_CARD_MAX_LEN', env_int('APP_CARD_MAX_LEN', 262144));   // Guardrail against runaway payloads (bytes/characters)
define_env('APP_REQUIRE_UUID', env_bool('APP_REQUIRE_UUID', false));   // Enforce UUIDv4 IDs instead of hex
// Rate limiting (mutating endpoints). Set RATE_LIMIT_MAX=0 to disable.
define_env('APP_RATE_LIMIT_WINDOW', env_int('RATE_LIMIT_WINDOW', 60));
define_env('APP_RATE_LIMIT_MAX', env_int('RATE_LIMIT_MAX', 300));
// Versioning / history snapshots
define_env('APP_VERSION_MAX_PER_CARD', env_int('APP_VERSION_MAX_PER_CARD', 25));      // Cap per-card stored versions
define_env('APP_VERSION_MIN_INTERVAL_SEC', env_int('APP_VERSION_MIN_INTERVAL_SEC', 60)); // Minimum seconds between auto snapshots
define_env('APP_VERSION_MIN_SIZE_DELTA', env_int('APP_VERSION_MIN_SIZE_DELTA', 20));  // Minimum size delta to snapshot automatically
define_env('APP_VERSION_RETENTION_DAYS', env_int('APP_VERSION_RETENTION_DAYS', 0));   // 0 disables age-based pruning
