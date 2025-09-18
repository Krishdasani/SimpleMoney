<?php
// /public/auth/google_callback.php
declare(strict_types=1);

// RIGHT (goes 2 levels up to project root: SimpleMoney)
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/auth_tokens.php';

use League\OAuth2\Client\Provider\Google;

if (session_status() === PHP_SESSION_NONE) session_start();

$clientId     = $_ENV['GOOGLE_CLIENT_ID'] ?? '';
$clientSecret = $_ENV['GOOGLE_CLIENT_SECRET'] ?? '';
$redirectUri  = $_ENV['GOOGLE_REDIRECT_URI'] ?? '';
$jwtSecret    = $_ENV['JWT_SECRET'] ?? '';

// TEMP DEBUG — remove after testing
//  var_dump('clientId='.substr($clientId,0,10), 'secretLen='.strlen($clientSecret), 'redirect='.$redirectUri); exit;


if ($clientId === '' || $clientSecret === '' || $redirectUri === '' || $jwtSecret === '') {
  http_response_code(500);
  exit('Env not configured');
}

if (empty($_GET['state']) || ($_GET['state'] !== ($_SESSION['oauth2state'] ?? ''))) {
  unset($_SESSION['oauth2state']);
  http_response_code(400);
  exit('Invalid state');
}
if (!isset($_GET['code'])) {
  http_response_code(400);
  exit('Missing code');
}

$provider = new Google([
  'clientId'     => $clientId,
  'clientSecret' => $clientSecret,
  'redirectUri'  => $redirectUri,
]);

try {
  $token = $provider->getAccessToken('authorization_code', ['code' => $_GET['code']]);
  $googleUser = $provider->getResourceOwner($token);
} catch (\GuzzleHttp\Exception\ClientException $e) {
  http_response_code(400);
  $body = $e->getResponse() ? (string)$e->getResponse()->getBody() : '';
  exit('Token exchange failed: ' . $e->getMessage() . ' | ' . $body);
} catch (\Throwable $e) {
  http_response_code(400);
  exit('Token exchange failed: ' . $e->getMessage());
}

$email = strtolower($googleUser->getEmail() ?? '');
$name  = $googleUser->getName() ?? '';
$sub   = $googleUser->getId() ?? '';

if ($email === '' || $sub === '') {
  http_response_code(400);
  exit('Invalid Google profile');
}

// Upsert user
$pdo->beginTransaction();

// If you added columns google_sub + name (recommended):
// ALTER TABLE users ADD COLUMN google_sub VARCHAR(64) NULL UNIQUE AFTER email;
// ALTER TABLE users ADD COLUMN name VARCHAR(120) NULL AFTER google_sub;
// ALTER TABLE users MODIFY password_hash VARCHAR(255) NULL;

$sel = $pdo->prepare('SELECT id FROM users WHERE google_sub = ? OR email = ? LIMIT 1');
$sel->execute([$sub, $email]);
$row = $sel->fetch();

if ($row) {
  $userId = (int)$row['id'];
  $upd = $pdo->prepare('UPDATE users SET google_sub = COALESCE(google_sub, ?), name = COALESCE(name, ?) WHERE id = ?');
  $upd->execute([$sub, $name, $userId]);
} else {
  $ins = $pdo->prepare('INSERT INTO users (email, google_sub, name, password_hash) VALUES (?, ?, ?, NULL)');
  $ins->execute([$email, $sub, $name]);
  $userId = (int)$pdo->lastInsertId();
}

$pdo->commit();

// Issue cookies (JWT access + refresh)
issueTokens($pdo, $userId, $jwtSecret);

// Go to dashboard
header('Location: /simplemoney/public/dashboard.php');
exit;
