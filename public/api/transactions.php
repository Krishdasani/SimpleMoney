<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/auth_guard.php';
require_once __DIR__ . '/../../src/tl_api.php';

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;

header('Content-Type: application/json; charset=utf-8');
header('X-SM-Transactions-Version: v7');

$userId = requireUserApi($pdo);

// ---------- tiny utils ----------
function normDate(string $s): string {
  $s = trim($s);
  if ($s === '') return '';
  if (preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $s, $m)) {
    return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
  }
  return $s;
}
function clampDates(?string $from, ?string $to): array {
  $today = date('Y-m-d');
  $from = $from ? normDate($from) : '';
  $to   = $to   ? normDate($to)   : '';
  if ($from && $from > $today) $from = $today;
  if ($to   && $to   > $today) $to   = $today;
  if ($from && $to && $from > $to) { $tmp=$from; $from=$to; $to=$tmp; }
  return [$from ?: date('Y-m-d', strtotime('-89 days')), $to ?: $today];
}
function isENS(?\Psr\Http\Message\ResponseInterface $resp, ?array $json): bool {
  if (!$resp) return false;
  return $resp->getStatusCode() === 501 &&
         (($json['error'] ?? '') === 'endpoint_not_supported' ||
          stripos(($json['error_description'] ?? ''), 'Feature not supported') !== false);
}
function isScaExpired(?\Psr\Http\Message\ResponseInterface $resp, ?array $json): bool {
  if (!$resp) return false;
  if ($resp->getStatusCode() !== 403) return false;
  $err  = $json['error'] ?? '';
  $desc = $json['error_description'] ?? '';
  return ($err === 'sca_exceeded') || stripos($desc, 'SCA exemption has expired') !== false;
}

// ---------- DB categories ----------
function loadDbCategories(PDO $pdo): array {
  $rows = [];
  try {
    $rows = $pdo->query('SELECT id, name FROM categories')->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (\Throwable $e) { /* if table missing, we just fall back */ }
  $byName = [];
  $byId   = [];
  foreach ($rows as $r) {
    $id = (int)$r['id']; $name = (string)$r['name'];
    $byId[$id] = $name;
    $byName[strtolower($name)] = $id;
  }
  return ['byId'=>$byId, 'byName'=>$byName];
}
$CATS = loadDbCategories($pdo);

// ---------- categorisation helpers (friendly → DB) ----------
function mccCategory($mcc): ?string {
  $map = [
    5411=>'Groceries', 5499=>'Groceries',
    5812=>'Restaurants', 5814=>'Fast Food', 5813=>'Bars & Pubs', 5811=>'Catering',
    4111=>'Transport', 4112=>'Rail', 4121=>'Taxis', 4131=>'Bus', 4789=>'Transport',
    5541=>'Fuel', 5542=>'Fuel',
    4814=>'Telecom', 4900=>'Utilities',
    5311=>'Shopping', 5399=>'Shopping', 5691=>'Clothing', 5732=>'Electronics', 5941=>'Sports',
    5921=>'Alcohol', 5968=>'Subscriptions',
    7995=>'Gambling',
    6011=>'Cash Withdrawal', 6012=>'Financial',
    7011=>'Hotels', 4511=>'Airlines', 4722=>'Travel',
    6300=>'Insurance',
  ];
  $n = (int)preg_replace('/\D/','',(string)$mcc);
  return $map[$n] ?? null;
}
function heurCategory(string $desc): ?string {
  $d = strtolower($desc); $has = fn($s)=> strpos($d,$s)!==false;
  if ($has('uber')||$has('bolt')||$has('tfl')||$has('train')||$has('rail')||$has('bus')||$has('taxi')) return 'Transport';
  if ($has('tesco')||$has('sainsbury')||$has('asda')||$has('morrisons')||$has('aldi')||$has('lidl')||$has('waitrose')||$has('co-op')) return 'Groceries';
  if ($has('starbucks')||$has('costa')||$has('nero')) return 'Coffee';
  if ($has('mcdonald')||$has('kfc')||$has('burger king')||$has('domino')||$has('pizza hut')||$has('subway')) return 'Fast Food';
  if ($has('betfair')||$has('bet365')||$has('william hill')||$has('ladbrokes')) return 'Gambling';
  if ($has('vodafone')||$has(' o2 ')||$has(' ee ')||$has(' three ')||$has('virgin media')||$has('giffgaff')) return 'Telecom';
  if ($has('octopus')||$has('british gas')||$has('ovo')||$has('edf')||$has('eon')) return 'Utilities';
  if ($has('netflix')||$has('spotify')||$has('disney')||$has('now tv')||$has('apple.com/bill')||($has('google')&&$has('subscription'))) return 'Subscriptions';
  if ($has('amazon')||$has('argos')||$has('currys')||$has('ebay')||$has('asos')||$has('shein')) return 'Shopping';
  if ($has('shell')||$has('bp')||$has('esso')) return 'Fuel';
  if ($has('nhs')||$has('boots')||$has('superdrug')||$has('pharmacy')) return 'Health';
  if ($has('rent')||$has('mortgage')||$has('landlord')) return 'Housing';
  if ($has('hotel')||$has('airbnb')||$has('booking.com')) return 'Travel';
  if ($has('salary')||$has('payroll')||$has('hmrc')||$has('tax refund')) return 'Income';
  return null;
}
function genericToFriendly(string $raw, float $amt, string $desc): string {
  $r = strtoupper(str_replace('_',' ',$raw));
  if (in_array($r, ['PURCHASE','DEBIT','DIRECT DEBIT','TRANSFER','CREDIT'], true)) {
    if ($h = heurCategory($desc)) return $h;
    if ($amt > 0 && $r === 'CREDIT') return 'Income';
    if ($r === 'DIRECT DEBIT') return 'Bills';
    if ($r === 'TRANSFER') return 'Transfers';
    if ($r === 'DEBIT' || $r === 'PURCHASE') return 'Shopping';
  }
  return ucwords(strtolower($r));
}
function friendlyToDb(string $friendly, float $amt, string $desc, array $CATS): array {
  // Map friendly labels to your DB names
  $syn = [
    'groceries'=>'Groceries',
    'restaurants'=>'Eating Out', 'fast food'=>'Eating Out', 'coffee'=>'Eating Out','bars & pubs'=>'Eating Out','catering'=>'Eating Out',
    'shopping'=>'Shopping','clothing'=>'Shopping','electronics'=>'Shopping',
    'transport'=>'Transport','rail'=>'Transport','taxis'=>'Transport','bus'=>'Transport','fuel'=>'Transport',
    'telecom'=>'Bills & Utilities','utilities'=>'Bills & Utilities','bills'=>'Bills & Utilities','subscriptions'=>'Bills & Utilities','insurance'=>'Bills & Utilities',
    'entertainment'=>'Entertainment','gambling'=>'Entertainment',
    'health'=>'Health & Fitness','pharmacy'=>'Health & Fitness','health & fitness'=>'Health & Fitness',
    'travel'=>'Travel','hotels'=>'Travel','airlines'=>'Travel',
    'housing'=>'Rent & Mortgage','rent'=>'Rent & Mortgage','mortgage'=>'Rent & Mortgage',
    'income'=>'Income',
    'transfers'=>'Transfers','cash withdrawal'=>'Transfers',
    'other'=>'Other',
  ];
  $key = strtolower($friendly);
  $target = $syn[$key] ?? $friendly; // attempt direct use if already same name
  $id = $CATS['byName'][strtolower($target)] ?? null;

  // final fallbacks
  if (!$id && $amt > 0) $id = $CATS['byName']['income'] ?? null;
  if (!$id) $id = $CATS['byName']['other'] ?? null;
  $name = $CATS['byId'][$id] ?? $friendly;
  return [$id, $name];
}
function classifyToDb(array $tx, array $item, array $CATS): array {
  $ts   = $tx['timestamp'] ?? ($tx['transaction_date'] ?? ($tx['posted_date'] ?? ''));
  $amtR = $tx['amount'] ?? ($tx['transaction_amount']['amount'] ?? 0);
  $amt  = is_numeric($amtR) ? (float)$amtR : 0.0;
  $desc = $tx['description'] ?? ($tx['merchant_name'] ?? '');

  // 1) MCC / 2) heuristics / 3) provider fields → friendly
  $friendly = null;
  $mcc = $tx['merchant_category_code'] ?? ($tx['mcc'] ?? null);
  if ($mcc && ($c = mccCategory($mcc))) $friendly = $c;
  if (!$friendly && $desc && ($c = heurCategory($desc))) $friendly = $c;
  if (!$friendly) {
    foreach (['transaction_classification','transaction_category','category','merchant_category'] as $k) {
      if (!isset($tx[$k])) continue;
      $v = $tx[$k];
      if (is_array($v) && isset($v[0]) && is_string($v[0]) && $v[0] !== '') { $friendly = genericToFriendly($v[0], $amt, $desc); break; }
      if (is_string($v) && $v !== '') { $friendly = genericToFriendly($v, $amt, $desc); break; }
    }
  }
  if (!$friendly) $friendly = ($amt > 0 ? 'Income' : 'Other');

  // Map friendly → DB category
  [$catId, $catName] = friendlyToDb($friendly, $amt, $desc, $CATS);

  return [
    'id'         => $tx['transaction_id'] ?? ($tx['id'] ?? null),
    'account_id' => $item['id'],
    'type'       => $item['type'],
    'ts'         => $ts,
    'date'       => substr((string)$ts, 0, 10),
    'amount'     => $amt,
    'currency'   => $tx['currency'] ?? ($tx['transaction_amount']['currency'] ?? ($item['currency'] ?? 'GBP')),
    'description'=> $desc ?: 'Transaction',
    'merchant'   => $tx['merchant_name'] ?? null,
    'category'   => $catName,     // backwards compatibility for UI that expects 'category'
    'category_id'=> $catId,
    'category_name'=> $catName,
    'mcc'        => $mcc,
    'provider'   => $item['provider'] ?? null,
    'source'     => $item['type'],
  ];
}

// ---------- fetch items per connection ----------
function itemsForConn(PDO $pdo, array $conn): array {
  $out = [];
  try {
    $acc = tlGet($pdo, $conn, '/data/v1/accounts');
    foreach (($acc['results'] ?? []) as $a) {
      $id = $a['account_id'] ?? null; if (!$id) continue;
      $out[] = [
        'id'=>$id,'type'=>'account',
        'display_name'=>$a['display_name'] ?? ($a['account_type'] ?? 'Account'),
        'currency'=>$a['currency'] ?? 'GBP',
        'provider'=>$a['provider']['display_name'] ?? ($conn['provider'] ?? 'provider'),
      ];
    }
  } catch (\Throwable $e) { /* no accounts */ }
  try {
    $cards = tlGet($pdo, $conn, '/data/v1/cards');
    foreach (($cards['results'] ?? []) as $c) {
      $id = $c['card_id'] ?? ($c['account_id'] ?? null); if (!$id) continue;
      $out[] = [
        'id'=>$id,'type'=>'card',
        'display_name'=>$c['display_name'] ?? ($c['name_on_card'] ?? 'Card'),
        'currency'=>$c['currency'] ?? 'GBP',
        'provider'=>$c['provider']['display_name'] ?? ($conn['provider'] ?? 'provider'),
      ];
    }
  } catch (\Throwable $e) { /* no cards */ }
  // de-dupe
  $seen=[]; return array_values(array_filter($out,function($it)use(&$seen){$k=$it['type'].'|'.$it['id']; if(isset($seen[$k]))return false; $seen[$k]=1; return true;}));
}

// ---------- pull tx for one item ----------
function fetchTxForItem(PDO $pdo, array $conn, array $item, string $from, string $to, array $CATS, array &$diag): array {
  $id = $item['id']; $type = $item['type']; $paths=[];
  $try = function(string $base) use ($pdo,$conn,$id,$from,$to,&$paths) {
    $path = $base.'/'.rawurlencode($id).'/transactions?from='.urlencode($from).'&to='.urlencode($to).'&page=1&size=500';
    $paths[] = $path;
    $resp = tlGet($pdo, $conn, $path);
    return $resp['results'] ?? [];
  };
  try {
    $rows = $type === 'card' ? $try('/data/v1/cards') : $try('/data/v1/accounts');
  } catch (ClientException $e) {
    $resp = $e->getResponse();
    $json = $resp ? json_decode((string)$resp->getBody(), true) : null;
    if (isENS($resp, is_array($json)?$json:[])) {
      $rows = $type === 'card' ? $try('/data/v1/accounts') : $try('/data/v1/cards');
    } elseif (isScaExpired($resp, is_array($json)?$json:[])) {
      $diag[]=['reauth'=>true,'paths'=>$paths];
      throw $e;
    } else {
      try { $rows = $type === 'card' ? $try('/data/v1/accounts') : $try('/data/v1/cards'); }
      catch (\Throwable $e2) { throw $e; }
    }
  }
  $diag[] = ['id'=>$id,'type'=>$type,'paths'=>$paths,'count'=>is_array($rows)?count($rows):0];
  $out=[];
  foreach ($rows as $tx) $out[] = classifyToDb($tx, $item, $CATS);
  return $out;
}

// ---------- parse input ----------
$accountId   = $_GET['account_id']   ?? '';
$accountIds  = $_GET['account_ids']  ?? '';
$typeHint    = $_GET['type']         ?? ''; // 'account' | 'card'
list($from, $to) = clampDates($_GET['from'] ?? '', $_GET['to'] ?? '');
$page        = max(1, (int)($_GET['page'] ?? 1));
$size        = max(1, min(200, (int)($_GET['size'] ?? 50)));
$wantAll     = ($accountId === '__all__') || ($accountId === '' && $accountIds === '');

// NEW: filters
$q           = trim((string)($_GET['q'] ?? ''));
$categoryRaw = trim((string)($_GET['category'] ?? ''));     // still supported
$categoryId  = (int)($_GET['category_id'] ?? 0);

// ---------- run ----------
try {
  $conns = tlAllConnections($pdo, $userId);
  if (!$conns) {
    echo json_encode(['version'=>'v7','items'=>[], 'total'=>0, 'page'=>$page, 'size'=>$size, 'merged'=>false, 'error'=>'no_connection']);
    exit;
  }

  // items
  $all = [];
  $seen = [];
  foreach ($conns as $conn) {
    foreach (itemsForConn($pdo, $conn) as $it) {
      $k = $it['type'].'|'.$it['id'];
      if (!isset($seen[$k])) { $seen[$k]=1; $all[]=['conn'=>$conn,'item'=>$it]; }
    }
  }

  // selection
  $sel = [];
  if ($wantAll) $sel = $all;
  else {
    $wanted = [];
    if ($accountId !== '') $wanted[] = $accountId;
    if ($accountIds !== '') foreach (explode(',', $accountIds) as $w) { $w=trim($w); if ($w!=='') $wanted[]=$w; }
    foreach ($all as $pair) if (in_array($pair['item']['id'], $wanted, true) && ($typeHint==='' || $typeHint===$pair['item']['type'])) $sel[]=$pair;
    if (empty($sel) && !empty($wanted)) foreach ($all as $pair) if (in_array($pair['item']['id'], $wanted, true)) $sel[]=$pair;
  }

  // fetch
  $diag=[]; $merged=[];
  foreach ($sel as $pair) {
    $merged = array_merge($merged, fetchTxForItem($pdo, $pair['conn'], $pair['item'], $from, $to, $CATS, $diag));
  }

  // server-side filtering
  if ($categoryId > 0) {
    $merged = array_values(array_filter($merged, fn($t)=> (int)($t['category_id'] ?? 0) === $categoryId));
  } elseif ($categoryRaw !== '' && strcasecmp($categoryRaw, 'All') !== 0) {
    $merged = array_values(array_filter($merged, fn($t)=> isset($t['category']) && strcasecmp((string)$t['category'], $categoryRaw)===0));
  }
  if ($q !== '') {
    $qLower = mb_strtolower($q);
    $merged = array_values(array_filter($merged, function($t) use ($qLower) {
      $desc = mb_strtolower((string)($t['description'] ?? ''));
      $mer  = mb_strtolower((string)($t['merchant'] ?? ''));
      return (strpos($desc,$qLower)!==false) || (strpos($mer,$qLower)!==false);
    }));
  }

  // order + paginate
  usort($merged, fn($a,$b)=> strcmp(($b['ts'] ?? $b['date'] ?? ''), ($a['ts'] ?? $a['date'] ?? '')));
  $total = count($merged);
  $slice = array_slice($merged, ($page-1)*$size, $size);

  echo json_encode([
    'version' => 'v7',
    'items'   => $slice,
    'total'   => $total,
    'page'    => $page,
    'size'    => $size,
    'merged'  => $wantAll || count($sel) > 1
  ]);
} catch (ClientException $e) {
  $resp = $e->getResponse();
  $body = $resp ? (string)$resp->getBody() : '';
  $json = $body ? json_decode($body, true) : null;

  if (isScaExpired($resp, is_array($json)?$json:[])) {
    // Optionally generate a reauth link here (same as earlier version).
    echo json_encode([
      'version'=>'v7', 'items'=>[], 'error'=>'reauth_required', 'error_detail'=>'SCA exemption expired'
    ]);
    exit;
  }
  http_response_code($resp ? $resp->getStatusCode() : 500);
  echo json_encode(['version'=>'v7','items'=>[], 'error'=>'client_error']);
} catch (\Throwable $e) {
  error_log('transactions.php[v7] error: '.$e->getMessage());
  http_response_code(500);
  echo json_encode(['version'=>'v7','items'=>[], 'error'=>'server_error']);
}
