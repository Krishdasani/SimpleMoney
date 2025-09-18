<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/auth_guard.php';
require_once __DIR__ . '/../../src/tl_api.php';

use GuzzleHttp\Exception\ClientException;

header('Content-Type: application/json; charset=utf-8');
header('X-SM-Summary-Version: v3');

$userId = requireUserApi($pdo);

function safe_get(PDO $pdo, array $conn, string $path, ?array &$errorOut = null) {
  try {
    $res = tlGet($pdo, $conn, $path);
    return is_array($res) ? $res : null;
  } catch (ClientException $e) {
    $resp = $e->getResponse();
    $code = $resp ? $resp->getStatusCode() : 0;
    $body = $resp ? (string)$resp->getBody() : '';
    $json = $body ? json_decode($body, true) : null;
    $errorOut = ['status' => $code, 'json' => $json, 'raw' => $body];
    return null;
  } catch (Throwable $e) {
    $errorOut = ['status' => 0, 'json' => null, 'raw' => $e->getMessage()];
    return null;
  }
}

function first_amount(array $balance): ?float {
  foreach (['current', 'available', 'amount', 'cleared'] as $k) {
    if (isset($balance[$k]) && is_numeric($balance[$k])) return (float)$balance[$k];
  }
  return null;
}

function norm_tx(array $tx, array $meta): array {
  $ts   = $tx['timestamp'] ?? ($tx['transaction_date'] ?? ($tx['posted_date'] ?? ''));
  $amt  = $tx['amount'] ?? ($tx['transaction_amount']['amount'] ?? 0.0);
  $desc = $tx['description'] ?? ($tx['merchant_name'] ?? 'Transaction');
  return [
    'date'          => substr((string)$ts, 0, 10),
    'amount'        => (float)$amt,
    'desc'          => $desc,
    'account_label' => $meta['label'],
  ];
}

try {
  $tz   = new DateTimeZone('UTC');
  $now  = new DateTimeImmutable('now', $tz);
  $from = (new DateTimeImmutable($now->format('Y-m-01'), $tz))->setTime(0,0,0);
  $to   = $now;

  $fromIso = $from->format('Y-m-d');
  $toIso   = $to->format('Y-m-d');

  $conns = tlAllConnections($pdo, $userId);
  if (!$conns) {
    echo json_encode([
      'version'   => 'v3',
      'kpis'      => ['total_balance_gbp' => 0.0, 'mtd_spent_gbp' => 0.0, 'mtd_income_gbp' => 0.0, 'other_currencies' => []],
      'balances'  => [],
      'recent'    => [],
      'reauth_required' => false,
      'warnings'  => ['no_connection']
    ]);
    exit;
  }

  $balances = [];
  $recent_all = [];   // all MTD tx across all items (accounts + cards)
  $recent_ui  = [];   // for the "Recent Transactions" panel (top 10)
  $totalGBP = 0.0;    // KPI: bank accounts only
  $otherCurrencies = [];
  $reauth = false;
  $warn = [];

  foreach ($conns as $conn) {
    // ---- BANK ACCOUNTS ----
    $accErr = null;
    $accList = safe_get($pdo, $conn, '/data/v1/accounts', $accErr);
    if ($accErr && ($accErr['status'] ?? 0) === 403) { $reauth = true; }

    foreach (($accList['results'] ?? []) as $a) {
      $accId = $a['account_id'] ?? null; if (!$accId) continue;
      $provider = $a['provider']['display_name'] ?? ($conn['provider'] ?? 'provider');
      $name = $a['display_name'] ?? ($a['account_type'] ?? 'Account');
      $cur = $a['currency'] ?? 'GBP';

      // Balance (for KPI and list)
      $balErr = null;
      $balRes = safe_get($pdo, $conn, "/data/v1/accounts/{$accId}/balance", $balErr);
      if ($balErr && ($balErr['status'] ?? 0) === 403) { $reauth = true; }
      $balObj = is_array($balRes['results'][0] ?? null) ? $balRes['results'][0] : [];
      $amtBal = first_amount($balObj) ?? 0.0;

      if (($cur ?? 'GBP') === 'GBP') { $totalGBP += $amtBal; }
      else { $otherCurrencies[$cur] = true; }

      $label = sprintf('%s • %s (%s)', strtoupper((string)$provider), $name, $cur);
      $balances[] = [
        'account_id' => $accId,
        'type'       => 'account',
        'provider'   => $provider,
        'label'      => $label,
        'amount'     => round($amtBal, 2),
        'currency'   => $cur,
      ];

      // Full-month transactions (bigger size so MTD is accurate)
      $txErr = null;
      $txRes = safe_get($pdo, $conn, "/data/v1/accounts/{$accId}/transactions?from={$fromIso}&to={$toIso}&page=1&size=500", $txErr);
      if ($txErr && ($txErr['status'] ?? 0) === 403) { $reauth = true; }
      $meta = ['label' => sprintf('%s • %s', strtoupper((string)$provider), $name)];
      foreach (($txRes['results'] ?? []) as $tx) {
        $n = norm_tx($tx, $meta);
        $recent_all[] = $n;
        $recent_ui[]  = $n;
      }
    }

    // ---- CARDS (AMEX etc.) ----
    $cardErr = null;
    $cardList = safe_get($pdo, $conn, '/data/v1/cards', $cardErr);
    if ($cardErr && ($cardErr['status'] ?? 0) === 403) { $reauth = true; }

    foreach (($cardList['results'] ?? []) as $c) {
      $cardId = $c['card_id'] ?? ($c['account_id'] ?? null); if (!$cardId) continue;
      $provider = $c['provider']['display_name'] ?? ($conn['provider'] ?? 'provider');
      $name = $c['display_name'] ?? ($c['name_on_card'] ?? 'Card');
      $cur = $c['currency'] ?? 'GBP';
      $last4 = $c['partial_card_number'] ?? null;

      // Card balance (shown in list only, excluded from Total Balance KPI)
      $balErr = null;
      $balRes = safe_get($pdo, $conn, "/data/v1/cards/{$cardId}/balance", $balErr);
      if ($balErr && ($balErr['status'] ?? 0) === 403) { $reauth = true; }
      $balObj = is_array($balRes['results'][0] ?? null) ? $balRes['results'][0] : [];
      $amtBal = first_amount($balObj) ?? 0.0;

      if (($cur ?? 'GBP') !== 'GBP') { $otherCurrencies[$cur] = true; }

      $label = sprintf('%s • %s%s (%s)', strtoupper((string)$provider), $name, $last4 ? " ••••{$last4}" : '', $cur);
      $balances[] = [
        'account_id' => $cardId,
        'type'       => 'card',
        'provider'   => $provider,
        'label'      => $label,
        'amount'     => round($amtBal, 2),
        'currency'   => $cur,
      ];

      // Full-month card transactions for accurate MTD
      $txErr = null;
      $txRes = safe_get($pdo, $conn, "/data/v1/cards/{$cardId}/transactions?from={$fromIso}&to={$toIso}&page=1&size=500", $txErr);
      if ($txErr && ($txErr['status'] ?? 0) === 403) { $reauth = true; }
      $meta = ['label' => sprintf('%s • %s', strtoupper((string)$provider), $name)];
      foreach (($txRes['results'] ?? []) as $tx) {
        $n = norm_tx($tx, $meta);
        $recent_all[] = $n;
        $recent_ui[]  = $n;
      }
    }
  }

  // Compute accurate month-to-date KPIs across ALL accounts + cards
  $mtd_spent  = 0.0;
  $mtd_income = 0.0;
  foreach ($recent_all as $t) {
    $a = $t['amount'];
    if ($a < 0) $mtd_spent  += $a;
    else        $mtd_income += $a;
  }

  // Build “Recent Transactions” panel: newest first, top 10
  usort($recent_ui, fn($a,$b)=> strcmp($b['date'] ?? '', $a['date'] ?? ''));
  $recent = array_slice($recent_ui, 0, 10);

  echo json_encode([
    'version' => 'v3',
    'kpis' => [
      'total_balance_gbp' => round($totalGBP, 2),         // bank accounts only
      'mtd_spent_gbp'     => round($mtd_spent, 2),        // all accounts + cards (full month)
      'mtd_income_gbp'    => round($mtd_income, 2),       // all accounts + cards (full month)
      'other_currencies'  => array_keys($otherCurrencies),
    ],
    'balances' => $balances,  // includes accounts + cards (e.g., AMEX)
    'recent'   => $recent,    // top 10 newest entries across all sources
    'reauth_required' => $reauth,
    'warnings' => $warn,
  ]);
} catch (Throwable $e) {
  error_log('summary.php[v3] error: '.$e->getMessage());
  http_response_code(500);
  echo json_encode(['version'=>'v3','error'=>'server_error']);
}
