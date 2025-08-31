<?php
// Worker: flush Redis stream events to MariaDB (write-behind persistence)
require_once __DIR__ . '/bootstrap.php';

if (!defined('APP_WRITE_BEHIND') || !APP_WRITE_BEHIND) {
    fwrite(STDERR, "Write-behind disabled. Enable APP_WRITE_BEHIND to use this worker.\n");
    exit(1);
}

$redis = redis_client();
$lastKey = REDIS_STREAM_LAST;
$stream  = REDIS_STREAM;

if (!$redis->exists($stream)) {
    // Create an empty stream marker (append a no-op event?) Not needed; will be auto-created.
}

$lastId = $redis->get($lastKey) ?: '0-0';
$once = true;
$quiet = true;
if (isset($argv)) {
    $once = in_array('--once', $argv, true);
    $quiet = in_array('--quiet', $argv, true);
}

$trimEvery = defined('APP_WORKER_TRIM_EVERY') ? APP_WORKER_TRIM_EVERY : 500;
$maxLen    = defined('APP_STREAM_MAXLEN') ? APP_STREAM_MAXLEN : (getenv('STREAM_MAXLEN') ? (int)getenv('STREAM_MAXLEN') : 5000);
$processedSinceTrim = 0;

$stats = [
  'upserts'=>0,
  'purges'=>0,        // deleted from DB
  'skipped_empty'=>0, // pruned by empty policy
  'seen'=>0
];
$FLUSH_QUEUE = [];

function logln($msg) { global $quiet; if (!$quiet) echo date('H:i:s') . " | $msg\n"; }

logln("Starting flush worker from ID $lastId (once=" . ($once?'yes':'no') . ")");

$running = true;
if (function_exists('pcntl_signal') && defined('PHP_OS_FAMILY') && PHP_OS_FAMILY !== 'Windows') {
    if (defined('SIGINT')) pcntl_signal(SIGINT, function() use (&$running){ $running=false; });
    if (defined('SIGTERM')) pcntl_signal(SIGTERM, function() use (&$running){ $running=false; });
}

function read_batch($redis, $stream, $lastId) {
    // Use XRANGE to fetch next batch after lastId (exclusive)
    $nextStart = $lastId === '0-0' ? '-' : '(' . $lastId; // '(' for exclusive
    try {
        $raw = $redis->executeRaw(['XRANGE', $stream, $nextStart, '+', 'COUNT', '200']);
        return $raw ?: [];
    } catch (Throwable $e) {
        error_log('XRANGE failed: ' . $e->getMessage());
        usleep(200000); // backoff
        return [];
    }
}

/**
 * Queue one event by card id. We only store the id and coalesce duplicates.
 */
function worker_flush_event(array $fields): bool {
    global $FLUSH_QUEUE;
    if (empty($fields['id'])) return false;
    $FLUSH_QUEUE[(string)$fields['id']] = true;

    // Opportunistic flush if queue grows large
    if (count($FLUSH_QUEUE) >= 500) {
        worker_commit_batch();
    }
    return true;
}

/**
 * Commit the current global queue to MariaDB:
 *  - Missing Redis hash => DELETE row
 *  - Empty text (policy) => DELETE row
 *  - Otherwise UPSERT (including `name`)
 */
function worker_commit_batch(): void {
    global $FLUSH_QUEUE, $stats;
    if (!$FLUSH_QUEUE) return;

    $r   = redis_client();
    $pdo = db();

    $ids = array_keys($FLUSH_QUEUE);
    $stats['seen'] += count($ids);

    $pdo->beginTransaction();

    // Include `name` if you added that column
    $stmtUp = $pdo->prepare(
        'INSERT INTO cards (id, name, txt, `order`, updated_at)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
           name = VALUES(name),
           txt = VALUES(txt),
           `order` = VALUES(`order`),
           updated_at = VALUES(updated_at)'
    );
    $stmtDel = $pdo->prepare('DELETE FROM cards WHERE id = ?');

    foreach ($ids as $cid) {
        $h = $r->hgetall("card:$cid");

        if (!$h) {
            $stmtDel->execute([$cid]);
            $stats['purges']++;
            continue;
        }

        $text       = (string)($h['text'] ?? '');
        $name       = (string)($h['name'] ?? '');
        $order      = (int)   ($h['order'] ?? 0);
        $updated_at = (int)   ($h['updated_at'] ?? time());

        if (APP_PRUNE_EMPTY && mb_strlen(trim($text)) < APP_EMPTY_MINLEN) {
            $stmtDel->execute([$cid]);
            $stats['skipped_empty']++;
            continue;
        }

        $stmtUp->execute([$cid, $name, $text, $order, $updated_at]);
        $stats['upserts']++;
    }

    $pdo->commit();

    // clear the queue now that this batch is persisted
    $FLUSH_QUEUE = [];
}

/**
 * Ensure any queued IDs are flushed now.
 * Ensure any deferred items are flushed at end of a batch/loop.
 */
function worker_commit_pending(): void {
    static $queueRef = null;

    // Access the static $queue inside worker_flush_event by reference
    $func = new ReflectionFunction('worker_flush_event');
    $staticVars = $func->getStaticVariables();
    if (isset($staticVars['queue']) && is_array($staticVars['queue'])) {
        $queueRef = $staticVars['queue'];
        if ($queueRef) {
            worker_commit_batch($queueRef);
            // Clear the static queue
            $r = new ReflectionFunction('worker_flush_event');
            $r->setStaticVariable('queue', []);
        }
    }
}

// ---- CONFIG KNOBS (or move these to config.php) ----
if (!defined('APP_PRUNE_EMPTY')) define('APP_PRUNE_EMPTY', true);     // true = delete rows whose text is empty
if (!defined('APP_EMPTY_MINLEN')) define('APP_EMPTY_MINLEN', 1);      // treat < this length as empty

while ($running) {
    if (function_exists('pcntl_signal_dispatch')) { pcntl_signal_dispatch(); }

    $entries = read_batch($redis, $stream, $lastId);
    if (empty($entries)) {
        if ($once) break; // nothing to do
        // Block briefly waiting for new events using XREAD BLOCK
        try {
            $blockMs = defined('APP_WORKER_BLOCK_MS') ? APP_WORKER_BLOCK_MS : 5000;
            $res = $redis->executeRaw(['XREAD', 'BLOCK', (string)$blockMs, 'COUNT', '100', 'STREAMS', $stream, $lastId]);
            if ($res && isset($res[0][1])) {
                // Structure: [ [ stream, [ [id, [field,val,...]], ... ] ] ]
                foreach ($res[0][1] as $ev) { $entries[] = $ev; }
            }
        } catch (Throwable $e) {
            error_log('XREAD failed: ' . $e->getMessage());
            usleep(500000);
        }
        if (empty($entries)) continue; // loop again
    }

    $processed = 0;
    // If backlog big (entries fetched equals 200) try an immediate second non-blocking batch
    if (!$once) {
        $cap = defined('APP_WORKER_MAX_BATCH') ? APP_WORKER_MAX_BATCH : 1000;
        while (count($entries) < $cap) {
            $lastLocal = $entries[count($entries)-1][0];
            $more = read_batch($redis, $stream, $lastLocal);
            if (!$more) break;
            $entries = array_merge($entries, $more);
            if (count($more) < 200) break; // partial batch means tail
        }
    }
    foreach ($entries as $entry) {
        if (!is_array($entry) || count($entry) < 2) continue;
        [$id, $fieldList] = $entry;
        if (!is_array($fieldList)) continue;
        $fields = [];
        for ($i=0; $i < count($fieldList); $i+=2) {
            $k = $fieldList[$i];
            $v = $fieldList[$i+1] ?? '';
            $fields[$k] = $v;
        }
        if (worker_flush_event($fields)) {
            $lastId = $id;
            $processed++;
        }
    }
    if ($processed) {
        $redis->set($lastKey, $lastId);
        $processedSinceTrim += $processed;
        logln("Flushed $processed events. Last ID=$lastId");
        $redis->set(REDIS_LAST_FLUSH_TS, time());

        // ✨ ensure any queued ids are written now
        worker_commit_pending();

        if ($processedSinceTrim >= $trimEvery) {
            $processedSinceTrim = 0;
            try {
                $redis->executeRaw(['XTRIM', $stream, 'MAXLEN', '~', (string)$maxLen]);
                logln("Trimmed stream to ~{$maxLen}");
            } catch (Throwable $e) { error_log('XTRIM failed: ' . $e->getMessage()); }
        }
    }
    if ($once) break;
}

if (PHP_SAPI === 'cli') {
  echo "Flush complete: upserts={$stats['upserts']}, purges={$stats['purges']}, skipped_empty={$stats['skipped_empty']}, seen={$stats['seen']}\n";
}
logln('Worker exiting (lastId=' . $lastId . ')');
