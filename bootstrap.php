<?php

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config.php';

function redis_client() {
    static $redis = null;
    if ($redis === null) {
        $params = [];
        if (defined('REDIS_CONNECTION_TYPE') && REDIS_CONNECTION_TYPE === 'unix') {
            $params = [
                'scheme' => 'unix',
                'path'   => REDIS_SOCKET,
            ];
        } else {
            $params = [
                'scheme' => 'tcp',
                'host'   => REDIS_HOST,
                'port'   => REDIS_PORT,
            ];
        }

        // Add authentication parameters directly to the connection parameters array
        if (defined('REDIS_USERNAME') && REDIS_USERNAME) {
            $params['username'] = REDIS_USERNAME;
        }
        if (defined('REDIS_PASSWORD') && REDIS_PASSWORD) {
            $params['password'] = REDIS_PASSWORD;
        }
        
        $redis = new Predis\Client($params);
    }
    return $redis;
}

function db() {
    static $pdo = null;
    if ($pdo === null) {
        $options = PDO_COMMON;
        if (defined('MYSQL_USE_SOCKET') && MYSQL_USE_SOCKET) {
            $dsn = 'mysql:unix_socket=' . MYSQL_SOCKET . ';dbname=' . MYSQL_DB;
        } else {
            $dsn = 'mysql:host=' . MYSQL_HOST . ';port=' . MYSQL_PORT . ';dbname=' . MYSQL_DB;
            if (defined('MYSQL_SSL_ENABLE') && MYSQL_SSL_ENABLE) {
                // For local dev with self-signed certs, we might not want to verify the cert.
                // For production, this should be true.
                $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = defined('MYSQL_SSL_VERIFY_SERVER_CERT') ? MYSQL_SSL_VERIFY_SERVER_CERT : false;
                
                if (defined('MYSQL_SSL_CA') && file_exists(MYSQL_SSL_CA)) {
                    $options[PDO::MYSQL_ATTR_SSL_CA] = MYSQL_SSL_CA;
                }
            }
        }
        // The SSL options must be passed in the $options array of the PDO constructor.
        // Setting them here ensures they are applied for TCP connections.
        $pdo = new PDO($dsn, MYSQL_USER, MYSQL_PASS, $options);
    }
    return $pdo;
}

function load_state() {
    $redis = redis_client();
    $cards = [];
    $cardIds = $redis->zrange(REDIS_INDEX_KEY, 0, -1);

    if (empty($cardIds)) {
        // Hydrate from DB if Redis is empty
        $stmt = db()->query("SELECT id, txt, `order`, updated_at FROM cards ORDER BY `order` ASC");
        $dbCards = $stmt->fetchAll();
        
        if (empty($dbCards)) {
             return ['cards' => [], 'updated_at' => 0];
        }

        $pipe = $redis->pipeline();
        foreach ($dbCards as $card) {
            // Store using 'text' (primary) plus legacy 'txt' for backward compatibility
            $pipe->hmset("card:{$card['id']}", [
                'text' => $card['txt'],
                'txt' => $card['txt'],
                'order' => $card['order'],
                'updated_at' => $card['updated_at']
            ]);
            $pipe->zadd(REDIS_INDEX_KEY, [$card['id'] => $card['order']]);
            $cards[] = [
                'id' => $card['id'],
                'text' => $card['txt'],
                'order' => (int)$card['order'],
                'updated_at' => (int)$card['updated_at']
            ];
        }
        $pipe->execute();
        $updated_at = max(array_column($dbCards, 'updated_at'));
        $redis->set(REDIS_UPDATED_AT, $updated_at);
        return ['cards' => $cards, 'updated_at' => $updated_at];
    }

    $pipe = $redis->pipeline();
    foreach ($cardIds as $id) { $pipe->hgetall("card:$id"); }
    $cardDataList = $pipe->execute();

    foreach ($cardDataList as $index => $cardData) {
        if ($cardData) {
            $textVal = $cardData['text'] ?? ($cardData['txt'] ?? '');
            $cards[] = [
                'id' => $cardIds[$index],
                'text' => $textVal,
                'order' => (int)($cardData['order'] ?? 0),
                'updated_at' => (int)($cardData['updated_at'] ?? 0),
            ];
        }
    }
    $updated_at = (int)$redis->get(REDIS_UPDATED_AT);
    return ['cards' => $cards, 'updated_at' => $updated_at];
}

function redis_upsert_card($id, $text, $order) {
    $redis = redis_client();
    $updated_at = time();
    $pipe = $redis->pipeline();
    // Write both 'text' and legacy 'txt'
    $pipe->hmset("card:$id", [
        'text' => $text,
        'txt' => $text,
        'order' => $order,
        'updated_at' => $updated_at
    ]);
    $pipe->zadd(REDIS_INDEX_KEY, [$id => $order]);
    $pipe->set(REDIS_UPDATED_AT, $updated_at);
    $pipe->execute();

    // Write-behind via stream if enabled
    if (defined('APP_WRITE_BEHIND') && APP_WRITE_BEHIND) {
        try {
            // Use raw command to avoid version-specific signature mismatches
            $redis->executeRaw(['XADD', REDIS_STREAM, '*', 'id', $id, 'text', $text, 'order', (string)$order, 'updated_at', (string)$updated_at]);
        } catch (Throwable $e) {
            error_log('Stream append failed: ' . $e->getMessage());
            _db_upsert_card($id, $text, $order, $updated_at);
        }
    } else {
        // Write-through mode (no stream)
        _db_upsert_card($id, $text, $order, $updated_at);
    }

    return $updated_at;
}

function delete_card_everywhere($id) {
    $redis = redis_client();
    $pipe = $redis->pipeline();
    $pipe->zrem(REDIS_INDEX_KEY, $id);
    $pipe->del("card:$id");
    $pipe->execute();

    // Delete from the DB immediately.
    try {
        $stmt = db()->prepare("DELETE FROM cards WHERE id = ?");
        $stmt->execute([$id]);
    } catch (PDOException $e) {
        // Log the error but don't fail the request, as the card is gone from Redis.
        error_log("Failed to delete card $id from DB: " . $e->getMessage());
    }
}

// Internal helper for direct DB upsert (used by worker or fallback)
function _db_upsert_card($id, $text, $order, $updated_at) {
    try {
        $stmt = db()->prepare(
            "INSERT INTO cards (id, txt, `order`, updated_at) VALUES (:id, :txt, :order, :updated_at)
             ON DUPLICATE KEY UPDATE txt = VALUES(txt), `order` = VALUES(`order`), updated_at = VALUES(updated_at)"
        );
        $stmt->execute([
            ':id' => $id,
            ':txt' => $text,
            ':order' => $order,
            ':updated_at' => $updated_at
        ]);
    } catch (PDOException $e) { error_log("DB upsert failed for $id: " . $e->getMessage()); }
}

// Worker-facing: process one event (associative array fields) -> DB
function worker_flush_event(array $fields) {
    $required = ['id','text','order','updated_at'];
    foreach ($required as $r) { if (!isset($fields[$r])) return false; }
    _db_upsert_card($fields['id'], $fields['text'], (int)$fields['order'], (int)$fields['updated_at']);
    return true;
}