<?php
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/config.php';

use Predis\Client as PredisClient;

function redis_client(): PredisClient {
  static $r = null;
  if ($r === null) {
    $params = ['scheme' => 'unix', 'path' => REDIS_SOCKET];
    $opts   = ['parameters' => []];
    if (REDIS_USERNAME !== null && REDIS_USERNAME !== '') {
      $opts['parameters']['username'] = REDIS_USERNAME;
    }
    if (REDIS_PASSWORD !== null && REDIS_PASSWORD !== '') {
      $opts['parameters']['password'] = REDIS_PASSWORD;
    }
    $r = new PredisClient($params, $opts);

    // Quick sanity check; throws if AUTH fails
    $r->ping();
  }
  return $r;
}

function db(): PDO {
  static $pdo = null;
  if ($pdo) return $pdo;

  if (defined('MYSQL_USE_SOCKET') && MYSQL_USE_SOCKET) {
    // Secure: local UNIX socket (no TCP)
    $dsn = sprintf('mysql:unix_socket=%s;dbname=%s;charset=utf8mb4', MYSQL_SOCKET, MYSQL_DB);
    $pdo = new PDO($dsn, MYSQL_USER, MYSQL_PASS, PDO_COMMON);
    return $pdo;
  }

  // Fallback: TCP with TLS
  $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', MYSQL_HOST, MYSQL_PORT, MYSQL_DB);
  $opts = PDO_COMMON + [
    PDO::MYSQL_ATTR_SSL_CA   => MYSQL_SSL_CA,
    // The following are optional if server does NOT require client certs:
    // PDO::MYSQL_ATTR_SSL_CERT => MYSQL_SSL_CERT,
    // PDO::MYSQL_ATTR_SSL_KEY  => MYSQL_SSL_KEY,
  ];
  // (Optional) strictly verify CN if your server has a proper cert:
  // $opts[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;

  $pdo = new PDO($dsn, MYSQL_USER, MYSQL_PASS, $opts);
  return $pdo;
}

/** Read full state from Redis; if empty, hydrate from MariaDB. */
function load_state(): array {
  $r = redis_client();
  $ids = $r->smembers(REDIS_INDEX_KEY);
  $cards = [];

  if (!empty($ids)) {
    foreach ($ids as $id) {
      $h = $r->hgetall("card:$id");
      if (!$h) continue;
      $cards[] = [
        'id'         => $id,
        'text'       => $h['text']       ?? '',
        'order'      => intval($h['order'] ?? 0),
        'updated_at' => intval($h['updated_at'] ?? 0),
      ];
    }
    usort($cards, fn($a,$b)=>($a['order']<=>$b['order']));
    $updated_at = intval($r->get(REDIS_UPDATED_AT) ?: time());
    return ['cards'=>$cards, 'updated_at'=>$updated_at];
  }

  // Redis empty → read from DB and hydrate Redis
  $pdo = db();
  $rows = $pdo->query('SELECT id, txt, `order`, updated_at FROM cards ORDER BY `order` ASC')->fetchAll();
  if (!$rows) return ['cards'=>[], 'updated_at'=>time()];

  $maxUpdated = 0;
  foreach ($rows as $row) {
    $id = $row['id'];
    $data = [
      'text'       => $row['txt'],
      'order'      => (string)($row['`order`'] ?? $row['order']),
      'updated_at' => (string)($row['updated_at']),
    ];
    redis_client()->hmset("card:$id", $data);
    redis_client()->sadd(REDIS_INDEX_KEY, $id);
    $maxUpdated = max($maxUpdated, (int)$row['updated_at']);
  }
  redis_client()->set(REDIS_UPDATED_AT, $maxUpdated ?: time());

  // Return same shape
  $cards = array_map(function($row){
    return [
      'id'         => $row['id'],
      'text'       => $row['txt'],
      'order'      => (int)$row['`order`'] ?? (int)$row['order'],
      'updated_at' => (int)$row['updated_at'],
    ];
  }, $rows);

  return ['cards'=>$cards, 'updated_at'=>$maxUpdated ?: time()];
}

/** Upsert one card into Redis and emit a stream event. */
function redis_upsert_card(string $id, string $text, int $order): int {
  $ts = time();
  $r  = redis_client();
  $r->hmset("card:$id", ['text'=>$text, 'order'=>(string)$order, 'updated_at'=>(string)$ts]);
  $r->sadd(REDIS_INDEX_KEY, $id);
  $r->set(REDIS_UPDATED_AT, (string)$ts);
  // Queue to stream for the flusher
  $r->xadd(REDIS_STREAM, '*', ['id'=>$id, 'ts'=>(string)$ts]);
  return $ts;
}

/** Delete a card from Redis (and DB immediately for simplicity). */
function delete_card_everywhere(string $id): void {
  $r = redis_client();
  $r->srem(REDIS_INDEX_KEY, $id);
  $r->del("card:$id");
  $r->set(REDIS_UPDATED_AT, (string)time());

  // Also delete in DB right away (keeps DB lean even if worker lags)
  $pdo = db();
  $stmt = $pdo->prepare('DELETE FROM cards WHERE id = ?');
  $stmt->execute([$id]);
}
