<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/config.php';
header('Content-Type: application/json');

$action = $_GET['action'] ?? 'state';

function json_input() {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function ok($data = []) { echo json_encode(['ok' => true] + $data); exit; }
function fail($msg, $code = 400) { http_response_code($code); echo json_encode(['ok' => false, 'error' => $msg]); exit; }

switch ($action) {
    case 'state':
        $state = load_state();
        ok($state);
        break;
    case 'save_card':
        $in = json_input();
        $id = $in['id'] ?? null;
        $text = $in['text'] ?? '';
        $order = isset($in['order']) ? (int)$in['order'] : 0;
        if (!$id) fail('id required');
        $updated_at = redis_upsert_card($id, $text, $order);
        ok(['id' => $id, 'text' => $text, 'order' => $order, 'updated_at' => $updated_at]);
        break;
    case 'bulk_save':
        $in = json_input();
        $cards = $in['cards'] ?? [];
        if (!is_array($cards)) fail('cards must be array');
        $lastUpdated = 0;
        foreach ($cards as $c) {
            if (!isset($c['id'])) continue;
            $t = $c['text'] ?? '';
            $o = isset($c['order']) ? (int)$c['order'] : 0;
            $lastUpdated = redis_upsert_card($c['id'], $t, $o);
        }
        ok(['updated_at' => $lastUpdated]);
        break;
    case 'delete_card':
        $in = json_input();
        $id = $in['id'] ?? null;
        if (!$id) fail('id required');
        delete_card_everywhere($id);
        ok(['id' => $id]);
        break;
    case 'health':
        $r = redis_client();
        $streamLen = 0; $pending = 0; $lastId = $r->get(REDIS_STREAM_LAST) ?: '0-0';
        try { $streamLen = (int)$r->xlen(REDIS_STREAM); } catch (Throwable $e) {}
        // Estimate lag: last stream ID vs processed ID
        $lag = 0;
        try {
            // Count pending entries in chunks until either <chunk size or limit reached
            $cursor = '(' . $lastId;
            $pending = 0; $chunk = 200; $limit = 2000;
            while ($pending < $limit) {
                $slice = $r->executeRaw(['XRANGE', REDIS_STREAM, $cursor, '+', 'COUNT', (string)$chunk]);
                if (!$slice) break;
                $count = count($slice);
                $pending += $count;
                if ($count < $chunk) break; // end reached
                // advance cursor to last id in slice (exclusive next loop)
                $cursor = '(' . $slice[$count-1][0];
            }
            $lag = $pending;
        } catch (Throwable $e) {}
    $okLag = defined('APP_WORKER_MIN_OK_LAG') ? APP_WORKER_MIN_OK_LAG : 20;
    $degLag = defined('APP_WORKER_MIN_DEGRADED_LAG') ? APP_WORKER_MIN_DEGRADED_LAG : 200;
    $status = ($lag < $okLag) ? 'ok' : (($lag < $degLag) ? 'degraded' : 'backlog');
        $lastFlushTs = (int)$r->get(REDIS_LAST_FLUSH_TS);
        $sinceFlush = $lastFlushTs ? (time() - $lastFlushTs) : null;
        // In batch mode tolerate larger lag if still within interval
        if (defined('APP_WRITE_BEHIND_MODE') && APP_WRITE_BEHIND_MODE === 'batch') {
            $expected = defined('APP_BATCH_FLUSH_EXPECTED_INTERVAL') ? APP_BATCH_FLUSH_EXPECTED_INTERVAL : 180;
            if ($sinceFlush !== null && $sinceFlush <= $expected + 30) {
                // Relax status one level if within expected time window
                if ($status === 'backlog') $status = 'degraded';
            }
        }
        ok([
            'status' => $status,
            'lag' => $lag,
            'stream_length' => $streamLen,
            'last_flushed_id' => $lastId,
            'seconds_since_last_flush' => $sinceFlush
        ]);
        break;
    case 'flush_once':
        if (!defined('APP_WRITE_BEHIND') || !APP_WRITE_BEHIND) fail('write-behind disabled', 400);
        // Run worker logic one batch (reuse worker functions) by including worker file
        require_once __DIR__ . '/flush_redis_to_db.php'; // will exit if disabled; functions already loaded otherwise
        // flush_redis_to_db.php runs immediately; but we only want a single batch. Instead replicate minimal logic here.
        $r = redis_client();
        $lastId = $r->get(REDIS_STREAM_LAST) ?: '0-0';
        // If backlog large, pull bigger batch (adaptive)
        $est = $r->executeRaw(['XRANGE', REDIS_STREAM, ($lastId==='0-0' ? '-' : '(' . $lastId), '+', 'COUNT', '1']);
        $batchSize = 200;
        if ($est && count($est) === 1) {
            // quick approximate backlog via second probe
            $probe = $r->executeRaw(['XRANGE', REDIS_STREAM, ($lastId==='0-0' ? '-' : '(' . $lastId), '+', 'COUNT', '500']);
            if ($probe && count($probe) === 500) $batchSize = 800; // escalate
        }
        $batch = $r->executeRaw(['XRANGE', REDIS_STREAM, ($lastId==='0-0' ? '-' : '(' . $lastId), '+', 'COUNT', (string)$batchSize]);
        $processed = 0; $newLast = $lastId;
        foreach ($batch as $entry) {
            if (!is_array($entry) || count($entry)<2) continue;
            [$id,$fieldList] = $entry; $fields=[];
            for ($i=0;$i<count($fieldList);$i+=2){ $fields[$fieldList[$i]] = $fieldList[$i+1] ?? ''; }
            if (worker_flush_event($fields)) { $newLast=$id; $processed++; }
        }
        if ($processed) {
            $r->set(REDIS_STREAM_LAST, $newLast);
            $r->set(REDIS_LAST_FLUSH_TS, time());
        }
        ok(['flushed' => $processed]);
        break;
    case 'trim_stream':
        if (!defined('APP_WRITE_BEHIND') || !APP_WRITE_BEHIND) fail('write-behind disabled', 400);
        $keep = isset($_GET['keep']) ? max(100, (int)$_GET['keep']) : 5000; // minimum 100
        $r = redis_client();
        try { $r->executeRaw(['XTRIM', REDIS_STREAM, 'MAXLEN', '~', (string)$keep]); } catch (Throwable $e) {}
        ok(['kept' => $keep]);
        break;
    case 'debug_clear_redis_index':
        // Remove debug endpoint in production: keep guarded by constant
        if (!defined('APP_DEBUG') || !APP_DEBUG) fail('forbidden', 403);
        $r = redis_client();
        $r->del([REDIS_INDEX_KEY]);
        ok();
        break;
    default:
        fail('unknown action');
}
