<?php
// /src/auth_tokens.php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';   // <-- ADD THIS

use Firebase\JWT\JWT;

function base64url(string $bin): string {
  return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function setCookieSafe(string $name, string $value, int $ttlSeconds): void {
  setcookie($name, $value, [
    'expires'  => time() + $ttlSeconds,
    'path'     => '/',
    'secure'   => false,   // set to true when you use HTTPS
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
  // Access token
  $access = newAccessToken($userId, $jwtSecret);
  setCookieSafe('sm_at', $access, 1800);

  // Refresh token (store hash in DB)
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
