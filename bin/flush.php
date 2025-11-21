<?php
// Worker: flush Redis stream events to MariaDB (write-behind persistence)
require_once __DIR__ . '/../src/Support/Bootstrap.php';

if (!defined('APP_WRITE_BEHIND') || !APP_WRITE_BEHIND) {
    fwrite(STDERR, "Write-behind disabled. Enable APP_WRITE_BEHIND to use this worker.\n");
    if (!defined('REN0TE_WORKER_LIBRARY_MODE')) exit(1);
}

$redis = redis_client();
$lastKey = REDIS_STREAM_LAST;
$stream  = REDIS_STREAM;

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
  'purges'=>0,
  'skipped_empty'=>0,
  'seen'=>0
];
$FLUSH_QUEUE = [];

function logln($msg) { global $quiet; if (!$quiet) echo date('H:i:s') . " | $msg\n"; }

function read_batch($redis, $stream, $lastId) {
    $nextStart = $lastId === '0-0' ? '-' : '(' . $lastId;
    try { return $redis->executeRaw(['XRANGE', $stream, $nextStart, '+', 'COUNT', '200']) ?: []; }
    catch (Throwable $e) { error_log('XRANGE failed: ' . $e->getMessage()); usleep(200000); return []; }
}

function worker_flush_event(array $fields): bool { global $FLUSH_QUEUE; if (empty($fields['id'])) return false; $FLUSH_QUEUE[(string)$fields['id']] = true; if (count($FLUSH_QUEUE) >= 500) worker_commit_batch(); return true; }

function worker_commit_batch(): void {
    global $FLUSH_QUEUE, $stats; if (!$FLUSH_QUEUE) return; $r=redis_client(); $pdo=db(); $ids=array_keys($FLUSH_QUEUE); $stats['seen']+=count($ids);
    $pdo->beginTransaction();
    $stmtUp=$pdo->prepare('INSERT INTO cards (id, name, txt, `order`, updated_at) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE name=VALUES(name), txt=VALUES(txt), `order`=VALUES(`order`), updated_at=VALUES(updated_at)');
    $stmtDel=$pdo->prepare('DELETE FROM cards WHERE id = ?');
    foreach ($ids as $cid) {
        $h=$r->hgetall("card:$cid");
        if(!$h){ $stmtDel->execute([$cid]); $stats['purges']++; continue; }
        $text=(string)($h['text']??''); $name=(string)($h['name']??''); $order=(int)($h['order']??0); $updated_at=(int)($h['updated_at']??time());
        if(APP_PRUNE_EMPTY && mb_strlen(trim($text))<APP_EMPTY_MINLEN){ $stmtDel->execute([$cid]); $stats['skipped_empty']++; continue; }
        $stmtUp->execute([$cid,$name,$text,$order,$updated_at]); $stats['upserts']++;
        // Version snapshot (flush origin) – ignore failures silently
        try { version_insert($cid, $name, $text, $order, 'flush', false); } catch (Throwable $e) {}
    }
    $pdo->commit(); $FLUSH_QUEUE=[];
}

function worker_commit_pending(): void { worker_commit_batch(); }

// Only run the worker loop if not in library mode
if (!defined('REN0TE_WORKER_LIBRARY_MODE')) {
    logln("Starting flush worker from ID $lastId (once=" . ($once?'yes':'no') . ")");
    $running = true;
    if (function_exists('pcntl_signal') && defined('PHP_OS_FAMILY') && PHP_OS_FAMILY !== 'Windows') {
        if (defined('SIGINT')) pcntl_signal(SIGINT, function() use (&$running){ $running=false; });
        if (defined('SIGTERM')) pcntl_signal(SIGTERM, function() use (&$running){ $running=false; });
    }
    while ($running) {
        if (function_exists('pcntl_signal_dispatch')) { pcntl_signal_dispatch(); }
        $entries = read_batch($redis, $stream, $lastId);
        if (empty($entries)) {
            if ($once) break;
            try { $blockMs=defined('APP_WORKER_BLOCK_MS')?APP_WORKER_BLOCK_MS:5000; $res=$redis->executeRaw(['XREAD','BLOCK',(string)$blockMs,'COUNT','100','STREAMS',$stream,$lastId]); if($res && isset($res[0][1])) foreach($res[0][1] as $ev){ $entries[]=$ev; } }
            catch (Throwable $e) { error_log('XREAD failed: '.$e->getMessage()); usleep(500000); }
            if (empty($entries)) continue;
        }
        $processed=0; if(!$once){ $cap=defined('APP_WORKER_MAX_BATCH')?APP_WORKER_MAX_BATCH:1000; while(count($entries)<$cap){ $lastLocal=$entries[count($entries)-1][0]; $more=read_batch($redis,$stream,$lastLocal); if(!$more) break; $entries=array_merge($entries,$more); if(count($more)<200) break; } }
        foreach ($entries as $entry) { if(!is_array($entry)||count($entry)<2) continue; [$id,$fieldList]=$entry; if(!is_array($fieldList)) continue; $fields=[]; for($i=0;$i<count($fieldList);$i+=2){ $k=$fieldList[$i]; $v=$fieldList[$i+1]??''; $fields[$k]=$v; } if(worker_flush_event($fields)){ $lastId=$id; $processed++; } }
        if ($processed) { $redis->set($lastKey,$lastId); $processedSinceTrim+=$processed; logln("Flushed $processed events. Last ID=$lastId"); $redis->set(REDIS_LAST_FLUSH_TS, time()); worker_commit_pending(); if($processedSinceTrim>=$trimEvery){ $processedSinceTrim=0; try { $redis->executeRaw(['XTRIM',$stream,'MAXLEN','~',(string)$maxLen]); logln("Trimmed stream to ~{$maxLen}"); } catch(Throwable $e){ error_log('XTRIM failed: '.$e->getMessage()); } } }
        if ($once) break;
    }
    if (PHP_SAPI === 'cli') { echo "Flush complete: upserts={$stats['upserts']}, purges={$stats['purges']}, skipped_empty={$stats['skipped_empty']}, seen={$stats['seen']}\n"; }
    logln('Worker exiting (lastId=' . $lastId . ')');
}
