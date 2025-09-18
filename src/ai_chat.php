<?php
declare(strict_types=1);

require_once __DIR__ . '/auth_guard.php';
require_once __DIR__ . '/tl_api.php';

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\ClientException;

/**
 * SimpleMoney AI — Groq edition
 * - Builds a private snapshot of the user’s finances (last 90 days)
 * - Calls Groq Chat Completions (OpenAI-compatible)
 * - Finance-only guardrails + image-generation refusal
 *
 * Endpoint (IMPORTANT): https://api.groq.com/openai/v1/chat/completions
 */

const SM_GROQ_BASE   = 'https://api.groq.com';           // <-- base domain only
const SM_GROQ_PATH   = '/openai/v1/chat/completions';    // <-- full path with /openai prefix
const SM_GROQ_MODEL  = 'llama-3.3-70b-versatile';
const SM_AI_TIMEOUT  = 15.0; // seconds

/* -------------------- Key lookup -------------------- */
function sm_env_key_groq(): string {
  $k = getenv('GROQ_API_KEY') ?: ($_ENV['GROQ_API_KEY'] ?? $_SERVER['GROQ_API_KEY'] ?? '');
  if (!$k) $k = getenv('OPENAI_API_KEY') ?: ($_ENV['OPENAI_API_KEY'] ?? '');
  if (!$k) {
    $env = dirname(__DIR__) . '/.env';
    if (is_readable($env)) {
      foreach (file($env, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        [$name,$value] = array_pad(explode('=', $line, 2), 2, '');
        $name = trim($name);
        if (strcasecmp($name,'GROQ_API_KEY')===0 || strcasecmp($name,'OPENAI_API_KEY')===0) {
          $k = trim($value, " \t\n\r\0\x0B\"'");
          break;
        }
      }
    }
  }
  if (!$k) throw new RuntimeException('GROQ_API_KEY not set. Add it to your .env.');
  return $k;
}

/* -------------------- Utils -------------------- */
function sm_now_ymd(): string { return date('Y-m-d'); }
function sm_dd($v): string { return json_encode($v, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); }

/* -------------------- Categories (DB) -------------------- */
function sm_load_categories(PDO $pdo): array {
  $byId=[]; $byName=[];
  try {
    foreach ($pdo->query('SELECT id, name FROM categories') as $row) {
      $byId[(int)$row['id']] = $row['name'];
      $byName[strtolower($row['name'])] = (int)$row['id'];
    }
  } catch (Throwable $e) {}
  return ['byId'=>$byId, 'byName'=>$byName];
}

/* -------------------- Category heuristics -------------------- */
function sm_mcc_category($mcc): ?string {
  $map = [
    5411=>'Groceries', 5499=>'Groceries',
    5812=>'Eating Out', 5814=>'Eating Out', 5813=>'Entertainment',
    4111=>'Transport', 4121=>'Transport', 4131=>'Transport', 4789=>'Transport',
    5541=>'Transport', 5542=>'Transport',
    4814=>'Bills & Utilities', 4900=>'Bills & Utilities',
    5311=>'Shopping', 5399=>'Shopping', 5691=>'Shopping', 5732=>'Shopping', 5941=>'Shopping',
    7995=>'Entertainment',
    7011=>'Travel', 4511=>'Travel', 4722=>'Travel',
    6300=>'Bills & Utilities',
  ];
  $n = (int)preg_replace('/\D/','',(string)$mcc);
  return $map[$n] ?? null;
}
function sm_heur_category(string $desc): ?string {
  $d = strtolower($desc); $has = fn($s)=> strpos($d,$s)!==false;
  if ($has('tesco')||$has('sainsbury')||$has('asda')||$has('aldi')||$has('lidl')||$has('waitrose')) return 'Groceries';
  if ($has('uber')||$has('tfl')||$has('train')||$has('bus')||$has('taxi')||$has('bolt')) return 'Transport';
  if ($has('vodafone')||$has('virgin media')||$has('o2 ')||$has(' ee ')||$has('giffgaff')||$has('octopus')||$has('british gas')||$has('edf')||$has('eon')) return 'Bills & Utilities';
  if ($has('starbucks')||$has('costa')||$has('mcdonald')||$has('kfc')||$has('burger king')||$has('domino')) return 'Eating Out';
  if ($has('amazon')||$has('argos')||$has('currys')||$has('ebay')||$has('asos')||$has('shein')) return 'Shopping';
  if ($has('betfair')||$has('bet365')||$has('ladbrokes')) return 'Entertainment';
  if ($has('salary')||$has('payroll')||$has('hmrc')||$has('tax refund')) return 'Income';
  if ($has('rent')||$has('mortgage')||$has('landlord')) return 'Rent & Mortgage';
  return null;
}
function sm_generic_to_friendly(string $raw, float $amt, string $desc): string {
  $r = strtoupper(str_replace('_',' ',$raw));
  if (in_array($r, ['PURCHASE','DEBIT','DIRECT DEBIT','TRANSFER','CREDIT'], true)) {
    if ($h = sm_heur_category($desc)) return $h;
    if ($amt > 0 && $r === 'CREDIT') return 'Income';
    if ($r === 'DIRECT DEBIT') return 'Bills & Utilities';
    if ($r === 'TRANSFER') return 'Transfers';
    return 'Shopping';
  }
  return ucwords(strtolower($r));
}
function sm_map_to_db(string $friendly, float $amt, array $CATS): array {
  $id = $CATS['byName'][strtolower($friendly)] ?? null;
  if (!$id && $amt > 0) $id = $CATS['byName']['income'] ?? null;
  if (!$id) $id = $CATS['byName']['other'] ?? null;
  return [$id, $CATS['byId'][$id] ?? $friendly];
}

/* -------------------- TrueLayer helpers -------------------- */
function sm_is_endpoint_not_supported(?\Psr\Http\Message\ResponseInterface $resp, ?array $json): bool {
  if (!$resp) return false;
  return $resp->getStatusCode() === 501 &&
         (($json['error'] ?? '') === 'endpoint_not_supported' ||
          stripos(($json['error_description'] ?? ''), 'Feature not supported') !== false);
}
function sm_is_sca_expired(?\Psr\Http\Message\ResponseInterface $resp, ?array $json): bool {
  if (!$resp) return false;
  if ($resp->getStatusCode() !== 403) return false;
  $err  = $json['error'] ?? '';
  $desc = $json['error_description'] ?? '';
  return ($err === 'sca_exceeded')
      || stripos($desc, 'SCA exemption has expired') !== false
      || stripos($desc, 'PSU Authentication') !== false;
}

/* -------------------- Discover items & fetch tx -------------------- */
function sm_items_for_conn(PDO $pdo, array $conn): array {
  $out=[];
  try {
    $acc = tlGet($pdo,$conn,'/data/v1/accounts');
    foreach (($acc['results'] ?? []) as $a) {
      $id = $a['account_id'] ?? null; if (!$id) continue;
      $out[]=['id'=>$id,'type'=>'account','currency'=>$a['currency'] ?? 'GBP','label'=>$a['display_name'] ?? 'Account'];
    }
  } catch (Throwable $e) {}
  try {
    $cards = tlGet($pdo,$conn,'/data/v1/cards');
    foreach (($cards['results'] ?? []) as $c) {
      $id = $c['card_id'] ?? ($c['account_id'] ?? null); if (!$id) continue;
      $out[]=['id'=>$id,'type'=>'card','currency'=>$c['currency'] ?? 'GBP','label'=>$c['display_name'] ?? 'Card'];
    }
  } catch (Throwable $e) {}
  $seen=[]; return array_values(array_filter($out,function($it)use(&$seen){$k=$it['type'].'|'.$it['id']; if(isset($seen[$k]))return false; $seen[$k]=1; return true;}));
}

function sm_fetch_tx(PDO $pdo, array $conn, array $item, string $from, string $to): array {
  $id=$item['id']; $type=$item['type'];
  $try=function(string $base) use ($pdo,$conn,$id,$from,$to){
    $path=$base.'/'.rawurlencode($id).'/transactions?from='.urlencode($from).'&to='.urlencode($to).'&page=1&size=500';
    $resp=tlGet($pdo,$conn,$path);
    return $resp['results'] ?? [];
  };
  try {
    return $type==='card' ? $try('/data/v1/cards') : $try('/data/v1/accounts');
  } catch (ClientException $e) {
    $resp = $e->getResponse();
    $json = $resp ? json_decode((string)$resp->getBody(),true) : null;

    if (sm_is_endpoint_not_supported($resp, is_array($json)?$json:[])) {
      return $type==='card' ? $try('/data/v1/accounts') : $try('/data/v1/cards');
    }
    if (sm_is_sca_expired($resp, is_array($json)?$json:[])) {
      $label = $item['label'] ?? ($item['type'].' '.$item['id']);
      throw new RuntimeException('reauth_required: '.$label);
    }
    return [];
  }
}

/* -------------------- Build snapshot (last 90 days) -------------------- */
function sm_user_snapshot(PDO $pdo, int $userId): array {
  $CATS = sm_load_categories($pdo);
  $from = date('Y-m-d', strtotime('-90 days'));
  $to   = sm_now_ymd();

  $conns = tlAllConnections($pdo, $userId);
  if (!$conns) return ['from'=>$from,'to'=>$to,'currency'=>'GBP','accounts'=>[],'totals'=>['spend'=>0,'income'=>0,'net'=>0],'by_category'=>[],'recent'=>[],'issues'=>[]];

  $accounts=[]; $recent=[]; $byCat=[]; $spend=0.0; $income=0.0; $currency='GBP';
  $issues=[];

  foreach ($conns as $conn) {
    foreach (sm_items_for_conn($pdo,$conn) as $it) {
      $accounts[] = ['id'=>$it['id'],'type'=>$it['type'],'label'=>$it['label'],'currency'=>$it['currency']];
      try {
        $rows = sm_fetch_tx($pdo, $conn, $it, $from, $to);
      } catch (RuntimeException $rx) {
        if (str_starts_with($rx->getMessage(), 'reauth_required')) {
          $issues[] = ['type'=>'reauth_required','item'=>$it];
          continue;
        }
        continue;
      } catch (Throwable $e) { continue; }

      foreach ($rows as $tx) {
        $ts   = $tx['timestamp'] ?? ($tx['transaction_date'] ?? ($tx['posted_date'] ?? ''));
        $amtR = $tx['amount'] ?? ($tx['transaction_amount']['amount'] ?? 0);
        $cur  = $tx['currency'] ?? ($tx['transaction_amount']['currency'] ?? ($it['currency'] ?? 'GBP'));
        $currency = $cur ?: $currency;
        $amt = is_numeric($amtR) ? (float)$amtR : 0.0;
        $desc= $tx['description'] ?? ($tx['merchant_name'] ?? '');

        $friendly = null;
        $mcc = $tx['merchant_category_code'] ?? ($tx['mcc'] ?? null);
        if ($mcc && ($c = sm_mcc_category($mcc))) $friendly = $c;
        if (!$friendly && $desc && ($c = sm_heur_category($desc))) $friendly = $c;
        if (!$friendly) {
          foreach (['transaction_classification','transaction_category','category','merchant_category'] as $k) {
            if (!isset($tx[$k])) continue;
            $v = $tx[$k];
            if (is_array($v) && isset($v[0]) && is_string($v[0]) && $v[0] !== '') { $friendly = sm_generic_to_friendly($v[0], $amt, $desc); break; }
            if (is_string($v) && $v !== '') { $friendly = sm_generic_to_friendly($v, $amt, $desc); break; }
          }
        }
        if (!$friendly) $friendly = ($amt > 0 ? 'Income' : 'Other');
        [$catId, $catName] = sm_map_to_db($friendly, $amt, $CATS);

        if ($amt >= 0) $income += $amt; else $spend += $amt;
        if ($amt < 0) {
          if (!isset($byCat[$catId])) $byCat[$catId]=['id'=>$catId,'name'=>$catName,'spend'=>0.0];
          $byCat[$catId]['spend'] += -$amt;
        }

        $recent[] = [
          'date'=> substr((string)$ts,0,10),
          'description'=> $desc ?: '(no description)',
          'category'=> $catName,
          'amount'=> $amt,
          'currency'=> $currency
        ];
      }
    }
  }

  usort($recent, fn($a,$b)=> strcmp($b['date'],$a['date']));
  $recent = array_slice($recent, 0, 25);
  usort($byCat, fn($a,$b)=> $b['spend'] <=> $a['spend']);

  return [
    'from'=>$from,'to'=>$to,'currency'=>$currency,
    'accounts'=>$accounts,
    'totals'=>['spend'=>$spend,'income'=>$income,'net'=>$income+$spend],
    'by_category'=>array_values($byCat),
    'recent'=>$recent,
    'issues'=>$issues
  ];
}

/* -------------------- Guards -------------------- */
function sm_is_image_request(string $msg): bool {
  $q = strtolower($msg);
  return (bool)preg_match('/\b(dalle|image|picture|photo|logo|icon|banner|poster|flyer|draw|sketch|generate\s+image|edit\s+image|png|jpeg|jpg)\b/', $q);
}
function sm_is_obviously_offtopic(string $msg): bool {
  return (bool)preg_match('/\b(joke|poem|story|lyrics|song|image|picture|game|code|program)\b/i', $msg);
}

/* -------------------- Main entry -------------------- */
function sm_ai_reply(PDO $pdo, int $userId, string $userMessage, ?string $previousResponseId=null): array {
  $apiKey = sm_env_key_groq();

  if (sm_is_image_request($userMessage)) {
    return ['reply'=>"I’m a text-only financial assistant. I can’t create or edit images. Ask me about your spending, income, budgets, or trends instead.", 'response_id'=>null];
  }

  $snapshot = sm_user_snapshot($pdo, $userId);

  if (empty($snapshot['recent']) && !empty($snapshot['issues'])) {
    $names = array_values(array_unique(array_map(fn($i)=>$i['item']['label'] ?? 'an account', $snapshot['issues'])));
    $list  = implode(', ', $names);
    $msg = "I can’t access your transactions because one or more connections need re-authentication (e.g., {$list}). Please re-auth the affected bank/card and ask again.";
    return ['reply'=>$msg, 'response_id'=>null];
  }

  $system = <<<SYS
You are SimpleMoney AI, a concise **financial assistant** for a single user.
Use only the "Customer Data" provided. If some accounts are missing due to "issues" (e.g., reauth_required), mention that briefly and answer from the remaining data.
Do not give personalised investment advice. Use GBP formatting (e.g., £1,234.56). Be numeric and concise and reference which slice of the Customer Data you used (e.g., "last 90 days totals", "top categories", "recent transactions").
SYS;

  if (sm_is_obviously_offtopic($userMessage)) {
    $userMessage = "USER ASKED (might be off-topic): ".$userMessage."\nPolitely steer back to financial topics using their data.";
  }

  $http = new Client(['base_uri'=>SM_GROQ_BASE, 'timeout'=>SM_AI_TIMEOUT]);

  $messages = [
    ['role'=>'system', 'content'=>$system],
    ['role'=>'user',   'content'=>"Customer Data (JSON):\n".sm_dd($snapshot)."\n\nUser Question:\n".$userMessage]
  ];

  try {
    $res = $http->post(SM_GROQ_PATH, [
      'headers'=>[
        'Authorization'=>"Bearer {$apiKey}",
        'Content-Type'=>'application/json',
        'Accept'=>'application/json',
        'User-Agent'=>'SimpleMoneyAI/1.0'
      ],
      'json'=>[
        'model'       => SM_GROQ_MODEL,
        'temperature' => 0.2,
        'max_tokens'  => 700,
        'messages'    => $messages
      ]
    ]);
  } catch (GuzzleException $e) {
    $msg = $e->getMessage();
    if (stripos($msg, 'timed out') !== false)  throw new RuntimeException('groq_timeout: request timed out.');
    if (stripos($msg, 'SSL') !== false)        throw new RuntimeException('groq_ssl_error: certificate verify failed.');
    throw new RuntimeException('groq_http: '.$msg);
  }

  $j = json_decode((string)$res->getBody(), true);
  $reply = $j['choices'][0]['message']['content'] ?? '';
  if ($reply === '') $reply = "I couldn't produce an answer from your data. Try asking about spending by category, recent merchants, or this month’s net.";

  return ['reply'=>$reply, 'response_id'=>$j['id'] ?? null];
}
