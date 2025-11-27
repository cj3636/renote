<?php

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config.php';

/**
 * Normalize category id (null/empty -> root).
 */
function normalize_category_id(?string $categoryId): string
{
    $categoryId = trim((string)$categoryId);
    return $categoryId === '' ? 'root' : $categoryId;
}

function category_index_key(string $categoryId): string
{
    return normalize_category_id($categoryId) === 'root'
        ? REDIS_INDEX_KEY
        : 'cat:' . normalize_category_id($categoryId) . ':cards';
}

/**
 * Shared Redis client (lazy singleton). Prefers UNIX sockets when configured.
 */
function redis_client()
{
    static $redis = null;
    if ($redis === null) {
        $params = (defined('REDIS_CONNECTION_TYPE') && REDIS_CONNECTION_TYPE === 'unix')
            ? ['scheme' => 'unix', 'path' => REDIS_SOCKET]
            : ['scheme' => 'tcp', 'host' => REDIS_HOST, 'port' => REDIS_PORT];
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

/**
 * Whether the cards table has the category_id column (cached).
 */
function db_supports_categories(): bool
{
    static $has = null;
    if ($has !== null) return $has;
    try {
        $stmt = db()->query("SHOW COLUMNS FROM cards LIKE 'category_id'");
        $has = (bool)$stmt->fetch();
    } catch (Throwable $e) {
        $has = false;
    }
    return $has;
}

/**
 * Ensure categories table exists (no-op if already present).
 */
function ensure_categories_table(): void
{
    static $done = false; if ($done) return; $pdo = db();
    try { $pdo->query("SELECT 1 FROM categories LIMIT 1"); $done = true; return; } catch (Throwable $e) {}
    $sql = "CREATE TABLE IF NOT EXISTS categories (
        id VARCHAR(64) PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        `order` INT NOT NULL DEFAULT 0,
        updated_at BIGINT NOT NULL
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    try { $pdo->exec($sql); } catch (Throwable $e) { error_log('Failed creating categories table: '.$e->getMessage()); }
    $done = true;
}

/**
 * PDO connection (cached). Applies TLS options when provided for TCP connections.
 */
function db()
{
    static $pdo = null;
    if ($pdo === null) {
        $options = PDO_COMMON;
        if (defined('MYSQL_USE_SOCKET') && MYSQL_USE_SOCKET) {
            $dsn = 'mysql:unix_socket=' . MYSQL_SOCKET . ';dbname=' . MYSQL_DB;
        } else {
            $dsn = 'mysql:host=' . MYSQL_HOST . ';port=' . MYSQL_PORT . ';dbname=' . MYSQL_DB;
            if (defined('MYSQL_SSL_ENABLE') && MYSQL_SSL_ENABLE) {
                $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = defined('MYSQL_SSL_VERIFY_SERVER_CERT') ? MYSQL_SSL_VERIFY_SERVER_CERT : false;
                if (defined('MYSQL_SSL_CA') && MYSQL_SSL_CA && file_exists(MYSQL_SSL_CA)) {
                    $options[PDO::MYSQL_ATTR_SSL_CA] = MYSQL_SSL_CA;
                }
                if (defined('PDO_MYSQL_SSL_CIPHER') && PDO_MYSQL_SSL_CIPHER) {
                    $options[PDO::MYSQL_ATTR_SSL_CIPHER] = PDO_MYSQL_SSL_CIPHER;
                }
                if (defined('MYSQL_SSL_CRT') && MYSQL_SSL_CRT && file_exists(MYSQL_SSL_CRT)) {
                    $options[PDO::MYSQL_ATTR_SSL_CERT] = MYSQL_SSL_CRT;
                }
                if (defined('MYSQL_SSL_KEY') && MYSQL_SSL_KEY && file_exists(MYSQL_SSL_KEY)) {
                    $options[PDO::MYSQL_ATTR_SSL_KEY] = MYSQL_SSL_KEY;
                }
            }
        }
        $pdo = new PDO($dsn, MYSQL_USER, MYSQL_PASS, $options);
    }
    return $pdo;
}

/**
 * Current canonical state from Redis; hydrates Redis from DB if empty.
 * Returns categories (excluding implicit root) and cards (with category_id).
 */
function load_state(): array
{
    $redis = redis_client();
    $cards = [];
    $categories = [];

    // ---- Categories ----
    $categoryIds = $redis->zrange(REDIS_CATEGORIES_INDEX, 0, -1) ?: [];
    if ($categoryIds) {
        $pipe = $redis->pipeline();
        foreach ($categoryIds as $cid) { $pipe->hgetall(REDIS_CATEGORY_PREFIX . $cid); }
        $catData = $pipe->execute();
        foreach ($catData as $idx => $data) {
            if (!$data) continue;
            $categories[] = [
                'id' => $categoryIds[$idx],
                'name' => $data['name'] ?? '',
                'order' => (int)($data['order'] ?? 0),
                'updated_at' => (int)($data['updated_at'] ?? 0),
            ];
        }
    } else {
        // Hydrate categories from DB if present
        try {
            ensure_categories_table();
            $rows = db()->query("SELECT id, name, `order`, updated_at FROM categories ORDER BY `order` ASC")->fetchAll();
            if ($rows) {
                $pipe = $redis->pipeline();
                foreach ($rows as $row) {
                    $categories[] = [
                        'id' => $row['id'],
                        'name' => $row['name'],
                        'order' => (int)$row['order'],
                        'updated_at' => (int)$row['updated_at'],
                    ];
                    $pipe->hmset(REDIS_CATEGORY_PREFIX . $row['id'], [
                        'name' => $row['name'],
                        'order' => $row['order'],
                        'updated_at' => $row['updated_at'],
                    ]);
                    $pipe->zadd(REDIS_CATEGORIES_INDEX, [$row['id'] => $row['order']]);
                }
                $pipe->execute();
                $categoryIds = array_column($rows, 'id');
            }
        } catch (Throwable $e) {
            // ignore
        }
    }

    // ---- Cards ----
    $cardIdsByCategory = [];
    $cardIdsByCategory['root'] = $redis->zrange(REDIS_INDEX_KEY, 0, -1) ?: [];
    foreach ($categoryIds as $cid) {
        $cardIdsByCategory[$cid] = $redis->zrange(category_index_key($cid), 0, -1) ?: [];
    }
    $allIds = [];
    foreach ($cardIdsByCategory as $ids) { $allIds = array_merge($allIds, $ids); }

    if (empty($allIds)) {
        // Hydrate from DB if Redis is empty (include category if available)
        $selectCols = db_supports_categories()
            ? "id, name, category_id, txt, `order`, updated_at"
            : "id, name, txt, `order`, updated_at";
        $stmt = db()->query("SELECT $selectCols FROM cards ORDER BY `order` ASC");
        $dbCards = $stmt->fetchAll();
        if (empty($dbCards)) {
            return ['cards' => [], 'categories' => $categories, 'updated_at' => 0];
        }
        $pipe = $redis->pipeline();
        foreach ($dbCards as $card) {
            $cat = db_supports_categories() ? normalize_category_id($card['category_id'] ?? 'root') : 'root';
            $pipe->hmset("card:{$card['id']}", [
              'name' => $card['name'] ?? '',
              'text' => $card['txt'],
              'txt'  => $card['txt'],
              'order' => $card['order'],
              'updated_at' => $card['updated_at'],
              'category_id' => $cat,
            ]);
            $pipe->zadd(category_index_key($cat), [$card['id'] => $card['order']]);
            $cards[] = [
                'id' => $card['id'],
                'name' => $card['name'] ?? '',
                'text' => $card['txt'],
                'order' => (int)$card['order'],
                'updated_at' => (int)$card['updated_at'],
                'category_id' => $cat,
            ];
        }
        $pipe->execute();
        $updated_at = max(array_column($dbCards, 'updated_at'));
        $redis->set(REDIS_UPDATED_AT, $updated_at);
        usort($categories, function($a,$b){ return ($a['order'] ?? 0) <=> ($b['order'] ?? 0); });
        return ['cards' => $cards, 'categories' => $categories, 'updated_at' => $updated_at];
    }

    $pipe = $redis->pipeline();
    foreach ($allIds as $id) { $pipe->hgetall("card:$id"); }
    $cardDataList = $pipe->execute();
    $idx = 0;
    foreach ($cardIdsByCategory as $categoryId => $ids) {
        foreach ($ids as $id) {
            $cardData = $cardDataList[$idx++] ?? [];
            if ($cardData) {
                $textVal = $cardData['text'] ?? ($cardData['txt'] ?? '');
                $cards[] = [
                    'id' => $id,
                    'name' => $cardData['name'] ?? '',
                    'text' => $textVal,
                    'order' => (int)($cardData['order'] ?? 0),
                    'updated_at' => (int)($cardData['updated_at'] ?? 0),
                    'category_id' => normalize_category_id($cardData['category_id'] ?? $categoryId),
                ];
            }
        }
    }
    usort($categories, function($a,$b){ return ($a['order'] ?? 0) <=> ($b['order'] ?? 0); });
    $updated_at = (int)$redis->get(REDIS_UPDATED_AT);
    return ['cards' => $cards, 'categories' => $categories, 'updated_at' => $updated_at];
}

/**
 * Persist card into Redis (and optionally enqueue stream for write-behind).
 */
function redis_upsert_card(string $id, string $text, int $order, string $name = '', ?string $categoryId = 'root'): int
{
  $redis = redis_client();
  $categoryId = normalize_category_id($categoryId);
  $existing = $redis->hgetall("card:$id");
  $prevCategory = normalize_category_id($existing['category_id'] ?? $categoryId);
  $updated_at = time();
  $pipe = $redis->pipeline();
  $pipe->hmset("card:$id", [
    'name' => (string)$name,
    'text' => $text,
    'txt'  => $text,
    'order' => $order,
    'updated_at' => $updated_at,
    'category_id' => $categoryId,
  ]);
  $pipe->zadd(category_index_key($categoryId), [$id => $order]);
  if ($prevCategory !== $categoryId) {
    $pipe->zrem(category_index_key($prevCategory), $id);
  }
  $pipe->set(REDIS_UPDATED_AT, $updated_at);
  $pipe->incr('metrics:saves');
  $pipe->execute();

  if (defined('APP_WRITE_BEHIND') && APP_WRITE_BEHIND) {
    try {
      $redis->executeRaw(['XADD', REDIS_STREAM, '*',
        'id', $id, 'name', (string)$name, 'text', $text,
        'order', (string)$order, 'category_id', $categoryId, 'updated_at', (string)$updated_at
      ]);
    } catch (Throwable $e) { _db_upsert_card($id, $name, $text, $order, $updated_at, $categoryId); }
  } else {
    _db_upsert_card($id, $name, $text, $order, $updated_at, $categoryId);
  }
  return $updated_at;
}

/**
 * Create or update a category (synchronous DB write).
 */
function redis_upsert_category(string $id, string $name, int $order): int
{
  $id = normalize_category_id($id);
  if ($id === 'root') return time(); // root is implicit
  $r = redis_client();
  $updated_at = time();
  $pipe = $r->pipeline();
  $pipe->hmset(REDIS_CATEGORY_PREFIX . $id, [
    'name' => $name,
    'order' => $order,
    'updated_at' => $updated_at,
  ]);
  $pipe->zadd(REDIS_CATEGORIES_INDEX, [$id => $order]);
  $pipe->set(REDIS_UPDATED_AT, $updated_at);
  $pipe->execute();

  try {
    ensure_categories_table();
    $stmt = db()->prepare(
      "INSERT INTO categories (id, name, `order`, updated_at)
       VALUES (:id,:name,:order,:updated_at)
       ON DUPLICATE KEY UPDATE name=VALUES(name), `order`=VALUES(`order`), updated_at=VALUES(updated_at)"
    );
    $stmt->execute([
      ':id' => $id,
      ':name' => $name,
      ':order' => $order,
      ':updated_at' => $updated_at,
    ]);
  } catch (Throwable $e) { error_log('DB upsert category failed: '.$e->getMessage()); }

  return $updated_at;
}

function category_card_count(string $categoryId): int
{
  $categoryId = normalize_category_id($categoryId);
  $redisCount = 0;
  try { $redisCount = (int)redis_client()->zcard(category_index_key($categoryId)); } catch (Throwable $e) {}
  $dbCount = 0;
  if (db_supports_categories()) {
    try {
      $stmt = db()->prepare("SELECT COUNT(*) AS c FROM cards WHERE category_id = ?");
      $stmt->execute([$categoryId]);
      $row = $stmt->fetch();
      $dbCount = (int)($row['c'] ?? 0);
    } catch (Throwable $e) {}
  }
  return max($redisCount, $dbCount);
}

function delete_category(string $categoryId): bool
{
  $categoryId = normalize_category_id($categoryId);
  if ($categoryId === 'root') return false;
  if (category_card_count($categoryId) > 0) return false;
  $r = redis_client();
  $pipe = $r->pipeline();
  $pipe->zrem(REDIS_CATEGORIES_INDEX, $categoryId);
  $pipe->del(REDIS_CATEGORY_PREFIX . $categoryId);
  $pipe->del(category_index_key($categoryId));
  $pipe->execute();
  try {
    ensure_categories_table();
    $stmt = db()->prepare("DELETE FROM categories WHERE id = ?");
    $stmt->execute([$categoryId]);
  } catch (Throwable $e) { error_log('DB delete category failed: '.$e->getMessage()); }
  return true;
}

function delete_card_everywhere(string $id): void
{
    $redis = redis_client();
    $catId = 'root';
    try { $data = $redis->hgetall("card:$id"); if ($data && isset($data['category_id'])) $catId = normalize_category_id($data['category_id']); } catch (Throwable $e) {}
    $pipe = $redis->pipeline();
    $pipe->zrem(category_index_key($catId), $id);
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
  $catId = 'root';
  try { $data = $r->hgetall("card:$id"); if ($data && isset($data['category_id'])) $catId = normalize_category_id($data['category_id']); } catch (Throwable $e) {}
  $pipe = $r->pipeline();
  $pipe->zrem(category_index_key($catId), $id);
  $pipe->del("card:$id");
  $pipe->incr('metrics:deletes');
  $pipe->execute();
  if (defined('APP_WRITE_BEHIND') && APP_WRITE_BEHIND) {
    try { $r->executeRaw(['XADD', REDIS_STREAM, '*', 'id', $id, 'op', 'del', 'ts', (string)time(), 'category_id', $catId]); } catch (Throwable $e) {}
  }
}

function metrics_snapshot(): array
{
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
function _db_upsert_card(string $id, string $name, string $text, int $order, int $updated_at, string $categoryId = 'root'): void
{
    try {
        $hasCategory = db_supports_categories();
        if ($hasCategory) {
            $stmt = db()->prepare(
              "INSERT INTO cards (id, name, category_id, txt, `order`, updated_at)
               VALUES (:id,:name,:category_id,:txt,:order,:updated_at)
               ON DUPLICATE KEY UPDATE
                 name=VALUES(name), category_id=VALUES(category_id), txt=VALUES(txt), `order`=VALUES(`order`), updated_at=VALUES(updated_at)"
            );
            $stmt->execute([
                ':id' => $id,
                ':name'=> $name,
                ':category_id' => normalize_category_id($categoryId),
                ':txt' => $text,
                ':order' => $order,
                ':updated_at' => $updated_at
            ]);
        } else {
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
        }
    } catch (PDOException $e) { error_log("DB upsert failed for $id: " . $e->getMessage()); }
}

function _db_delete_card(string $id): void
{
  $stmt = db()->prepare("DELETE FROM cards WHERE id=?");
  $stmt->execute([$id]);
}

function safe_json_for_script($value): string
{
  return json_encode($value, JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);
}

function db_orphans(): array
{
  $pdo = db();
  $r = redis_client();
  $redisIds = $r->zrange(REDIS_INDEX_KEY, 0, -1) ?: [];
  try {
    $catIds = $r->zrange(REDIS_CATEGORIES_INDEX, 0, -1) ?: [];
    foreach ($catIds as $cid) {
      $ids = $r->zrange(category_index_key($cid), 0, -1) ?: [];
      $redisIds = array_merge($redisIds, $ids);
    }
  } catch (Throwable $e) {}
  $in = $redisIds ? str_repeat('?,', count($redisIds)-1).'?' : null;
  if (!$in) {
    $cols = db_supports_categories() ? "id, name, category_id, txt, `order`, updated_at" : "id, name, txt, `order`, updated_at";
    $stmt = $pdo->query("SELECT $cols FROM cards ORDER BY updated_at DESC LIMIT 500");
    return $stmt->fetchAll();
  }
  $cols = db_supports_categories() ? "id, name, category_id, txt, `order`, updated_at" : "id, name, txt, `order`, updated_at";
  $stmt = $pdo->prepare("SELECT $cols FROM cards WHERE id NOT IN ($in) ORDER BY updated_at DESC LIMIT 500");
  $stmt->execute($redisIds);
  return $stmt->fetchAll();
}

function card_validate_id_and_text(string $id, string $text): void
{
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

/** Ensure version table exists (lazy) */
function ensure_versions_table(): void
{
  static $done = false; if ($done) return; $pdo = db();
  try { $pdo->query("SELECT 1 FROM card_versions LIMIT 1"); $done = true; return; } catch (Throwable $e) {}
  $sql = "CREATE TABLE IF NOT EXISTS card_versions (\n    version_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,\n    card_id VARCHAR(64) NOT NULL,\n    name VARCHAR(255) NULL,\n    txt MEDIUMTEXT NOT NULL,\n    `order` INT NOT NULL DEFAULT 0,\n    captured_at BIGINT NOT NULL,\n    origin ENUM('flush','manual','restore') NOT NULL DEFAULT 'flush',\n    KEY idx_card_time (card_id, captured_at DESC)\n  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
  try { $pdo->exec($sql); } catch (Throwable $e) { error_log('Failed creating card_versions: '.$e->getMessage()); }
  $done = true;
}

/** Fetch last version meta for card */
function version_last(string $cardId): ?array
{
  ensure_versions_table();
  $pdo = db();
  $stmt = $pdo->prepare("SELECT version_id, captured_at, CHAR_LENGTH(txt) AS len, txt FROM card_versions WHERE card_id=? ORDER BY captured_at DESC LIMIT 1");
  $stmt->execute([$cardId]);
  $row = $stmt->fetch();
  return $row ?: null;
}

/** Insert a version snapshot if policy allows */
function version_insert(string $cardId, string $name, string $text, int $order, string $origin = 'flush', bool $force = false): bool
{
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

function versions_list(string $cardId, int $limit = 25): array
{
  ensure_versions_table();
  $limit = max(1, min(200, $limit));
  $stmt = db()->prepare("SELECT version_id, captured_at, name, `order`, CHAR_LENGTH(txt) AS size, origin FROM card_versions WHERE card_id=? ORDER BY captured_at DESC LIMIT $limit");
  $stmt->execute([$cardId]);
  return $stmt->fetchAll();
}

function version_get_full(int $versionId): ?array
{
  ensure_versions_table();
  $stmt = db()->prepare("SELECT version_id, card_id, name, txt, `order`, captured_at, origin FROM card_versions WHERE version_id=?");
  $stmt->execute([$versionId]);
  $row = $stmt->fetch();
  return $row ?: null;
}

function version_restore(int $versionId): bool
{
  $row = version_get_full($versionId); if (!$row) return false;
  $catId = 'root';
  try {
    $existing = redis_client()->hgetall("card:{$row['card_id']}");
    if ($existing && isset($existing['category_id'])) {
      $catId = normalize_category_id($existing['category_id']);
    }
  } catch (Throwable $e) {}
  // Upsert into Redis & stream
  redis_upsert_card($row['card_id'], $row['txt'], (int)$row['order'], (string)($row['name'] ?? ''), $catId);
  // Record restore snapshot (forced)
  version_insert($row['card_id'], (string)$row['name'], $row['txt'], (int)$row['order'], 'restore', true);
  return true;
}

function version_snapshot_manual(string $cardId): bool
{
  $r = redis_client();
  $data = $r->hgetall("card:$cardId");
  if (!$data) return false;
  $name = $data['name'] ?? '';
  $text = $data['text'] ?? ($data['txt'] ?? '');
  $order = (int)($data['order'] ?? 0);
  return version_insert($cardId, $name, $text, $order, 'manual', true);
}
