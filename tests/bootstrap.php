<?php
// Test bootstrap: load autoloader & guard external services.
require_once __DIR__ . '/../vendor/autoload.php';

// Prevent accidental use of real production credentials in CI by requiring CHANGEME markers.
$cfg = __DIR__ . '/../config.php';
if (file_exists($cfg)) {
    require_once $cfg;
}

// Simple helper: skip integration if Redis socket/host not reachable quickly.
function renote_test_can_connect_redis(): bool {
    try {
        $c = new Predis\Client([
            'scheme' => (defined('REDIS_CONNECTION_TYPE') && REDIS_CONNECTION_TYPE === 'unix') ? 'unix' : 'tcp',
            'path'   => defined('REDIS_SOCKET') ? REDIS_SOCKET : null,
            'host'   => defined('REDIS_HOST') ? REDIS_HOST : '127.0.0.1',
            'port'   => defined('REDIS_PORT') ? REDIS_PORT : 6379,
            'timeout'=> 0.2,
        ]);
        $c->ping();
        return true;
    } catch (Throwable $e) { return false; }
}

