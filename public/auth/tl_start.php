<?php
declare(strict_types=1);

/**
 * TrueLayer consent start (PKCE)
 * Minimal, robust: valid scopes, no provider filters (they can cause Bad Request).
 * Add ?debug=1 to see the built URL instead of redirecting.
 */

require_once __DIR__ . '/../../src/bootstrap.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$clientId = env('TL_CLIENT_ID', '');
$redirect = env('TL_REDIRECT_URI', ''); // MUST match Console exactly
$authBase = rtrim(env('TL_AUTH_BASE', 'https://auth.truelayer.com'), '/');

if ($clientId === '' || $redirect === '' || $authBase === '') {
  http_response_code(500);
  echo "TrueLayer env not configured. Check TL_CLIENT_ID / TL_REDIRECT_URI / TL_AUTH_BASE.";
  exit;
}

/* Valid scopes (space-separated). Do NOT use cards:* variants, or “balances”. */
$scope = 'info accounts balance transactions cards offline_access';

/* PKCE */
$state         = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
$codeVerifier  = rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
$codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

$_SESSION['tl_state']         = $state;
$_SESSION['tl_pkce_verifier'] = $codeVerifier;

$params = [
  'response_type'         => 'code',
  'client_id'             => $clientId,
  'redirect_uri'          => $redirect,            // must exactly match Console
  'scope'                 => $scope,
  'state'                 => $state,
  'code_challenge'        => $codeChallenge,
  'code_challenge_method' => 'S256',
  // Avoid provider filters; they can trigger “Bad Request” if invalid.
  // 'providers' => 'uk-ob-all', // <- leave unset
];

// Debug mode to inspect the final URL & params in-browser
if ((isset($_GET['debug']) && $_GET['debug'] === '1')) {
  header('Content-Type: text/plain; charset=utf-8');
  echo "Auth Base: {$authBase}\n";
  echo "Redirect : {$redirect}\n";
  echo "Client ID: " . substr($clientId, 0, 6) . "…\n";
  echo "Scope    : {$scope}\n";
  echo "State    : {$state}\n";
  echo "CodeChal : {$codeChallenge}\n\n";
  echo "FINAL URL:\n" . $authBase . '/?' . http_build_query($params) . "\n";
  exit;
}

header('Location: ' . $authBase . '/?' . http_build_query($params));
exit;
