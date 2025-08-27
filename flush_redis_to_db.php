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

$once = in_array('--once', $argv, true);
$quiet = in_array('--quiet', $argv, true);
$trimEvery = defined('APP_WORKER_TRIM_EVERY') ? APP_WORKER_TRIM_EVERY : 500;
$maxLen    = defined('APP_STREAM_MAXLEN') ? APP_STREAM_MAXLEN : (getenv('STREAM_MAXLEN') ? (int)getenv('STREAM_MAXLEN') : 5000);
$processedSinceTrim = 0;

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
        if ($processedSinceTrim >= $trimEvery) {
            $processedSinceTrim = 0;
            try {
                // Approximate trimming ( ~ for performance )
                $redis->executeRaw(['XTRIM', $stream, 'MAXLEN', '~', (string)$maxLen]);
                logln("Trimmed stream to ~{$maxLen}");
            } catch (Throwable $e) { error_log('XTRIM failed: ' . $e->getMessage()); }
        }
    }
    if ($once) break;
}

logln('Worker exiting (lastId=' . $lastId . ')');
