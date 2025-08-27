<?php
// --- Redis via UNIX socket ---
const REDIS_SOCKET = '/var/run/redis/redis.sock';  // adjust to your system
const REDIS_USERNAME = null;
const REDIS_PASSWORD = 'Deflation_TYS#36';
// --- MariaDB via UNIX socket ---
const MYSQL_USE_SOCKET = false;
const MYSQL_SOCKET     = '/run/mysqld/mysqld.sock';   // adjust to your distro
const MYSQL_DB         = 'pnotes';
const MYSQL_USER = 'pnoteuser';
const MYSQL_PASS = 'Sobulu#14';

// Optional TLS settings (used if MYSQL_USE_SOCKET === false)
const MYSQL_HOST = '127.0.0.1';
const MYSQL_PORT = 3306;
const MYSQL_SSL_CA   = '/etc/ssl/tys.crt';     // set if you use TLS
const MYSQL_SSL_CERT = '/etc/mysql/client-cert.pem'; // optional
const MYSQL_SSL_KEY  = '/etc/mysql/client-key.pem'; // optional

const PDO_COMMON = [
  PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  PDO::ATTR_EMULATE_PREPARES   => false,
];

// Stream & keys
const REDIS_INDEX_KEY    = 'cards:index';
const REDIS_UPDATED_AT   = 'cards:updated_at';
const REDIS_STREAM       = 'cards:stream';
const REDIS_STREAM_LAST  = 'cards:stream:lastid';
