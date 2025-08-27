<?php
// Simple JSON file store with locking and write-coalescing.
header('Content-Type: application/json; charset=utf-8');

$store = __DIR__ . '/data/cards.json';
$lock  = __DIR__ . '/data/cards.lock';

if (!is_dir(__DIR__ . '/data')) { @mkdir(__DIR__ . '/data', 0775, true); }
if (!file_exists($store)) {
  file_put_contents($store, json_encode(["cards"=>[], "updated_at"=>time()], JSON_PRETTY_PRINT), LOCK_EX);
}

function read_state($path, $lock) {
  $fp = fopen($lock, 'c+');
  if ($fp) { flock($fp, LOCK_SH); }
  $raw = file_get_contents($path);
  if ($fp) { flock($fp, LOCK_UN); fclose($fp); }
  $json = json_decode($raw, true);
  if (!is_array($json)) $json = ["cards"=>[], "updated_at"=>time()];
  if (!isset($json["cards"])) $json["cards"] = [];
  return $json;
}

function write_state($path, $lock, $state) {
  $state["updated_at"] = time();
  $tmp = $path . '.tmp';
  $fp = fopen($lock, 'c+');
  if ($fp) { flock($fp, LOCK_EX); }
  file_put_contents($tmp, json_encode($state, JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT));
  rename($tmp, $path);
  if ($fp) { flock($fp, LOCK_UN); fclose($fp); }
}

// Route
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($method === 'GET' && $action === 'state') {
  echo json_encode(read_state($store, $lock));
  exit;
}

if ($method === 'POST') {
  $payload = json_decode(file_get_contents('php://input'), true) ?? [];
  $state = read_state($store, $lock);

  if ($action === 'save_card') {
    // payload: { id, text, order }
    $card = [
      "id" => $payload["id"] ?? null,
      "text" => $payload["text"] ?? "",
      "order" => intval($payload["order"] ?? 0),
    ];
    if (!$card["id"]) { http_response_code(400); echo '{"error":"missing id"}'; exit; }

    $found = false;
    foreach ($state["cards"] as &$c) {
      if ($c["id"] === $card["id"]) { $c = $card; $found = true; break; }
    }
    if (!$found) { $state["cards"][] = $card; }
    usort($state["cards"], fn($a,$b)=>($a["order"]<=>$b["order"]));
    write_state($store, $lock, $state);
    echo json_encode(["ok"=>true, "updated_at"=>$state["updated_at"]]);
    exit;
  }

  if ($action === 'bulk_save') {
    // payload: { cards: [...] }
    if (!isset($payload["cards"]) || !is_array($payload["cards"])) {
      http_response_code(400); echo '{"error":"invalid cards"}'; exit;
    }
    // Normalize
    foreach ($payload["cards"] as &$c) {
      $c["id"] = $c["id"] ?? bin2hex(random_bytes(8));
      $c["text"] = $c["text"] ?? "";
      $c["order"] = intval($c["order"] ?? 0);
    }
    $state["cards"] = $payload["cards"];
    usort($state["cards"], fn($a,$b)=>($a["order"]<=>$b["order"]));
    write_state($store, $lock, $state);
    echo json_encode(["ok"=>true, "updated_at"=>$state["updated_at"]]);
    exit;
  }

  if ($action === 'delete_card') {
    $id = $payload["id"] ?? null;
    if (!$id) { http_response_code(400); echo '{"error":"missing id"}'; exit; }
    $state["cards"] = array_values(array_filter($state["cards"], fn($c)=>$c["id"] !== $id));
    // reindex order
    foreach ($state["cards"] as $i=>&$c) { $c["order"] = $i; }
    write_state($store, $lock, $state);
    echo json_encode(["ok"=>true]);
    exit;
  }
}

http_response_code(404);
echo '{"error":"not found"}';
