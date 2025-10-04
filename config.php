<?php
// Configuration loader: derive constants from environment (.env) with backward-compatible constant names.
// Users now edit .env (see .env.example). This file should not contain secrets directly.

// Load dotenv if available (ignore failures so existing deployments work without it)
if (class_exists('Dotenv\\Dotenv')) {
  $envPath = __DIR__;
  try {
    $dotenvClass = 'Dotenv\\Dotenv';
    $dotenvClass::createImmutable($envPath)->safeLoad();
  } catch (Throwable $e) { /* ignore */
  }
}

// Helper to read boolean-ish env values
function env_bool($key, $default = false)
{
  $v = $_ENV[$key] ?? getenv($key);
  if ($v === null) return $default;
  $v = strtolower(trim($v));
  return in_array($v, ['1', 'true', 'yes', 'on'], true);
}
function env_int($key, $default)
{
  $v = $_ENV[$key] ?? getenv($key);
  if ($v === null || $v === '') return $default;
  return (int)$v;
}
function env_str($key, $default)
{
  $v = $_ENV[$key] ?? getenv($key);
  return ($v === null || $v === '') ? $default : $v;
}

// --- Redis ---
if (!defined('REDIS_CONNECTION_TYPE')) define('REDIS_CONNECTION_TYPE', env_str('REDIS_CONNECTION', 'unix'));
if (!defined('REDIS_HOST')) define('REDIS_HOST', env_str('REDIS_HOST', '127.0.0.1'));
if (!defined('REDIS_PORT')) define('REDIS_PORT', env_int('REDIS_PORT', 6379));
if (!defined('REDIS_SOCKET')) define('REDIS_SOCKET', env_str('REDIS_SOCKET', '/var/run/redis/redis.sock'));
if (!defined('REDIS_USERNAME')) define('REDIS_USERNAME', env_str('REDIS_USERNAME', ''));
if (!defined('REDIS_PASSWORD')) define('REDIS_PASSWORD', env_str('REDIS_PASSWORD', ''));

// --- MariaDB ---
if (!defined('MYSQL_USE_SOCKET')) define('MYSQL_USE_SOCKET', env_bool('DB_USE_SOCKET', true));
if (!defined('MYSQL_HOST')) define('MYSQL_HOST', env_str('DB_HOST', '127.0.0.1'));
if (!defined('MYSQL_PORT')) define('MYSQL_PORT', env_int('DB_PORT', 3306));
if (!defined('MYSQL_SOCKET')) define('MYSQL_SOCKET', env_str('DB_SOCKET', '/run/mysqld/mysqld.sock'));
if (!defined('MYSQL_DB')) define('MYSQL_DB', env_str('DB_NAME', 'pnotes'));
if (!defined('MYSQL_USER')) define('MYSQL_USER', env_str('DB_USER', 'pnoteuser'));
if (!defined('MYSQL_PASS')) define('MYSQL_PASS', env_str('DB_PASS', ''));

// TLS (only if using TCP)
if (!defined('MYSQL_SSL_ENABLE')) define('MYSQL_SSL_ENABLE', env_bool('DB_SSL_ENABLE', true));
if (!defined('MYSQL_SSL_VERIFY_SERVER_CERT')) define('MYSQL_SSL_VERIFY_SERVER_CERT', env_bool('DB_SSL_VERIFY', false));
if (!defined('MYSQL_SSL_CA')) define('MYSQL_SSL_CA', env_str('DB_SSL_CA', 'ssl/ca.crt'));
if (!defined('MYSQL_SSL_CRT')) define('MYSQL_SSL_CRT', env_str('DB_SSL_CRT', 'ssl/client.crt'));
if (!defined('MYSQL_SSL_KEY')) define('MYSQL_SSL_KEY', env_str('DB_SSL_KEY', 'ssl/client.key'));

if (!defined('PDO_COMMON')) define('PDO_COMMON', [
  PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  PDO::ATTR_EMULATE_PREPARES   => false,
]);
if (!defined('PDO_MYSQL_SSL_CIPHER')) define('PDO_MYSQL_SSL_CIPHER', 'DHE-RSA-AES256-GCM-SHA384');
if (!defined('PDO_MYSQL_SSL_CA')) define('PDO_MYSQL_SSL_CA', 'ssl/ca.crt');

// Redis keys (unlikely to change but keep constants)
if (!defined('REDIS_INDEX_KEY')) define('REDIS_INDEX_KEY', 'cards:index');
if (!defined('REDIS_UPDATED_AT')) define('REDIS_UPDATED_AT', 'cards:updated_at');
if (!defined('REDIS_STREAM')) define('REDIS_STREAM', 'cards:stream');
if (!defined('REDIS_STREAM_LAST')) define('REDIS_STREAM_LAST', 'cards:stream:lastid');
if (!defined('REDIS_LAST_FLUSH_TS')) define('REDIS_LAST_FLUSH_TS', 'cards:last_flush_ts');

// Feature / tuning flags
if (!defined('APP_DEBUG')) define('APP_DEBUG', env_bool('APP_DEBUG', false));
if (!defined('APP_WRITE_BEHIND')) define('APP_WRITE_BEHIND', env_bool('APP_WRITE_BEHIND', true));
if (!defined('APP_STREAM_MAXLEN')) define('APP_STREAM_MAXLEN', env_int('APP_STREAM_MAXLEN', 20000));
if (!defined('APP_WRITE_BEHIND_MODE')) define('APP_WRITE_BEHIND_MODE', env_str('APP_WRITE_BEHIND_MODE', 'batch'));
if (!defined('APP_WORKER_BLOCK_MS')) define('APP_WORKER_BLOCK_MS', env_int('WORKER_BLOCK_MS', 5000));
if (!defined('APP_WORKER_MAX_BATCH')) define('APP_WORKER_MAX_BATCH', env_int('WORKER_MAX_BATCH', 1000));
if (!defined('APP_WORKER_TRIM_EVERY')) define('APP_WORKER_TRIM_EVERY', env_int('WORKER_TRIM_EVERY', 500));
if (!defined('APP_WORKER_MIN_OK_LAG')) define('APP_WORKER_MIN_OK_LAG', env_int('WORKER_MIN_OK_LAG', 20));
if (!defined('APP_WORKER_MIN_DEGRADED_LAG')) define('APP_WORKER_MIN_DEGRADED_LAG', env_int('WORKER_MIN_DEGRADED_LAG', 200));
if (!defined('APP_BATCH_FLUSH_EXPECTED_INTERVAL')) define('APP_BATCH_FLUSH_EXPECTED_INTERVAL', env_int('BATCH_FLUSH_EXPECTED_INTERVAL', 180));
if (!defined('APP_PRUNE_EMPTY')) define('APP_PRUNE_EMPTY', env_bool('PRUNE_EMPTY', true));
if (!defined('APP_EMPTY_MINLEN')) define('APP_EMPTY_MINLEN', env_int('EMPTY_MINLEN', 1));
if (!defined('APP_CARD_MAX_LEN')) define('APP_CARD_MAX_LEN', env_int('APP_CARD_MAX_LEN', 262144));
if (!defined('APP_RATE_LIMIT_WINDOW')) define('APP_RATE_LIMIT_WINDOW', env_int('RATE_LIMIT_WINDOW', 60));
if (!defined('APP_RATE_LIMIT_MAX')) define('APP_RATE_LIMIT_MAX', env_int('RATE_LIMIT_MAX', 300));
if (!defined('APP_REQUIRE_UUID')) define('APP_REQUIRE_UUID', env_bool('APP_REQUIRE_UUID', false));
