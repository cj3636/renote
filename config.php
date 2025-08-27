<?php
// --- Redis via UNIX socket ---
const REDIS_CONNECTION_TYPE = 'tcp'; // Use 'unix' for production

// Redis via TCP (for development)
const REDIS_HOST = '10.0.0.36';
const REDIS_PORT = 6379;

// Redis via UNIX socket (for production)
const REDIS_SOCKET   = '/run/redis/redis-server.sock';

// Redis authentication
const REDIS_USERNAME = null;                    // e.g. 'pnotes'
const REDIS_PASSWORD = 'Deflation_TYS#36';   // requirepass or ACL user pwd. Use 'CHANGEME_REDIS_PASS' if needed.

// --- MariaDB via UNIX socket ---
const MYSQL_USE_SOCKET = false; // Use true for production

// MariaDB via TCP (for development)
const MYSQL_HOST = '10.0.0.36';
const MYSQL_PORT = 3306;

// MariaDB via UNIX socket (for production)
const MYSQL_SOCKET     = '/run/mysqld/mysqld.sock';

// MariaDB credentials
const MYSQL_DB         = 'pnotes';
const MYSQL_USER       = 'pnoteuser';
const MYSQL_PASS       = 'Deflation_TYS#36';

// MariaDB SSL options (for TCP connections)
const MYSQL_SSL_ENABLE = true; // Set to true to enable SSL/TLS
const MYSQL_SSL_VERIFY_SERVER_CERT = false; // Set to true for production with a valid cert
const MYSQL_SSL_CA = 'ssl/ca.crt'; // Path to CA for production
const MYSQL_SSL_CRT = 'ssl/client.crt'; // Path to client certificate for production
const MYSQL_SSL_KEY = 'ssl/client.key'; // Path to client key for production

// PDO common options
const PDO_COMMON = [
  PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  PDO::ATTR_EMULATE_PREPARES   => false,
];

// For production TLS, if needed instead of socket
const PDO_MYSQL_SSL_CIPHER = 'DHE-RSA-AES256-GCM-SHA384';
const PDO_MYSQL_SSL_CA = 'ssl/ca.crt';


// Stream & keys
const REDIS_INDEX_KEY    = 'cards:index';
const REDIS_UPDATED_AT   = 'cards:updated_at';
const REDIS_STREAM       = 'cards:stream';
const REDIS_STREAM_LAST  = 'cards:stream:lastid';
const REDIS_LAST_FLUSH_TS = 'cards:last_flush_ts';

// Add a debug flag constant if not present
if (!defined('APP_DEBUG')) {
    define('APP_DEBUG', true); // Set to false in production
}
if (!defined('APP_WRITE_BEHIND')) {
  define('APP_WRITE_BEHIND', true); // toggle stream write-behind
}
if (!defined('APP_STREAM_MAXLEN')) {
  define('APP_STREAM_MAXLEN', 20000); // allow more backlog for infrequent batch flush
}
// Worker tuning
if (!defined('APP_WRITE_BEHIND_MODE')) { // 'continuous' or 'batch'
  define('APP_WRITE_BEHIND_MODE', 'batch');
}
if (!defined('APP_WORKER_BLOCK_MS')) { // XREAD block duration
  define('APP_WORKER_BLOCK_MS', 5000); // still used if continuous
}
if (!defined('APP_WORKER_MAX_BATCH')) { // max events per cycle (adaptive up to this)
  define('APP_WORKER_MAX_BATCH', 1000);
}
if (!defined('APP_WORKER_TRIM_EVERY')) { // trim check frequency (processed events)
  define('APP_WORKER_TRIM_EVERY', 500);
}
if (!defined('APP_WORKER_MIN_OK_LAG')) { // health threshold for ok status
  define('APP_WORKER_MIN_OK_LAG', 20);
}
if (!defined('APP_WORKER_MIN_DEGRADED_LAG')) { // boundary between degraded/backlog
  define('APP_WORKER_MIN_DEGRADED_LAG', 200);
}
if (!defined('APP_BATCH_FLUSH_EXPECTED_INTERVAL')) { // seconds between oneshot timer runs
  define('APP_BATCH_FLUSH_EXPECTED_INTERVAL', 180); // 3 minutes
}
