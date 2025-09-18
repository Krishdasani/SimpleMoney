<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/crypto.php'; // enc()/dec()

use GuzzleHttp\Client;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * Return ALL TrueLayer connection rows for a user (newest first).
 */
function tlAllConnections(PDO $pdo, int $userId): array {
  $st = $pdo->prepare('SELECT * FROM tl_connections WHERE user_id = ? ORDER BY id DESC');
  $st->execute([$userId]);
  $rows = $st->fetchAll();
  return $rows ?: [];
}

/**
 * Get the most recent TrueLayer connection row for a user.
 */
function tlActiveConnection(PDO $pdo, int $userId): ?array {
  $st = $pdo->prepare('SELECT * FROM tl_connections WHERE user_id=? ORDER BY id DESC LIMIT 1');
  $st->execute([$userId]);
  $row = $st->fetch();
  return $row ?: null;
}

/**
 * Safely fetch a token from a tl_connections row.
 */
function tlGetToken(PDO $pdo, array $conn, string $field): string {
  if (!isset($conn[$field])) throw new RuntimeException("Missing field $field");
  $raw = $conn[$field];

  $b64 = base64_decode($raw, true);
  $looksEncrypted = ($b64 !== false && strlen($b64) >= 28);

  if (!$looksEncrypted) {
    $plain = $raw;
    try {
      $encVal = enc($plain);
      $upd = $pdo->prepare("UPDATE tl_connections SET {$field}=? WHERE id=?");
      $upd->execute([$encVal, $conn['id']]);
    } catch (\Throwable $e) { }
    return $plain;
  }

  return dec($raw);
}

/**
 * Build a detached JWS signature manually with ES256.
 */
function tlSign(string $method, string $path, string $body = '', array $signedHeaders = []): string {
  $pem = $_ENV['TL_PRIVATE_KEY_PATH'] ?? '';
  if (!is_file($pem)) {
    throw new RuntimeException('TL private key not found. Check TL_PRIVATE_KEY_PATH in .env');
  }

  $header = [
    'alg' => 'ES256',
    'typ' => 'JWT',
  ];
  $payload = [
    'method' => strtoupper($method),
    'path'   => $path,
    'body'   => $body ?? '',
    'ts'     => time(),
  ];

  // Sign with your private key
  return JWT::encode($payload, file_get_contents($pem), 'ES256', null, $header);
}

/**
 * Refresh access token using refresh_token; updates DB and returns new access token.
 */
function tlRefresh(PDO $pdo, array $conn): string {
  $tokenUrl = $_ENV['TL_TOKEN_URL'] ?? '';
  $clientId = $_ENV['TL_CLIENT_ID'] ?? '';
  $secret   = $_ENV['TL_CLIENT_SECRET'] ?? '';
  if (!$tokenUrl || !$clientId || !$secret) {
    throw new RuntimeException('TL env missing (token url / client id / secret)');
  }

  $refreshPlain = tlGetToken($pdo, $conn, 'refresh_token');
  $client = new Client(['timeout' => 15]);

  $resp = $client->post($tokenUrl, [
    'form_params' => [
      'grant_type'    => 'refresh_token',
      'client_id'     => $clientId,
      'client_secret' => $secret,
      'refresh_token' => $refreshPlain,
    ],
  ]);

  $data = json_decode((string)$resp->getBody(), true);
  $newAccess  = $data['access_token']  ?? '';
  $newRefresh = $data['refresh_token'] ?? $refreshPlain;
  $expiresIn  = (int)($data['expires_in'] ?? 0);
  if ($newAccess === '' || $expiresIn === 0) {
    throw new RuntimeException('Bad refresh response');
  }

  $expAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify("+{$expiresIn} seconds")->format('Y-m-d H:i:s');

  $upd = $pdo->prepare('UPDATE tl_connections SET access_token=?, refresh_token=?, expires_at=? WHERE id=?');
  $upd->execute([ enc($newAccess), enc($newRefresh), $expAt, $conn['id'] ]);

  return $newAccess;
}

/**
 * Core GET with signing + auto-refresh.
 */
function tlGet(PDO $pdo, array $conn, string $path): array {
  $apiBase = rtrim($_ENV['TL_API_BASE'] ?? 'https://api.truelayer-sandbox.com', '/');
  $client  = new Client(['base_uri' => $apiBase, 'timeout' => 20]);

  $access  = tlGetToken($pdo, $conn, 'access_token');
  $sig     = tlSign('GET', $path, '');

  try {
    $res = $client->get($path, [
      'headers' => [
        'Authorization' => "Bearer {$access}",
        'Tl-Signature'  => $sig,
      ],
    ]);
  }    
  catch (\GuzzleHttp\Exception\ClientException $e) {
    $status = $e->getResponse() ? $e->getResponse()->getStatusCode() : 0;
    if ($status === 401) {
      $newAccess = tlRefresh($pdo, $conn);
      $sig2 = tlSign('GET', $path, '');
      $res = $client->get($path, [
        'headers' => [
          'Authorization' => "Bearer {$newAccess}",
          'Tl-Signature'  => $sig2,
        ],
      ]);
    } else {
      $body = $e->getResponse() ? (string)$e->getResponse()->getBody() : '';
      error_log("tlGet {$path} {$status} {$body}");
      throw $e;
    }
  }

  

  $json = (string)$res->getBody();
  $data = json_decode($json, true);
  if ($data === null) {
    error_log("tlGet json decode failed: {$json}");
    throw new RuntimeException('json_decode_failed');
  }
  return $data;
}
