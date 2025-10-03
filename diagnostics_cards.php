<?php
require_once __DIR__ . '/bootstrap.php';
header('Content-Type: text/plain');
$state = load_state();
$counts = [ 'total'=>0, 'with_name'=>0, 'without_name'=>0 ];
foreach ($state['cards'] as $c) {
  $counts['total']++;
  if (!empty(trim($c['name'] ?? ''))) $counts['with_name']++; else $counts['without_name']++;
}
printf("Cards total: %d\nWith name: %d\nWithout name: %d\n", $counts['total'], $counts['with_name'], $counts['without_name']);
