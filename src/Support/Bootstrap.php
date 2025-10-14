<?php

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config.php';

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

// Parse and cache JSON input body for POST/PUT requests.
if (!function_exists('json_input')) {
  function json_input(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') { $cache = []; return $cache; }
    $data = json_decode($raw, true);
    if (!is_array($data)) { $cache = []; return $cache; }
    $cache = $data; return $cache;
  }
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
  $maxLen = (defined('APP_CARD_MAX_LEN') && APP_CARD_MAX_LEN > 0) ? APP_CARD_MAX_LEN : 262144;
  if (mb_strlen($text) > $maxLen) {
    throw new LengthException('text_too_long');
  }
}

if (!function_exists('card_repository')) {
  function card_repository() { return new \Renote\Domain\CardRepository(); }
}

// ================= Versioning / Backups (card_versions) =================

if (!defined('APP_VERSION_MAX_PER_CARD')) define('APP_VERSION_MAX_PER_CARD', 25);
if (!defined('APP_VERSION_MIN_INTERVAL_SEC')) define('APP_VERSION_MIN_INTERVAL_SEC', 60);
if (!defined('APP_VERSION_MIN_SIZE_DELTA')) define('APP_VERSION_MIN_SIZE_DELTA', 20);
if (!defined('APP_VERSION_RETENTION_DAYS')) define('APP_VERSION_RETENTION_DAYS', 0); // 0 = disabled

/** Ensure version table exists (lazy) */
function ensure_versions_table(): void {
  static $done = false; if ($done) return; $pdo = db();
  try { $pdo->query("SELECT 1 FROM card_versions LIMIT 1"); $done = true; return; } catch (Throwable $e) {}
  $sql = "CREATE TABLE IF NOT EXISTS card_versions (\n    version_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,\n    card_id VARCHAR(64) NOT NULL,\n    name VARCHAR(255) NULL,\n    txt MEDIUMTEXT NOT NULL,\n    `order` INT NOT NULL DEFAULT 0,\n    captured_at BIGINT NOT NULL,\n    origin ENUM('flush','manual','restore') NOT NULL DEFAULT 'flush',\n    KEY idx_card_time (card_id, captured_at DESC)\n  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
  try { $pdo->exec($sql); } catch (Throwable $e) { error_log('Failed creating card_versions: '.$e->getMessage()); }
  $done = true;
}

/** Fetch last version meta for card */
function version_last(string $cardId): ?array {
  ensure_versions_table();
  $pdo = db();
  $stmt = $pdo->prepare("SELECT version_id, captured_at, CHAR_LENGTH(txt) AS len, txt FROM card_versions WHERE card_id=? ORDER BY captured_at DESC LIMIT 1");
  $stmt->execute([$cardId]);
  $row = $stmt->fetch();
  return $row ?: null;
}

/** Insert a version snapshot if policy allows */
function version_insert(string $cardId, string $name, string $text, int $order, string $origin='flush', bool $force=false): bool {
  ensure_versions_table();
  $now = time();
  $last = version_last($cardId);
  $should = $force;
  if (!$should && $last) {
    $age = $now - (int)$last['captured_at'];
    $delta = abs(strlen($text) - (int)$last['len']);
    if ($age >= APP_VERSION_MIN_INTERVAL_SEC || $delta >= APP_VERSION_MIN_SIZE_DELTA) $should = true;
  } elseif (!$last) { // always capture first
    $should = true;
  } else if (!$force) { $should = false; }
  if (!$should) return false;
  try {
    $pdo = db();
    $stmt = $pdo->prepare("INSERT INTO card_versions (card_id, name, txt, `order`, captured_at, origin) VALUES (?,?,?,?,?,?)");
    $stmt->execute([$cardId,$name,$text,$order,$now,$origin]);
    // Retention by count
    $max = (int)APP_VERSION_MAX_PER_CARD; if ($max > 0) {
      $pdo->prepare("DELETE FROM card_versions WHERE card_id=? AND version_id NOT IN (SELECT version_id FROM (SELECT version_id FROM card_versions WHERE card_id=? ORDER BY captured_at DESC LIMIT ?) t)")
          ->execute([$cardId,$cardId,$max]);
    }
    // Retention by age
    $days = (int)APP_VERSION_RETENTION_DAYS; if ($days > 0) {
      $cut = $now - ($days*86400);
      $pdo->prepare("DELETE FROM card_versions WHERE card_id=? AND captured_at < ?")->execute([$cardId,$cut]);
    }
    return true;
  } catch (Throwable $e) { error_log('version_insert failed: '.$e->getMessage()); return false; }
}

function versions_list(string $cardId, int $limit=25): array {
  ensure_versions_table();
  $limit = max(1, min(200, $limit));
  $stmt = db()->prepare("SELECT version_id, captured_at, name, `order`, CHAR_LENGTH(txt) AS size, origin FROM card_versions WHERE card_id=? ORDER BY captured_at DESC LIMIT $limit");
  $stmt->execute([$cardId]);
  return $stmt->fetchAll();
}

function version_get_full(int $versionId): ?array {
  ensure_versions_table();
  $stmt = db()->prepare("SELECT version_id, card_id, name, txt, `order`, captured_at, origin FROM card_versions WHERE version_id=?");
  $stmt->execute([$versionId]);
  $row = $stmt->fetch();
  return $row ?: null;
}

function version_restore(int $versionId): bool {
  $row = version_get_full($versionId); if (!$row) return false;
  // Upsert into Redis & stream
  redis_upsert_card($row['card_id'], $row['txt'], (int)$row['order'], (string)($row['name'] ?? ''));
  // Record restore snapshot (forced)
  version_insert($row['card_id'], (string)$row['name'], $row['txt'], (int)$row['order'], 'restore', true);
  return true;
}

function version_snapshot_manual(string $cardId): bool {
  $r = redis_client();
  $data = $r->hgetall("card:$cardId");
  if (!$data) return false;
  $name = $data['name'] ?? '';
  $text = $data['text'] ?? ($data['txt'] ?? '');
  $order = (int)($data['order'] ?? 0);
  return version_insert($cardId, $name, $text, $order, 'manual', true);
}
