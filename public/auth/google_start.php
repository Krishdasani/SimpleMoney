<?php
// /public/auth/google_start.php
declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/bootstrap.php';
use League\OAuth2\Client\Provider\Google;

if (session_status() === PHP_SESSION_NONE) session_start();

$clientId     = $_ENV['GOOGLE_CLIENT_ID'] ?? '';
$clientSecret = $_ENV['GOOGLE_CLIENT_SECRET'] ?? '';
$redirectUri  = $_ENV['GOOGLE_REDIRECT_URI'] ?? '';

if ($clientId === '' || $clientSecret === '' || $redirectUri === '') {
  http_response_code(500);
  exit('Google OAuth not configured in .env');
}

$provider = new Google([
  'clientId'     => $clientId,
  'clientSecret' => $clientSecret,
  'redirectUri'  => $redirectUri,
]);

$authUrl = $provider->getAuthorizationUrl([
  'scope' => ['openid', 'email', 'profile'],
  // prompt => 'select_account' optional
]);
$_SESSION['oauth2state'] = $provider->getState();

header('Location: ' . $authUrl);
exit;
