<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/auth_guard.php';
require_once __DIR__ . '/../../src/tl_api.php';

header('Content-Type: application/json; charset=utf-8');

try {
  $userId = requireUserApi($pdo);

  $rows = tlAllConnections($pdo, $userId) ?: [];
  $out  = [];

  foreach ($rows as $conn) {
    $label = 'truelayer';
    $logo  = null;

    // Try cards first (AMEX shows up here), then accounts
    try {
      $cards = tlGet($pdo, $conn, '/data/v1/cards');
      $s = $cards['results'][0]['provider'] ?? null;
      if ($s && is_array($s)) {
        $label = $s['display_name'] ?? ($s['provider_id'] ?? $label);
        $logo  = $s['logo_uri'] ?? null;
      }
    } catch (Throwable $e) { /* ignore */ }

    if ($label === 'truelayer') {
      try {
        $acc = tlGet($pdo, $conn, '/data/v1/accounts');
        $s = $acc['results'][0]['provider'] ?? null;
        if ($s && is_array($s)) {
          $label = $s['display_name'] ?? ($s['provider_id'] ?? $label);
          $logo  = $s['logo_uri'] ?? $logo;
        }
      } catch (Throwable $e) { /* ignore */ }
    }

    // Date formatting
    $created = $conn['created_at'] ?? $conn['created'] ?? null;
    $human   = $created ? date('Y-m-d H:i:s', strtotime($created)) : null;

    $out[] = [
      'id'                => (int)$conn['id'],
      'provider'          => $conn['provider'] ?? 'truelayer',
      'provider_label'    => $label,
      'logo_uri'          => $logo,
      'connected_at'      => $created,
      'connected_at_human'=> $human,
    ];
  }

  echo json_encode(['ok'=>true, 'items'=>$out], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
  error_log('connections.php error: ' . $e->getMessage());
  http_response_code(500);
  echo json_encode(['ok'=>false, 'error'=>'server_error']);
}
