<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/crypto.php';
require_once __DIR__ . '/../../src/auth_guard.php';
$userId = requireUser($pdo); // ensure user exists

if (session_status() === PHP_SESSION_NONE) session_start();

$code      = $_GET['code'] ?? null;
$state     = $_GET['state'] ?? null;

if (!$code || !$state || !hash_equals($_SESSION['tl_state'] ?? '', $state)) {
  http_response_code(400); exit('Invalid state/code');
}

$tokenUrl  = $_ENV['TL_TOKEN_URL'] ?? '';
$clientId  = $_ENV['TL_CLIENT_ID'] ?? '';
$secret    = $_ENV['TL_CLIENT_SECRET'] ?? '';
$redirect  = $_ENV['TL_REDIRECT_URI'] ?? '';
$verifier  = $_SESSION['tl_pkce_verifier'] ?? '';

if ($tokenUrl==='' || $clientId==='' || $secret==='' || $redirect==='' || $verifier==='') {
  http_response_code(500); exit('Env/PKCE not set');
}

// Exchange code → tokens (Guzzle)
require_once __DIR__ . '/../../vendor/autoload.php';
$client = new \GuzzleHttp\Client(['timeout' => 15]);

try {
  $resp = $client->post($tokenUrl, [
    'form_params' => [
      'grant_type' => 'authorization_code',
      'client_id' => $clientId,
      'client_secret' => $secret,
      'redirect_uri' => $redirect,
      'code' => $code,
      'code_verifier' => $verifier,
    ]
  ]);
} catch (\GuzzleHttp\Exception\ClientException $e) {
  http_response_code(400);
  $body = $e->getResponse() ? (string)$e->getResponse()->getBody() : '';
  exit('Token exchange failed: '.$e->getMessage().' | '.$body);
}

$data = json_decode((string)$resp->getBody(), true);
$access  = $data['access_token']  ?? '';
$refresh = $data['refresh_token'] ?? '';
$expiresIn = (int)($data['expires_in'] ?? 0);
$scope   = $data['scope'] ?? '';

if ($access === '' || $refresh === '' || $expiresIn <= 0) {
  http_response_code(400); exit('Missing tokens');
}

// Save connection (encrypted tokens)
$ins = $pdo->prepare('INSERT INTO tl_connections (user_id, provider, access_token, refresh_token, expires_at, scope)
                      VALUES (?, ?, ?, ?, ?, ?)');
$expAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify("+{$expiresIn} seconds")->format('Y-m-d H:i:s');
$ins->execute([
  $userId,
  'truelayer',
  enc($access),
  enc($refresh),
  $expAt,
  $scope,
]);

// Clean session vars
unset($_SESSION['tl_state'], $_SESSION['tl_pkce_verifier']);

// Back to Connect page (next step will sync accounts)
header('Location: /SimpleMoney/public/connect.php');
exit;
