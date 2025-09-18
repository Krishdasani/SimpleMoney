<?php
declare(strict_types=1);
use Firebase\JWT\JWT;

function signTlRequest(string $method, string $path, string $body = ''): string {
  $privateKeyPath = $_ENV['TL_PRIVATE_KEY_PATH'] ?? '';
  if (!is_file($privateKeyPath)) throw new RuntimeException("Private key not found");
  $pk = file_get_contents($privateKeyPath);
  $now = time();
  $payload = [
    'iat'=>$now, 'exp'=>$now+300,
    'method'=>strtoupper($method),
    'path'=>$path,
    'body'=>hash('sha256', $body ?? '')
  ];
  return JWT::encode($payload, $pk, 'ES512');
}
