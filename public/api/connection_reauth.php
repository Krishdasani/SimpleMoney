<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/auth_guard.php';
require_once __DIR__ . '/../../src/tl_api.php';

header('Content-Type: application/json; charset=utf-8');

try {
  $userId = requireUserApi($pdo);
  $body = json_decode(file_get_contents('php://input'), true) ?: [];
  $action = (string)($body['action'] ?? '');
  $id = isset($body['id']) ? (int)$body['id'] : 0;

  // Helper: compute /public base for fallbacks
  $script = $_SERVER['SCRIPT_NAME'] ?? '';
  $pos = stripos($script, '/public/');
  $publicBase = $pos !== false ? substr($script, 0, $pos + 7) : '/SimpleMoney/public';

  // Try to build a URL using whatever you already have in tl_api.php
  // Prefer a dedicated reauth link if your tl_api provides one; otherwise use the normal consent builder.
  $url = null;

  if ($action === 'reauth' && $id > 0) {
    try {
      if (function_exists('tlBuildReauthUrl')) {
        $url = tlBuildReauthUrl($pdo, $userId, $id);
      }
    } catch (Throwable $e) { /* fallthrough */ }
  }

  if (!$url) {
    try {
      if (function_exists('tlBuildAuthUrl')) {
        $url = tlBuildAuthUrl($pdo, $userId, []); // general consent flow
      }
    } catch (Throwable $e) { /* fallthrough */ }
  }

  // Last resort: fallback to any page you already use to start the flow
  if (!$url) $url = $publicBase . '/connect_start.php';

  echo json_encode(['ok'=>true, 'url'=>$url]);
} catch (Throwable $e) {
  error_log('connection_reauth: ' . $e->getMessage());
  http_response_code(500);
  echo json_encode(['ok'=>false, 'error'=>'server_error']);
}
