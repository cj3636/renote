<?php
// NOTE: Do NOT commit real production credentials. Copy this file from example.config.php and
// replace CHANGEME values locally or load via environment-specific deployment tooling.

const DEV_MODE = false; // (placeholder hook)

// --- Redis connection ---
const REDIS_CONNECTION_TYPE = 'unix'; // 'unix' for socket, 'tcp' for TCP host/port
const REDIS_HOST = '127.0.0.1';
const REDIS_PORT = 6379;
const REDIS_SOCKET = '/var/run/redis/redis.sock';
const REDIS_USERNAME = null;
const REDIS_PASSWORD = 'CHANGEME'; // replace at deploy time

// --- MariaDB connection ---
const MYSQL_USE_SOCKET = true;
const MYSQL_HOST = '127.0.0.1';
const MYSQL_PORT = 3306;
const MYSQL_SOCKET = '/run/mysqld/mysqld.sock';
const MYSQL_DB   = 'pnotes';
const MYSQL_USER = 'pnoteuser';
const MYSQL_PASS = 'CHANGEME'; // replace at deploy time

// --- TLS (only for TCP mode) ---
const MYSQL_SSL_ENABLE = true;
const MYSQL_SSL_VERIFY_SERVER_CERT = false; // set true in production with valid CA
const MYSQL_SSL_CA  = 'ssl/ca.crt';
const MYSQL_SSL_CRT = 'ssl/client.crt';
const MYSQL_SSL_KEY = 'ssl/client.key';

const PDO_COMMON = [
  PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  PDO::ATTR_EMULATE_PREPARES   => false,
];

const PDO_MYSQL_SSL_CIPHER = 'DHE-RSA-AES256-GCM-SHA384';
const PDO_MYSQL_SSL_CA = 'ssl/ca.crt';


// Redis keys
const REDIS_INDEX_KEY      = 'cards:index';
const REDIS_UPDATED_AT     = 'cards:updated_at';
const REDIS_STREAM         = 'cards:stream';
const REDIS_STREAM_LAST    = 'cards:stream:lastid';
const REDIS_LAST_FLUSH_TS  = 'cards:last_flush_ts';

// Feature / tuning flags
if (!defined('APP_DEBUG')) define('APP_DEBUG', false); // set true for UI debug controls
if (!defined('APP_WRITE_BEHIND')) define('APP_WRITE_BEHIND', true);
if (!defined('APP_STREAM_MAXLEN')) define('APP_STREAM_MAXLEN', 20000);
if (!defined('APP_WRITE_BEHIND_MODE')) define('APP_WRITE_BEHIND_MODE', 'batch'); // or 'continuous'
if (!defined('APP_WORKER_BLOCK_MS')) define('APP_WORKER_BLOCK_MS', 5000);
if (!defined('APP_WORKER_MAX_BATCH')) define('APP_WORKER_MAX_BATCH', 1000);
if (!defined('APP_WORKER_TRIM_EVERY')) define('APP_WORKER_TRIM_EVERY', 500);
if (!defined('APP_WORKER_MIN_OK_LAG')) define('APP_WORKER_MIN_OK_LAG', 20);
if (!defined('APP_WORKER_MIN_DEGRADED_LAG')) define('APP_WORKER_MIN_DEGRADED_LAG', 200);
if (!defined('APP_BATCH_FLUSH_EXPECTED_INTERVAL')) define('APP_BATCH_FLUSH_EXPECTED_INTERVAL', 180);
if (!defined('APP_PRUNE_EMPTY')) define('APP_PRUNE_EMPTY', true);
if (!defined('APP_EMPTY_MINLEN')) define('APP_EMPTY_MINLEN', 1);
// Security / validation
if (!defined('APP_CARD_MAX_LEN')) define('APP_CARD_MAX_LEN', 262144); // 256 KB
if (!defined('APP_RATE_LIMIT_WINDOW')) define('APP_RATE_LIMIT_WINDOW', 60); // seconds
if (!defined('APP_RATE_LIMIT_MAX')) define('APP_RATE_LIMIT_MAX', 300); // requests per window per IP (mutating endpoints)
if (!defined('APP_REQUIRE_UUID')) define('APP_REQUIRE_UUID', false); // allow hex ids & uuid - set true if migrating strictly to UUID v4
