<?php
// /public/auth/login.php
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
    'secure'   => true,
    'httponly' => true,
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
  $access = newAccessToken($userId, $jwtSecret);
  setCookieSafe('sm_at', $access, 1800);

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

  setCookieSafe('sm_rt', $refresh, 60 * 24 * 60 * 60);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  exit('Method Not Allowed');
}

$email = strtolower(trim($_POST['email'] ?? ''));
$password = (string)($_POST['password'] ?? '');

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
  http_response_code(400);
  exit('Invalid email');
}

$st = $pdo->prepare('SELECT id, password_hash FROM users WHERE email = ? LIMIT 1');
$st->execute([$email]);
$user = $st->fetch();

if (!$user) {
  http_response_code(401);
  exit('Invalid credentials');
}

// If this account was created via Google (password_hash NULL/empty)
if (empty($user['password_hash'])) {
  http_response_code(400);
  exit('This account uses Google Sign-In. Please choose “Continue with Google”.');
}

if (!password_verify($password, $user['password_hash'])) {
  http_response_code(401);
  exit('Invalid credentials');
}

$userId = (int)$user['id'];
$jwtSecret = $_ENV['JWT_SECRET'] ?? '';
if ($jwtSecret === '') { http_response_code(500); exit('JWT secret not set'); }

// Issue cookies (JWT access + refresh)
issueTokens($pdo, $userId, $jwtSecret);

// Redirect to dashboard
header('Location: /public/dashboard.php');
exit;
    