<?php

declare(strict_types=1);

/**
 * AES-256-GCM encrypt/decrypt for storing TL tokens securely.
 * Key comes from TL_TOKEN_KEY in .env (must be 32 bytes, hex-encoded).
 */

function enc(string $plaintext): string {
  $keyHex = $_ENV['TL_TOKEN_KEY'] ?? '';
  $key = hex2bin($keyHex);
  if (!$key || strlen($key) !== 32) {
    throw new RuntimeException('Bad TL_TOKEN_KEY: must be 32 bytes hex');
  }

  $iv = random_bytes(12);
  $tag = '';
  $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
  if ($cipher === false) {
    throw new RuntimeException('Encryption failed');
  }
  return base64_encode($iv.$tag.$cipher);
}

function dec(string $b64): string {
  $keyHex = $_ENV['TL_TOKEN_KEY'] ?? '';
  $key = hex2bin($keyHex);
  if (!$key || strlen($key) !== 32) {
    throw new RuntimeException('Bad TL_TOKEN_KEY: must be 32 bytes hex');
  }

  $raw = base64_decode($b64, true);
  if ($raw === false || strlen($raw) < 28) {
    throw new RuntimeException('Invalid ciphertext');
  }

  $iv = substr($raw, 0, 12);
  $tag = substr($raw, 12, 16);
  $cipher = substr($raw, 28);

  $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
  if ($plain === false) {
    throw new RuntimeException('Decryption failed');
  }
  return $plain;
}
