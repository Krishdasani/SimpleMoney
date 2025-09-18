<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/auth_guard.php';
require_once __DIR__ . '/../../src/tl_api.php';

use GuzzleHttp\Exception\ClientException;

header('Content-Type: application/json; charset=utf-8');
header('X-SM-Insights-Version: v1');

$userId = requireUserApi($pdo);

/* ---------- utils ---------- */
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

/* ---------- DB categories ---------- */
function loadDbCategories(PDO $pdo): array {
  $rows = [];
  try { $rows = $pdo->query('SELECT id, name FROM categories')->fetchAll(PDO::FETCH_ASSOC) ?: []; }
  catch (\Throwable $e) { /* ignore */ }
  $byName = []; $byId = [];
  foreach ($rows as $r) { $byId[(int)$r['id']] = $r['name']; $byName[strtolower($r['name'])] = (int)$r['id']; }
  return ['byId'=>$byId, 'byName'=>$byName];
}
$CATS = loadDbCategories($pdo);

/* ---------- categorisation (same as Transactions v7) ---------- */
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
  if ($has('netflix')||$has('spotify')||$has('disney')||$has('now tv')||$has('apple.com/bill')||($has('google')&&$has('subscription'))) return 'Bills & Utilities';
  if ($has('amazon')||$has('argos')||$has('currys')||$has('ebay')||$has('asos')||$has('shein')) return 'Shopping';
  if ($has('shell')||$has('bp')||$has('esso')) return 'Transport';
  if ($has('nhs')||$has('boots')||$has('superdrug')||$has('pharmacy')) return 'Health & Fitness';
  if ($has('rent')||$has('mortgage')||$has('landlord')) return 'Rent & Mortgage';
  if ($has('hotel')||$has('airbnb')||$has('booking.com')) return 'Travel';
  if ($has('salary')||$has('payroll')||$has('hmrc')||$has('tax refund')) return 'Income';
  return null;
}
function genericToFriendly(string $raw, float $amt, string $desc): string {
  $r = strtoupper(str_replace('_',' ',$raw));
  if (in_array($r, ['PURCHASE','DEBIT','DIRECT DEBIT','TRANSFER','CREDIT'], true)) {
    if ($h = heurCategory($desc)) return $h;
    if ($amt > 0 && $r === 'CREDIT') return 'Income';
    if ($r === 'DIRECT DEBIT') return 'Bills & Utilities';
    if ($r === 'TRANSFER') return 'Transfers';
    if ($r === 'DEBIT' || $r === 'PURCHASE') return 'Shopping';
  }
  return ucwords(strtolower($r));
}
function friendlyToDb(string $friendly, float $amt, string $desc, array $CATS): array {
  // Map to your DB names; use direct match if already present.
  $id = $CATS['byName'][strtolower($friendly)] ?? null;
  if (!$id && $amt > 0) $id = $CATS['byName']['income'] ?? null;
  if (!$id) $id = $CATS['byName']['other'] ?? null;
  return [$id, $CATS['byId'][$id] ?? $friendly];
}
function classifyToDb(array $tx, array $item, array $CATS): array {
  $ts   = $tx['timestamp'] ?? ($tx['transaction_date'] ?? ($tx['posted_date'] ?? ''));
  $amtR = $tx['amount'] ?? ($tx['transaction_amount']['amount'] ?? 0);
  $amt  = is_numeric($amtR) ? (float)$amtR : 0.0;
  $desc = $tx['description'] ?? ($tx['merchant_name'] ?? '');

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
  [$catId, $catName] = friendlyToDb($friendly, $amt, $desc, $CATS);

  return [
    'date'          => substr((string)$ts, 0, 10),
    'amount'        => $amt,
    'currency'      => $tx['currency'] ?? ($tx['transaction_amount']['currency'] ?? ($item['currency'] ?? 'GBP')),
    'category_id'   => $catId,
    'category_name' => $catName,
  ];
}

/* ---------- items and tx fetch (same idea as transactions v7) ---------- */
function itemsForConn(PDO $pdo, array $conn): array {
  $out=[];
  try {
    $acc = tlGet($pdo,$conn,'/data/v1/accounts');
    foreach (($acc['results'] ?? []) as $a) {
      $id = $a['account_id'] ?? null; if (!$id) continue;
      $out[]=['id'=>$id,'type'=>'account','currency'=>$a['currency'] ?? 'GBP'];
    }
  } catch (\Throwable $e) {}
  try {
    $cards = tlGet($pdo,$conn,'/data/v1/cards');
    foreach (($cards['results'] ?? []) as $c) {
      $id = $c['card_id'] ?? ($c['account_id'] ?? null); if (!$id) continue;
      $out[]=['id'=>$id,'type'=>'card','currency'=>$c['currency'] ?? 'GBP'];
    }
  } catch (\Throwable $e) {}
  $seen=[]; return array_values(array_filter($out,function($it)use(&$seen){$k=$it['type'].'|'.$it['id']; if(isset($seen[$k]))return false; $seen[$k]=1; return true;}));
}
function fetchTx(PDO $pdo, array $conn, array $item, string $from, string $to, array $CATS): array {
  $id=$item['id']; $type=$item['type']; $paths=[];
  $try=function(string $base) use ($pdo,$conn,$id,$from,$to,&$paths){
    $path=$base.'/'.rawurlencode($id).'/transactions?from='.urlencode($from).'&to='.urlencode($to).'&page=1&size=500';
    $paths[]=$path;
    $resp=tlGet($pdo,$conn,$path);
    return $resp['results'] ?? [];
  };
  try{
    $rows = $type==='card' ? $try('/data/v1/cards') : $try('/data/v1/accounts');
  } catch (ClientException $e){
    $resp=$e->getResponse();
    $json=$resp?json_decode((string)$resp->getBody(),true):null;
    if (isENS($resp,is_array($json)?$json:[])) {
      $rows = $type==='card' ? $try('/data/v1/accounts') : $try('/data/v1/cards');
    } elseif (isScaExpired($resp,is_array($json)?$json:[])) {
      throw $e;
    } else {
      $rows=[];
    }
  }
  $out=[]; foreach($rows as $tx){ $out[]=classifyToDb($tx,$item,$CATS); }
  return $out;
}

/* ---------- input ---------- */
$accountId   = $_GET['account_id']   ?? '';
$accountIds  = $_GET['account_ids']  ?? '';
$typeHint    = $_GET['type']         ?? '';
list($from,$to) = clampDates($_GET['from'] ?? '', $_GET['to'] ?? '');

try {
  $conns = tlAllConnections($pdo, $userId);
  if (!$conns) { echo json_encode(['error'=>'no_connection']); exit; }

  // gather items
  $all=[]; $seen=[];
  foreach ($conns as $conn) {
    foreach (itemsForConn($pdo,$conn) as $it) {
      $k=$it['type'].'|'.$it['id']; if(isset($seen[$k])) continue;
      $seen[$k]=1; $all[]=['conn'=>$conn,'item'=>$it];
    }
  }

  // selection
  $sel=[];
  $wantAll = ($accountId==='__all__') || ($accountId==='' && $accountIds==='');
  if ($wantAll) $sel=$all;
  else {
    $wanted=[];
    if ($accountId!=='') $wanted[]=$accountId;
    if ($accountIds!=='') foreach (explode(',',$accountIds) as $w){ $w=trim($w); if($w!=='') $wanted[]=$w; }
    foreach ($all as $pair){
      $it=$pair['item'];
      if (in_array($it['id'],$wanted,true) && ($typeHint===''||$typeHint===$it['type'])) $sel[]=$pair;
    }
    if (empty($sel) && !empty($wanted)) foreach ($all as $pair) if (in_array($pair['item']['id'],$wanted,true)) $sel[]=$pair;
  }

  // fetch + aggregate
  $seriesByDate=[]; $catSpend=[]; $spend=0.0; $income=0.0; $currency='GBP';
  foreach ($sel as $pair) {
    $rows = fetchTx($pdo, $pair['conn'], $pair['item'], $from, $to, $CATS);
    foreach ($rows as $t) {
      $currency = $t['currency'] ?? $currency;
      $d = $t['date'];
      if (!isset($seriesByDate[$d])) $seriesByDate[$d]=['income'=>0.0,'spend'=>0.0];
      if ($t['amount'] >= 0) { $income += $t['amount']; $seriesByDate[$d]['income'] += $t['amount']; }
      else { $spend  += $t['amount']; $seriesByDate[$d]['spend']  += -$t['amount']; /* store spend as positive for chart */ }

      if ($t['amount'] < 0) {
        $cid = (int)($t['category_id'] ?? 0);
        $name = $t['category_name'] ?? 'Other';
        if (!isset($catSpend[$cid])) $catSpend[$cid]=['id'=>$cid,'name'=>$name,'spend'=>0.0];
        $catSpend[$cid]['spend'] += -$t['amount'];
      }
    }
  }

  ksort($seriesByDate);
  usort($catSpend, fn($a,$b)=> $b['spend'] <=> $a['spend']);

  echo json_encode([
    'version' => 'v1',
    'from'    => $from,
    'to'      => $to,
    'currency'=> $currency,
    'totals'  => [
      'spend'  => $spend,           // negative
      'income' => $income,          // positive
      'net'    => $income + $spend, // spend negative
    ],
    'series'  => array_map(fn($d,$v)=>['date'=>$d,'income'=>$v['income'],'spend'=>$v['spend']], array_keys($seriesByDate), array_values($seriesByDate)),
    'categories' => array_values($catSpend)
  ]);
} catch (ClientException $e) {
  $resp=$e->getResponse();
  $json=$resp?json_decode((string)$resp->getBody(),true):null;
  if (isScaExpired($resp,is_array($json)?$json:[])) {
    http_response_code(403);
    echo json_encode(['error'=>'reauth_required','detail'=>'SCA exemption expired']);
  } else {
    http_response_code($resp ? $resp->getStatusCode() : 500);
    echo json_encode(['error'=>'client_error']);
  }
} catch (\Throwable $e) {
  error_log('insights.php error: '.$e->getMessage());
  http_response_code(500);
  echo json_encode(['error'=>'server_error']);
}
