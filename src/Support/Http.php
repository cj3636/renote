<?php
namespace Renote\Support;

if (!function_exists(__NAMESPACE__ . '\\json_input')) {
    function json_input(): array {
        static $cache = null;
        if ($cache !== null) return $cache;
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') { $cache = []; return $cache; }
        $data = json_decode($raw, true);
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            fail('invalid_json', 400);
        }
        if (!is_array($data)) { $cache = []; return $cache; }
        $cache = $data; return $cache;
    }
}

if (!function_exists(__NAMESPACE__ . '\\ok')) {
    function ok(array $data = []): void { header('Content-Type: application/json'); echo json_encode(['ok'=>true]+$data); exit; }
}
if (!function_exists(__NAMESPACE__ . '\\fail')) {
    function fail(string $msg, int $code=400): void { http_response_code($code); header('Content-Type: application/json'); echo json_encode(['ok'=>false,'error'=>$msg]); exit; }
}

if (!function_exists(__NAMESPACE__ . '\\rl_check')) {
    function rl_check(string $actionKey): void {
        if (!defined('APP_RATE_LIMIT_MAX')) return;
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'ip:unknown';
        $bucket = (int)(time() / APP_RATE_LIMIT_WINDOW);
        $key = "rl:$ip:$bucket";
        try {
            $r = redis_client();
            $count = $r->incr($key);
            if ($count === 1) { $r->expire($key, APP_RATE_LIMIT_WINDOW); }
            if ($count > APP_RATE_LIMIT_MAX) { fail('rate_limited', 429); }
        } catch (\Throwable $e) { /* soft fail */ }
    }
}
