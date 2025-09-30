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
        if (defined('REDIS_USERNAME') && REDIS_USERNAME) $params['username'] = REDIS_USERNAME;
        if (defined('REDIS_PASSWORD') && REDIS_PASSWORD) $params['password'] = REDIS_PASSWORD;
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
                $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = defined('MYSQL_SSL_VERIFY_SERVER_CERT') ? MYSQL_SSL_VERIFY_SERVER_CERT : false;
                if (defined('MYSQL_SSL_CA') && file_exists(MYSQL_SSL_CA)) {
                    $options[PDO::MYSQL_ATTR_SSL_CA] = MYSQL_SSL_CA;
                }
            }
        }
        $pdo = new PDO($dsn, MYSQL_USER, MYSQL_PASS, $options);
    }
    return $pdo;
}

function load_state() {
    $redis = redis_client();
    $cards = [];
    $cardIds = $redis->zrange(REDIS_INDEX_KEY, 0, -1);

    if (empty($cardIds)) {
        // Hydrate from DB if Redis is empty (include name column)
        $stmt = db()->query("SELECT id, name, txt, `order`, updated_at FROM cards ORDER BY `order` ASC");
        $dbCards = $stmt->fetchAll();
        if (empty($dbCards)) {
             return ['cards' => [], 'updated_at' => 0];
        }
        $pipe = $redis->pipeline();
        foreach ($dbCards as $card) {
            $pipe->hmset("card:{$card['id']}", [
              'name' => $card['name'] ?? '',
              'text' => $card['txt'],
              'txt'  => $card['txt'],
              'order' => $card['order'],
              'updated_at' => $card['updated_at']
            ]);
            $pipe->zadd(REDIS_INDEX_KEY, [$card['id'] => $card['order']]);
            $cards[] = [
                'id' => $card['id'],
                'name' => $card['name'] ?? '',
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
                'name' => $cardData['name'] ?? '',
                'text' => $textVal,
                'order' => (int)($cardData['order'] ?? 0),
                'updated_at' => (int)($cardData['updated_at'] ?? 0),
            ];
        }
    }
    $updated_at = (int)$redis->get(REDIS_UPDATED_AT);
    return ['cards' => $cards, 'updated_at' => $updated_at];
}

function redis_upsert_card($id, $text, $order, $name='') {
  $redis = redis_client();
  $updated_at = time();
  $pipe = $redis->pipeline();
  $pipe->hmset("card:$id", [
    'name' => (string)$name,
    'text' => $text,
    'txt'  => $text,
    'order' => $order,
    'updated_at' => $updated_at
  ]);
  $pipe->zadd(REDIS_INDEX_KEY, [$id => $order]);
  $pipe->set(REDIS_UPDATED_AT, $updated_at);
  $pipe->incr('metrics:saves');
  $pipe->execute();

  if (defined('APP_WRITE_BEHIND') && APP_WRITE_BEHIND) {
    try {
      $redis->executeRaw(['XADD', REDIS_STREAM, '*',
        'id', $id, 'name', (string)$name, 'text', $text,
        'order', (string)$order, 'updated_at', (string)$updated_at
      ]);
    } catch (Throwable $e) { _db_upsert_card($id, $name, $text, $order, $updated_at); }
  } else {
    _db_upsert_card($id, $name, $text, $order, $updated_at);
  }
  return $updated_at;
}

function delete_card_everywhere($id) {
    $redis = redis_client();
    $pipe = $redis->pipeline();
    $pipe->zrem(REDIS_INDEX_KEY, $id);
    $pipe->del("card:$id");
    $pipe->execute();
    try {
        $stmt = db()->prepare("DELETE FROM cards WHERE id = ?");
        $stmt->execute([$id]);
    } catch (PDOException $e) {
        error_log("Failed to delete card $id from DB: " . $e->getMessage());
    }
}

function delete_card_redis_only($id) {
  $r = redis_client();
  $pipe = $r->pipeline();
  $pipe->zrem(REDIS_INDEX_KEY, $id);
  $pipe->del("card:$id");
  $pipe->incr('metrics:deletes');
  $pipe->execute();
  if (defined('APP_WRITE_BEHIND') && APP_WRITE_BEHIND) {
    try { $r->executeRaw(['XADD', REDIS_STREAM, '*', 'id', $id, 'op', 'del', 'ts', (string)time()]); } catch (Throwable $e) {}
  }
}

function metrics_snapshot(): array {
  $r = redis_client();
  try {
    $vals = $r->mget(['metrics:saves','metrics:deletes']);
    return [
      'saves' => (int)($vals[0] ?? 0),
      'deletes' => (int)($vals[1] ?? 0)
    ];
  } catch (Throwable $e) { return ['saves'=>0,'deletes'=>0]; }
}

// Internal helper for direct DB upsert (used by worker or fallback)
function _db_upsert_card($id, $name, $text, $order, $updated_at) {
    try {
        $stmt = db()->prepare(
          "INSERT INTO cards (id, name, txt, `order`, updated_at)
           VALUES (:id,:name,:txt,:order,:updated_at)
           ON DUPLICATE KEY UPDATE
             name=VALUES(name), txt=VALUES(txt), `order`=VALUES(`order`), updated_at=VALUES(updated_at)"
        );
        $stmt->execute([
            ':id' => $id,
            ':name'=> $name,
            ':txt' => $text,
            ':order' => $order,
            ':updated_at' => $updated_at
        ]);
    } catch (PDOException $e) { error_log("DB upsert failed for $id: " . $e->getMessage()); }
}

function _db_delete_card($id) {
  $stmt = db()->prepare("DELETE FROM cards WHERE id=?");
  $stmt->execute([$id]);
}

if (!defined('APP_PRUNE_EMPTY')) define('APP_PRUNE_EMPTY', true);
if (!defined('APP_EMPTY_MINLEN')) define('APP_EMPTY_MINLEN', 1);

function safe_json_for_script($value): string {
  return json_encode($value, JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);
}

function db_orphans(): array {
  $pdo = db();
  $r = redis_client();
  $redisIds = $r->zrange(REDIS_INDEX_KEY, 0, -1) ?: [];
  $in = $redisIds ? str_repeat('?,', count($redisIds)-1).'?' : null;
  if (!$in) {
    $stmt = $pdo->query("SELECT id, name, txt, `order`, updated_at FROM cards ORDER BY updated_at DESC LIMIT 500");
    return $stmt->fetchAll();
  }
  $stmt = $pdo->prepare("SELECT id, name, txt, `order`, updated_at FROM cards WHERE id NOT IN ($in) ORDER BY updated_at DESC LIMIT 500");
  $stmt->execute($redisIds);
  return $stmt->fetchAll();
}

function card_validate_id_and_text(string $id, string $text): void {
  if (defined('APP_REQUIRE_UUID') && APP_REQUIRE_UUID) {
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $id)) {
      throw new InvalidArgumentException('invalid_id_format');
    }
  } else {
    if (!preg_match('/^([0-9a-f]{16,64}|[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})$/i', $id)) {
      throw new InvalidArgumentException('invalid_id_format');
    }
  }
  if (defined('APP_CARD_MAX_LEN') && mb_strlen($text) > APP_CARD_MAX_LEN) {
    throw new LengthException('text_too_long');
  }
}

if (!function_exists('card_repository')) {
  function card_repository() { return new \Renote\Domain\CardRepository(); }
}
