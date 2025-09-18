<?php
declare(strict_types=1);

/**
 * Auth guard utilities for SimpleMoney
 * - requireUser()     -> for normal pages (redirects to login on failure)
 * - requireUserApi()  -> for JSON APIs (returns 401 JSON on failure)
 * - tryRefresh()      -> rotates refresh token + issues new cookies
 *
 * This version is BACKWARD-COMPATIBLE with DBs that don't yet have:
 *   - user_refresh_tokens.revoked
 *   - issued_at / expires_at / user_agent / ip
 * It feature-detects columns and adapts INSERT/UPDATE/SELECT accordingly.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/auth_tokens.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

function hasColumn(PDO $pdo, string $table, string $column): bool {
  try {
    $stmt = $pdo->prepare(
      "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $stmt->execute([$table, $column]);
    return (bool)$stmt->fetchColumn();
  } catch (\Throwable $e) {
    // If INFORMATION_SCHEMA isn't available (rare), fall back pessimistically
    return false;
  }
}

/**
 * Decode access token cookie (sm_at) and return user id, or null.
 */
function decodeAccess(?string $jwt, string $secret): ?int {
  if (!$jwt) return null;
  try {
    $decoded = JWT::decode($jwt, new Key($secret, 'HS256'));
    $sub = (int)($decoded->sub ?? 0);
    if ($sub <= 0) return null;
    $exp = (int)($decoded->exp ?? 0);
    if ($exp > 0 && $exp < time()) return null;
    return $sub;
  } catch (\Throwable $e) {
    error_log('AuthGuard: decode sm_at failed: '.$e->getMessage());
    return null;
  }
}

/**
 * Attempt a refresh using sm_rt cookie. On success:
 * - sets new sm_at (short-lived)
 * - rotates sm_rt (long-lived)
 * - returns user id
 * Returns null if refresh is not possible.
 */
function tryRefresh(PDO $pdo): ?int {
  $secret   = env('JWT_SECRET', 'dev_secret_change_me') ?? 'dev_secret_change_me';
  $issuer   = env('APP_URL', base_url());
  $rtCookie = $_COOKIE['sm_rt'] ?? null;
  if (!$rtCookie) {
    error_log('AuthGuard: tryRefresh: no sm_rt');
    return null;
  }

  $hash = hash('sha256', $rtCookie);

  // ----- Load existing row (SELECT * to survive missing columns) -----
  $stmt = $pdo->prepare("SELECT * FROM user_refresh_tokens WHERE token_hash = ? LIMIT 1");
  $stmt->execute([$hash]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$row) {
    error_log('AuthGuard: tryRefresh: token not found');
    return null;
  }

  // Optional columns (handle if absent)
  $hasRevoked   = array_key_exists('revoked', $row);
  $hasExpiresAt = array_key_exists('expires_at', $row);

  if ($hasRevoked && (int)$row['revoked'] === 1) {
    error_log('AuthGuard: tryRefresh: token revoked');
    return null;
  }
  if ($hasExpiresAt) {
    $expTs = strtotime($row['expires_at'] ?? '1970-01-01 00:00:00');
    if ($expTs !== false && $expTs < time()) {
      error_log('AuthGuard: tryRefresh: token expired');
      return null;
    }
  }

  $userId = (int)($row['user_id'] ?? 0);
  if ($userId <= 0) {
    error_log('AuthGuard: tryRefresh: bad user_id');
    return null;
  }

  // ----- Issue new access (30m) -----
  $now = time();
  $atPayload = [
    'iss' => $issuer,
    'sub' => $userId,
    'iat' => $now,
    'exp' => $now + (30 * 60),
  ];
  $newAccess = JWT::encode($atPayload, $secret, 'HS256');

  // ----- Rotate refresh (60 days) -----
  $newRefresh = base64url(random_bytes(32));
  $newHash    = hash('sha256', $newRefresh);

  // Detect which columns exist for INSERT
  $cIssuedAt  = hasColumn($pdo, 'user_refresh_tokens', 'issued_at');
  $cExpiresAt = hasColumn($pdo, 'user_refresh_tokens', 'expires_at');
  $cUA        = hasColumn($pdo, 'user_refresh_tokens', 'user_agent');
  $cIP        = hasColumn($pdo, 'user_refresh_tokens', 'ip');

  $fields = ['user_id', 'token_hash'];
  $vals   = [$userId, $newHash];

  if ($cIssuedAt) { $fields[] = 'issued_at';  $vals[] = date('Y-m-d H:i:s', $now); }
  if ($cExpiresAt){ $fields[] = 'expires_at'; $vals[] = date('Y-m-d H:i:s', $now + 60*24*60*60); } // 60 days
  if ($cUA)       { $fields[] = 'user_agent'; $vals[] = $_SERVER['HTTP_USER_AGENT'] ?? null; }
  if ($cIP)       { $fields[] = 'ip';         $vals[] = $_SERVER['REMOTE_ADDR'] ?? null; }

  $placeholders = implode(',', array_fill(0, count($fields), '?'));
  $fieldList    = implode(',', $fields);

  $pdo->beginTransaction();
  try {
    $ins = $pdo->prepare("INSERT INTO user_refresh_tokens ($fieldList) VALUES ($placeholders)");
    $ins->execute($vals);

    if ($hasRevoked) {
      $upd = $pdo->prepare("UPDATE user_refresh_tokens SET revoked = 1 WHERE token_hash = ?");
      $upd->execute([$hash]);
    } else {
      // If no 'revoked' column, delete the old row to invalidate it
      $del = $pdo->prepare("DELETE FROM user_refresh_tokens WHERE token_hash = ?");
      $del->execute([$hash]);
    }

    $pdo->commit();
  } catch (\Throwable $e) {
    $pdo->rollBack();
    error_log('AuthGuard: tryRefresh DB error: '.$e->getMessage());
    return null;
  }

  // Cookies (setCookieSafe is defined in auth_tokens.php)
  setCookieSafe('sm_at', $newAccess, 30 * 60);              // 30 minutes
  setCookieSafe('sm_rt', $newRefresh, 60 * 24 * 60 * 60);   // 60 days

  return $userId;
}

/** Require user for normal pages (redirect on failure). */
function requireUser(PDO $pdo): int {
  $secret = env('JWT_SECRET', 'dev_secret_change_me') ?? 'dev_secret_change_me';

  $uid = decodeAccess($_COOKIE['sm_at'] ?? null, $secret);
  if ($uid) return $uid;

  $uid = tryRefresh($pdo);
  if ($uid) return $uid;

  error_log('AuthGuard redirect: decode failed and refresh failed');
  redirect('/public/login.php');
}

/** Require user for API endpoints (401 JSON on failure). */
function requireUserApi(PDO $pdo): int {
  $secret = env('JWT_SECRET', 'dev_secret_change_me') ?? 'dev_secret_change_me';

  $uid = decodeAccess($_COOKIE['sm_at'] ?? null, $secret);
  if ($uid) return $uid;

  $uid = tryRefresh($pdo);
  if ($uid) return $uid;

  http_response_code(401);
  header('Content-Type: application/json');
  echo json_encode(['error' => 'unauthenticated']);
  exit;
}
