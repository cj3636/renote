<?php
/**
 * @codingStandardsIgnoreFile
 * @phpcs:disable
 *
 * This is a helper file for IDEs to provide autocompletion and static analysis for
 * the global constants defined in `config.php`.
 *
 * It is not meant to be included in the application at runtime. By wrapping the
 * definitions in `if (false)`, we ensure this code is never executed, but IDEs
 * and static analysis tools can still read it to understand the project's constants.
 */

if (false) {
    // ---- Redis ------------------------------------------------------------------
    define('REDIS_CONNECTION_TYPE', 'unix');
    define('REDIS_HOST', '127.0.0.1');
    define('REDIS_PORT', 6379);
    define('REDIS_SOCKET', '/var/run/redis/redis.sock');
    define('REDIS_USERNAME', '');
    define('REDIS_PASSWORD', '');

    // ---- MariaDB ---------------------------------------------------------------
    define('MYSQL_USE_SOCKET', true);
    define('MYSQL_HOST', '127.0.0.1');
    define('MYSQL_PORT', 3306);
    define('MYSQL_SOCKET', '/run/mysqld/mysqld.sock');
    define('MYSQL_DB', 'pnotes');
    define('MYSQL_USER', 'pnoteuser');
    define('MYSQL_PASS', '');
    define('MYSQL_SSL_ENABLE', true);
    define('MYSQL_SSL_VERIFY_SERVER_CERT', false);
    define('MYSQL_SSL_CA', 'ssl/ca.crt');
    define('MYSQL_SSL_CRT', 'ssl/client.crt');
    define('MYSQL_SSL_KEY', 'ssl/client.key');
    define('PDO_COMMON', []);
    define('PDO_MYSQL_SSL_CIPHER', 'DHE-RSA-AES256-GCM-SHA384');

    // ---- Redis keys (stable) ----------------------------------------------------
    define('REDIS_INDEX_KEY', 'cards:index');
    define('REDIS_CATEGORIES_INDEX', 'categories:index');
    define('REDIS_CATEGORY_PREFIX', 'category:');
    define('REDIS_UPDATED_AT', 'cards:updated_at');
    define('REDIS_STREAM', 'cards:stream');
    define('REDIS_STREAM_LAST', 'cards:stream:lastid');
    define('REDIS_LAST_FLUSH_TS', 'cards:last_flush_ts');

    // ---- Feature flags & tuning -------------------------------------------------
    define('APP_DEBUG', false);
    define('APP_WRITE_BEHIND', true);
    define('APP_WRITE_BEHIND_MODE', 'batch');
    define('APP_STREAM_MAXLEN', 20000);
    define('APP_WORKER_BLOCK_MS', 5000);
    define('APP_WORKER_MAX_BATCH', 1000);
    define('APP_WORKER_TRIM_EVERY', 500);
    define('APP_WORKER_MIN_OK_LAG', 20);
    define('APP_WORKER_MIN_DEGRADED_LAG', 200);
    define('APP_BATCH_FLUSH_EXPECTED_INTERVAL', 180);
    define('APP_PRUNE_EMPTY', true);
    define('APP_EMPTY_MINLEN', 1);
    define('APP_CARD_MAX_LEN', 262144);
    define('APP_REQUIRE_UUID', false);
    define('APP_RATE_LIMIT_WINDOW', 60);
    define('APP_RATE_LIMIT_MAX', 300);
    define('APP_VERSION_MAX_PER_CARD', 25);
    define('APP_VERSION_MIN_INTERVAL_SEC', 60);
    define('APP_VERSION_MIN_SIZE_DELTA', 20);
    define('APP_VERSION_RETENTION_DAYS', 0);
}
