<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/auth_guard.php';
require_once __DIR__ . '/../../src/tl_api.php';

use GuzzleHttp\Exception\ClientException;

header('Content-Type: application/json; charset=utf-8');

$userId = requireUserApi($pdo);

/** Safe fetch that captures status and error json */
function probe(PDO $pdo, array $conn, string $path): array {
  try {
    $res = tlGet($pdo, $conn, $path);
    $results = $res['results'] ?? null;
    return [
      'ok'      => true,
      'status'  => 200,
      'count'   => is_array($results) ? count($results) : null,
      'sample'  => is_array($results) && count($results) ? array_slice($results, 0, 1) : null,
    ];
  } catch (ClientException $e) {
    $resp  = $e->getResponse();
    $code  = $resp ? $resp->getStatusCode() : 0;
    $body  = $resp ? (string)$resp->getBody() : '';
    $json  = $body ? json_decode($body, true) : null;
    return [
      'ok'      => false,
      'status'  => $code,
      'error'   => is_array($json) ? ($json['error'] ?? null) : null,
      'desc'    => is_array($json) ? ($json['error_description'] ?? null) : null,
      'raw'     => $body ?: null,
    ];
  } catch (Throwable $e) {
    return [
      'ok'      => false,
      'status'  => 0,
      'error'   => 'exception',
      'desc'    => $e->getMessage(),
    ];
  }
}

try {
  $conns = tlAllConnections($pdo, $userId);
  if (!$conns) {
    echo json_encode(['connections' => [], 'note' => 'no tl_connections for this user']);
    exit;
  }

  $out = [];
  foreach ($conns as $conn) {
    // Expect these keys in your tl_connections row:
    // id, provider, scope, created_at, etc.
    $summary = [
      'connection_id' => $conn['id'] ?? null,
      'provider'      => $conn['provider'] ?? null,
      'scope'         => $conn['scope'] ?? null,
      'created_at'    => $conn['created_at'] ?? null,
    ];

    // Probes
    $accs  = probe($pdo, $conn, '/data/v1/accounts');
    $cards = probe($pdo, $conn, '/data/v1/cards');

    $out[] = [
      'summary' => $summary,
      'accounts_probe' => $accs,
      'cards_probe'    => $cards,
    ];
  }

  echo json_encode(['connections' => $out], JSON_PRETTY_PRINT);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error' => 'server_error', 'message' => $e->getMessage()]);
}
