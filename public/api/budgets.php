<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/auth_guard.php';
require_once __DIR__ . '/../../src/tl_api.php';

header('Content-Type: application/json; charset=utf-8');

$uid = requireUserApi($pdo);
$method = $_SERVER['REQUEST_METHOD'];

/**
 * Compute the current window (UTC) for a budget.
 */
function budgetWindow(string $period, ?string $startDate): array {
  $tz = new DateTimeZone('UTC');
  $now = new DateTimeImmutable('now', $tz);

  if ($period === 'weekly') {
    // ISO week starts Monday
    $dow = (int)$now->format('N'); // 1..7
    $start = $now->modify('-'.($dow-1).' days')->setTime(0,0,0);
    $end   = $now; // now; downstream clamps to now
    return [$start, $end];
  }
  if ($period === 'custom' && $startDate) {
    $start = (new DateTimeImmutable($startDate, $tz))->setTime(0,0,0);
    $end   = $now;
    return [$start, $end];
  }
  // monthly (default): first of month -> now
  $start = (new DateTimeImmutable($now->format('Y-m-01'), $tz))->setTime(0,0,0);
  $end   = $now;
  return [$start, $end];
}

/**
 * Fetch ALL transactions across all connections & accounts for a user within a window.
 * Returns a flat array: [ ['amount'=>float,'currency'=>'GBP','category'=>'X','timestamp'=>...], ... ]
 */
function fetchAllTx(PDO $pdo, int $userId, DateTimeImmutable $from, DateTimeImmutable $to): array {
  $items = [];
  $conns = tlAllConnections($pdo, $userId);
  if (!$conns) return $items;

  $fromIso = $from->format('Y-m-d\TH:i:s\Z');
  $toIso   = $to->format('Y-m-d\TH:i:s\Z');
  $qs = http_build_query(['from'=>$fromIso,'to'=>$toIso,'page'=>1,'size'=>100]);

  foreach ($conns as $conn) {
    try {
      $accResp = tlGet($pdo, $conn, '/data/v1/accounts');
    } catch (Throwable $e) {
      // if SCA needed or provider error, skip this connection
      continue;
    }
    foreach (($accResp['results'] ?? []) as $a) {
      $aid = $a['account_id'] ?? '';
      if ($aid === '') continue;
      try {
        $txResp = tlGet($pdo, $conn, "/data/v1/accounts/{$aid}/transactions?{$qs}");
      } catch (Throwable $e) {
        continue;
      }
      foreach (($txResp['results'] ?? []) as $t) {
        $items[] = [
          'amount'   => (float)($t['amount'] ?? 0),
          'currency' => $t['currency'] ?? 'GBP',
          'category' => $t['transaction_category'] ?? 'Other',
          'desc'     => $t['description'] ?? ($t['merchant_name'] ?? 'Transaction'),
          'ts'       => $t['timestamp'] ?? '',
        ];
      }
    }
  }
  return $items;
}

try {
  if ($method === 'GET') {
    // List budgets + computed progress
    $st = $pdo->prepare('SELECT * FROM budgets WHERE user_id=? AND is_active=1 ORDER BY category');
    $st->execute([$uid]);
    $rows = $st->fetchAll() ?: [];

    // If no budgets, quick empty reply
    if (!$rows) { echo json_encode(['items'=>[], 'kpi'=>['count'=>0,'total_limit'=>0,'spent'=>0,'remaining'=>0]]); exit; }

    // Build a superset window to avoid multiple fetches: earliest start among budgets -> now
    $earliest = null;
    $nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    foreach ($rows as $b) {
      [$s, $e] = budgetWindow($b['period_type'], $b['start_date']);
      if ($earliest === null || $s < $earliest) $earliest = $s;
    }
    if ($earliest === null) $earliest = (new DateTimeImmutable('first day of this month', new DateTimeZone('UTC')))->setTime(0,0,0);

    // Fetch once, then filter per-budget
    $all = fetchAllTx($pdo, $uid, $earliest, $nowUtc);

    $out = [];
    $k_total = 0.0; $k_spent = 0.0;

    foreach ($rows as $b) {
      [$winFrom, $winTo] = budgetWindow($b['period_type'], $b['start_date']);
      $cat  = $b['category'];
      $amt  = (float)$b['amount'];

      // Filter transactions within this budget's window and category (GBP only for MVP)
      $spent = 0.0;
      foreach ($all as $t) {
        if ($t['currency'] !== 'GBP') continue;
        if ($t['category'] !== $cat) continue;
        // ts compare (fallback: include if ts missing)
        if ($t['ts']) {
          $tTime = new DateTimeImmutable($t['ts'], new DateTimeZone('UTC'));
          if ($tTime < $winFrom || $tTime > $winTo) continue;
        }
        if ($t['amount'] < 0) $spent += $t['amount']; // negatives
      }

      $spentAbs  = abs($spent);
      $remaining = max(0, $amt - $spentAbs);
      $pct       = $amt > 0 ? min(1, $spentAbs / $amt) : 0;

      $out[] = [
        'id'         => (int)$b['id'],
        'category'   => $cat,
        'amount'     => round($amt, 2),
        'period'     => $b['period_type'],
        'start_date' => $b['start_date'],
        'rollover'   => (int)$b['rollover'],
        'window'     => ['from'=>$winFrom->format('Y-m-d'), 'to'=>$winTo->format('Y-m-d')],
        'spent'      => round($spentAbs, 2),
        'remaining'  => round($remaining, 2),
        'pct'        => $pct,
      ];

      $k_total += $amt;
      $k_spent += $spentAbs;
    }

    echo json_encode([
      'items' => $out,
      'kpi' => [
        'count'       => count($out),
        'total_limit' => round($k_total, 2),
        'spent'       => round($k_spent, 2),
        'remaining'   => round(max(0, $k_total - $k_spent), 2),
      ],
    ]);
    exit;
  }

  // POST: create
  if ($method === 'POST') {
    $json = json_decode(file_get_contents('php://input'), true) ?: [];
    $cat  = trim((string)($json['category'] ?? ''));
    $amt  = (float)($json['amount'] ?? 0);
    $per  = (string)($json['period_type'] ?? 'monthly');
    $sd   = $json['start_date'] ?? null;
    $rol  = (int)($json['rollover'] ?? 0);

    if ($cat === '' || $amt <= 0 || !in_array($per, ['monthly','weekly','custom'], true)) {
      http_response_code(400);
      echo json_encode(['error'=>'invalid_input']);
      exit;
    }
    if ($per === 'custom' && (!$sd || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $sd))) {
      http_response_code(400);
      echo json_encode(['error'=>'invalid_start_date']);
      exit;
    }

    $st = $pdo->prepare('INSERT INTO budgets (user_id,category,amount,period_type,start_date,rollover) VALUES (?,?,?,?,?,?)');
    $st->execute([$uid, $cat, $amt, $per, $sd, $rol]);

    echo json_encode(['ok'=>true, 'id'=>(int)$pdo->lastInsertId()]);
    exit;
  }

  // PUT: update
  if ($method === 'PUT') {
    parse_str($_SERVER['QUERY_STRING'] ?? '', $q);
    $id = (int)($q['id'] ?? 0);
    if ($id <= 0) { http_response_code(400); echo json_encode(['error'=>'missing_id']); exit; }

    $json = json_decode(file_get_contents('php://input'), true) ?: [];
    $fields = [];
    $params = [];

    foreach (['category','amount','period_type','start_date','rollover','is_active'] as $k) {
      if (array_key_exists($k, $json)) {
        $fields[] = "{$k}=?";
        $params[] = $json[$k];
      }
    }
    if (!$fields) { echo json_encode(['ok'=>true]); exit; }

    $params[] = $uid;
    $params[] = $id;

    $sql = 'UPDATE budgets SET '.implode(',', $fields).' WHERE user_id=? AND id=?';
    $st = $pdo->prepare($sql);
    $st->execute($params);

    echo json_encode(['ok'=>true]);
    exit;
  }

  // DELETE: soft delete (is_active=0)
  if ($method === 'DELETE') {
    parse_str($_SERVER['QUERY_STRING'] ?? '', $q);
    $id = (int)($q['id'] ?? 0);
    if ($id <= 0) { http_response_code(400); echo json_encode(['error'=>'missing_id']); exit; }

    $st = $pdo->prepare('UPDATE budgets SET is_active=0 WHERE user_id=? AND id=?');
    $st->execute([$uid, $id]);

    echo json_encode(['ok'=>true]);
    exit;
  }

  http_response_code(405);
  echo json_encode(['error'=>'method_not_allowed']);
} catch (Throwable $e) {
  error_log('budgets API error: '.$e->getMessage());
  http_response_code(500);
  echo json_encode(['error'=>'server_error']);
}
