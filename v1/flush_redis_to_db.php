<?php
require __DIR__ . '/bootstrap.php';

$r   = redis_client();
$pdo = db();

function fail($msg) { fwrite(STDERR, $msg . PHP_EOL); exit(1); }

// Sanity: ensure Redis reachable & authed
try { $r->ping(); } catch (Throwable $e) { fail("Redis ping/auth failed: " . $e->getMessage()); }

$stream     = REDIS_STREAM;       // e.g. 'cards:stream'
$lastKey    = REDIS_STREAM_LAST;  // e.g. 'cards:stream:lastid'
$checkpoint = $r->get($lastKey) ?: '0-0';

/**
 * We’ll poll in non-blocking batches:
 * XREAD COUNT 1000 STREAMS <stream> <last-id>
 * No BLOCK => perfect for oneshot timers.
 */
$maxLoops = 5;   // process up to ~5000 entries per run; adjust as desired
$processedAny = false;

for ($loop = 0; $loop < $maxLoops; $loop++) {
  try {
    $resp = $r->executeRaw(['XREAD', 'COUNT', '1000', 'STREAMS', $stream, $checkpoint]);
  } catch (Throwable $e) {
    fail("XREAD failed: " . $e->getMessage());
  }

  if (!$resp || !isset($resp[0]) || !is_array($resp[0]) || count($resp[0]) < 2) {
    // No new entries
    break;
  }

  // RESP shape: [ [ streamName, [ [id, [field, val, ...]], ... ] ] ]
  $entries = $resp[0][1];
  if (!$entries || !is_array($entries)) break;

  $seen = [];
  $lastSeen = $checkpoint;

  foreach ($entries as $e) {
    $sid    = $e[0];     // stream id
    $fields = $e[1];     // flat [k, v, k, v, ...]
    $lastSeen = $sid;

    $assoc = [];
    for ($i = 0; $i < count($fields); $i += 2) {
      $assoc[(string)$fields[$i]] = $fields[$i+1] ?? null;
    }
    if (!empty($assoc['id'])) {
      $seen[(string)$assoc['id']] = true; // coalesce multiple updates per card id
    }
  }

  if ($seen) {
    $processedAny = true;
    $pdo->beginTransaction();

    $stmtUp = $pdo->prepare(
      'INSERT INTO cards (id, txt, `order`, updated_at)
       VALUES (?, ?, ?, ?)
       ON DUPLICATE KEY UPDATE
         txt = VALUES(txt),
         `order` = VALUES(`order`),
         updated_at = VALUES(updated_at)'
    );
    $stmtDel = $pdo->prepare('DELETE FROM cards WHERE id = ?');

    foreach (array_keys($seen) as $cid) {
      $h = $r->hgetall("card:$cid");
      if (!$h) {
        // Treat missing hash as delete
        $stmtDel->execute([$cid]);
        continue;
      }
      $stmtUp->execute([
        $cid,
        $h['text']        ?? '',
        (int)($h['order'] ?? 0),
        (int)($h['updated_at'] ?? time()),
      ]);
    }

    $pdo->commit();

    // Move checkpoint forward only after successful DB commit
    $checkpoint = $lastSeen;
    $r->set($lastKey, $checkpoint);
  }

  // If we got a full batch, loop again once or twice more; otherwise we’re done
  if (count($entries) < 1000) break;
}
