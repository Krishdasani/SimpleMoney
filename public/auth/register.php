<?php
// /public/auth/register.php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use Firebase\JWT\JWT;

function base64url(string $bin): string {
  return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function setCookieSafe(string $name, string $value, int $ttlSeconds): void {
  setcookie($name, $value, [
    'expires'  => time() + $ttlSeconds,
    'path'     => '/',
    'secure'   => true,      // HTTPS only
    'httponly' => true,      // not readable by JS
    'samesite' => 'Lax',
  ]);
}

function newAccessToken(int $userId, string $secret): string {
  $now = time();
  $payload = [
    'sub' => $userId,
    'iat' => $now,
    'exp' => $now + 1800, // 30 minutes
    'iss' => 'simplemoney',
  ];
  return JWT::encode($payload, $secret, 'HS256');
}

function issueTokens(PDO $pdo, int $userId, string $jwtSecret): void {
  // Access token (short)
  $access = newAccessToken($userId, $jwtSecret);
  setCookieSafe('sm_at', $access, 1800); // 30 min

  // Refresh token (long)
  $raw = random_bytes(32);
  $refresh = base64url($raw);
  $hash = hash('sha256', $refresh);

  $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
  $exp = $now->modify('+60 days');

  $stmt = $pdo->prepare(
    'INSERT INTO user_refresh_tokens(user_id, token_hash, issued_at, expires_at, user_agent, ip)
     VALUES (?, ?, ?, ?, ?, ?)'
  );
  $stmt->execute([
    $userId,
    $hash,
    $now->format('Y-m-d H:i:s'),
    $exp->format('Y-m-d H:i:s'),
    $_SERVER['HTTP_USER_AGENT'] ?? null,
    $_SERVER['REMOTE_ADDR'] ?? null,
  ]);

  setCookieSafe('sm_rt', $refresh, 60 * 24 * 60 * 60); // 60 days
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  exit('Method Not Allowed');
}

$email = strtolower(trim($_POST['email'] ?? ''));
$password = (string)($_POST['password'] ?? '');

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
  // You can set a flash message and redirect to /signup.php
  http_response_code(400);
  exit('Invalid email or password (min 8 chars).');
}

// Ensure email not taken
$st = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
$st->execute([$email]);
if ($st->fetch()) {
  http_response_code(409);
  exit('Email already registered.');
}

// Create user
$hash = password_hash($password, PASSWORD_BCRYPT);
$ins = $pdo->prepare('INSERT INTO users (email, password_hash) VALUES (?, ?)');
$ins->execute([$email, $hash]);
$userId = (int)$pdo->lastInsertId();

// Issue cookies (JWT access + refresh)
$jwtSecret = $_ENV['JWT_SECRET'] ?? '';
if ($jwtSecret === '') { http_response_code(500); exit('JWT secret not set'); }

issueTokens($pdo, $userId, $jwtSecret);

// Redirect to dashboard
header('Location: /dashboard.php');
exit;
