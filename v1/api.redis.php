$redis = new Redis();
$socket_path = '/var/run/redis/redis.sock';
$redis->connect($socket_path);

if ($action === 'save_card') {
  $id = $payload['id'] ?? null;
  if (!$id) { http_response_code(400); echo '{"error":"id"}'; exit; }
  $text  = $payload['text'] ?? '';
  $order = intval($payload['order'] ?? 0);
  $ts = time();

  $redis->hMSet("card:$id", ['text'=>$text, 'order'=>$order, 'updated_at'=>$ts]);
  $redis->sAdd('cards:index', $id);
  $redis->xAdd('cards:stream', '*', ['id'=>$id, 'ts'=>$ts]);

  echo json_encode(['ok'=>true, 'updated_at'=>$ts]);
  exit;
}
