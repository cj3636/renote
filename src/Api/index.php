<?php
// API entry logicpoint: route to organized implementation
require_once __DIR__ . '/../Support/Bootstrap.php';
require_once __DIR__ . '/../Support/Http.php';

use function Renote\Support\json_input;
use function Renote\Support\ok;
use function Renote\Support\fail;
use function Renote\Support\rl_check;

header('Content-Type: application/json');

if (!isset($GLOBALS['stats'])) { $GLOBALS['stats'] = ['upserts'=>0,'purges'=>0,'skipped_empty'=>0,'seen'=>0]; }

$action = $_GET['action'] ?? 'state';

switch ($action) {
    case 'state':
        $state = load_state();
        ok($state);
        break;
    case 'metrics':
        $m = metrics_snapshot();
        $r = redis_client();
        $lastId = $r->get(REDIS_STREAM_LAST) ?: '0-0';
        $streamLen = 0; try { $streamLen = (int)$r->xlen(REDIS_STREAM); } catch (Throwable $e) {}
        ok(['metrics'=>$m,'stream_length'=>$streamLen,'last_flushed_id'=>$lastId]);
        break;
    case 'save_card':
        $in = json_input();
        $id = $in['id'] ?? null;
        $text = $in['text'] ?? '';
        $order = isset($in['order']) ? (int)$in['order'] : 0;
        $name  = isset($in['name']) ? (string)$in['name'] : '';
        if (!$id) fail('id required');
        try { card_validate_id_and_text($id, $text); } catch (InvalidArgumentException $e) { fail($e->getMessage()); } catch (LengthException $e) { fail($e->getMessage()); }
        $updated_at = redis_upsert_card($id, $text, $order, $name);
        ok(['id' => $id, 'name'=>$name, 'text' => $text, 'order' => $order, 'updated_at' => $updated_at]);
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
            $n = isset($c['name']) ? (string)$c['name'] : '';
            try { card_validate_id_and_text($c['id'], $t); } catch (Throwable $e) { continue; }
            $lastUpdated = redis_upsert_card($c['id'], $t, $o, $n);
        }
        ok(['updated_at' => $lastUpdated]);
        break;
    case 'delete_card':
        $in = json_input();
        $id = $in['id'] ?? null;
        if (!$id) fail('id required');
        delete_card_redis_only($id);
        ok(['id'=>$id]);
        break;
    case 'health':
        $r = redis_client();
        $streamLen = 0; $pending = 0; $lastId = $r->get(REDIS_STREAM_LAST) ?: '0-0';
        try { $streamLen = (int)$r->xlen(REDIS_STREAM); } catch (Throwable $e) {}
        $lag = 0;
        try {
            $cursor = '(' . $lastId;
            $pending = 0; $chunk = 200; $limit = 2000;
            while ($pending < $limit) {
                $slice = $r->executeRaw(['XRANGE', REDIS_STREAM, $cursor, '+', 'COUNT', (string)$chunk]);
                if (!$slice) break;
                $count = count($slice);
                $pending += $count;
                if ($count < $chunk) break;
                $cursor = '(' . $slice[$count-1][0];
            }
            $lag = $pending;
        } catch (Throwable $e) {}
        $okLag = defined('APP_WORKER_MIN_OK_LAG') ? APP_WORKER_MIN_OK_LAG : 20;
        $degLag = defined('APP_WORKER_MIN_DEGRADED_LAG') ? APP_WORKER_MIN_DEGRADED_LAG : 200;
        $status = ($lag < $okLag) ? 'ok' : (($lag < $degLag) ? 'degraded' : 'backlog');
        $lastFlushTs = (int)$r->get(REDIS_LAST_FLUSH_TS);
        $sinceFlush = $lastFlushTs ? (time() - $lastFlushTs) : null;
        if (defined('APP_WRITE_BEHIND_MODE') && APP_WRITE_BEHIND_MODE === 'batch') {
            $expected = defined('APP_BATCH_FLUSH_EXPECTED_INTERVAL') ? APP_BATCH_FLUSH_EXPECTED_INTERVAL : 180;
            if ($sinceFlush !== null && $sinceFlush <= $expected + 30) {
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
        if (!defined('REN0TE_WORKER_LIBRARY_MODE')) define('REN0TE_WORKER_LIBRARY_MODE', true);
        require_once __DIR__ . '/../../bin/flush.php';
        $r = redis_client();
        $lastId = $r->get(REDIS_STREAM_LAST) ?: '0-0';
        $est = $r->executeRaw(['XRANGE', REDIS_STREAM, ($lastId==='0-0' ? '-' : '(' . $lastId), '+', 'COUNT', '1']);
        $batchSize = 200;
        if ($est && count($est) === 1) {
            $probe = $r->executeRaw(['XRANGE', REDIS_STREAM, ($lastId==='0-0' ? '-' : '(' . $lastId), '+', 'COUNT', '500']);
            if ($probe && count($probe) === 500) $batchSize = 800;
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
        worker_commit_pending();
        $r->set(REDIS_STREAM_LAST, $newLast);
        $r->set(REDIS_LAST_FLUSH_TS, time());
        global $stats; // from bin/flush.php include
        ok(['flushed'=>$processed, 'stats'=>$stats]);
        break;
    case 'trim_stream':
        if (!defined('APP_WRITE_BEHIND') || !APP_WRITE_BEHIND) fail('write-behind disabled', 400);
        $keep = isset($_GET['keep']) ? max(100, (int)$_GET['keep']) : 5000;
        $r = redis_client();
        try { $r->executeRaw(['XTRIM', REDIS_STREAM, 'MAXLEN', '~', (string)$keep]); } catch (Throwable $e) {}
        ok(['kept' => $keep]);
        break;
    case 'history':
      $orphans = db_orphans();
      ok(['orphans'=>$orphans]);
      break;
    case 'history_purge':
      if (!defined('APP_DEBUG') || !APP_DEBUG) fail('forbidden',403);
      $in = json_input();
      $id = $in['id'] ?? null;
      if (!$id) fail('id required');
      _db_delete_card($id);
      ok(['purged'=>$id]);
      break;
    case 'history_restore':
      if (!defined('APP_DEBUG') || !APP_DEBUG) fail('forbidden',403);
      $in = json_input();
      $id = $in['id'] ?? null;
      if (!$id) fail('id required');
      $stmt = db()->prepare("SELECT id, name, txt, `order`, updated_at FROM cards WHERE id=?");
      $stmt->execute([$id]);
      $row = $stmt->fetch();
      if (!$row) fail('not found',404);
      redis_upsert_card($row['id'], $row['txt'], (int)$row['order'], $row['name'] ?? '');
      ok(['restored'=>$id]);
      break;
    default:
        fail('unknown action');
}
