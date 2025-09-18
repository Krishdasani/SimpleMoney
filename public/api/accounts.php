<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/auth_guard.php';
require_once __DIR__ . '/../../src/tl_api.php';

use GuzzleHttp\Exception\ClientException;

header('Content-Type: application/json; charset=utf-8');
header('X-SM-Accounts-Version: v5');

$userId = requireUserApi($pdo);

// ---------- helpers ----------
function isENS(?\Psr\Http\Message\ResponseInterface $resp, ?array $json): bool {
  if (!$resp) return false;
  return $resp->getStatusCode() === 501 &&
         (($json['error'] ?? '') === 'endpoint_not_supported' ||
          stripos(($json['error_description'] ?? ''), 'Feature not supported') !== false);
}

function tl_try(PDO $pdo, array $conn, string $path): array {
  try {
    $res = tlGet($pdo, $conn, $path);
    return ['ok'=>true, 'status'=>200, 'json'=>$res];
  } catch (ClientException $e) {
    $resp = $e->getResponse();
    $code = $resp ? $resp->getStatusCode() : 0;
    $body = $resp ? (string)$resp->getBody() : '';
    $json = $body ? json_decode($body, true) : null;
    if (!isENS($resp, is_array($json)?$json:[])) {
      error_log("accounts.php[v5] {$path} error: ".$e->getMessage());
    }
    return ['ok'=>false, 'status'=>$code, 'json'=>$json, 'raw'=>$body];
  } catch (\Throwable $e) {
    error_log("accounts.php[v5] {$path} exception: ".$e->getMessage());
    return ['ok'=>false, 'status'=>0, 'json'=>null, 'raw'=>$e->getMessage()];
  }
}

// ---------- main ----------
try {
  $conns = tlAllConnections($pdo, $userId);
  if (!$conns) {
    echo json_encode(['version'=>'v5','items'=>[], 'error'=>'no_connection']);
    exit;
  }

  $items = [];
  $seen  = [];   // de-dupe: type|id

  foreach ($conns as $conn) {
    // --- ACCOUNTS ---
    $accProbe = tl_try($pdo, $conn, '/data/v1/accounts');
    if ($accProbe['ok']) {
      foreach (($accProbe['json']['results'] ?? []) as $a) {
        $id  = $a['account_id'] ?? null; if (!$id) continue;
        $prov= $a['provider']['display_name'] ?? ($conn['provider'] ?? 'unknown');
        $nm  = $a['display_name'] ?? ($a['account_type'] ?? 'Account');
        $cur = $a['currency'] ?? 'GBP';
        $key = "account|{$id}";
        if (!isset($seen[$key])) {
          $seen[$key] = true;
          $items[] = [
            'id'           => $id,
            'account_id'   => $id,            // UI compatibility
            'type'         => 'account',
            'display_name' => $nm,
            'currency'     => $cur,
            'provider'     => $prov,
            'label'        => sprintf('%s • %s (%s)', strtoupper((string)$prov), $nm, $cur),
          ];
        }
      }
    }

    // --- CARDS (always attempt) ---
    $cardProbe = tl_try($pdo, $conn, '/data/v1/cards');
    if ($cardProbe['ok']) {
      foreach (($cardProbe['json']['results'] ?? []) as $c) {
        // ✅ Accept either card_id or account_id (TrueLayer uses account_id for cards)
        $id    = $c['card_id'] ?? ($c['account_id'] ?? null);
        if (!$id) continue;

        $prov  = $c['provider']['display_name'] ?? ($conn['provider'] ?? 'unknown');
        $nm    = $c['display_name'] ?? ($c['name_on_card'] ?? 'Card');
        $cur   = $c['currency'] ?? 'GBP';
        $last4 = $c['partial_card_number'] ?? null;
        $suffix= $last4 ? (" ••••".$last4) : '';

        $key   = "card|{$id}";
        if (!isset($seen[$key])) {
          $seen[$key] = true;
          $items[] = [
            'id'           => $id,
            'account_id'   => $id,            // keep same field name used elsewhere
            'type'         => 'card',
            'display_name' => $nm,
            'currency'     => $cur,
            'provider'     => $prov,
            'last4'        => $last4,
            'label'        => sprintf('%s • %s%s (%s)', strtoupper((string)$prov), $nm, $suffix, $cur),
          ];
        }
      }
    }
  }

  echo json_encode(['version'=>'v5','items'=>$items]);
} catch (\Throwable $e) {
  error_log('accounts.php[v5] fatal: '.$e->getMessage());
  http_response_code(500);
  echo json_encode(['version'=>'v5','items'=>[], 'error'=>'server_error']);
}
