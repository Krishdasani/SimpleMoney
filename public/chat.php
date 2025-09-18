<?php
declare(strict_types=1);

// If your app already loads .env globally, this is harmless.
// If you have no bootstrap_env.php, this line is safely ignored.
$bootstrap = __DIR__ . '/../../src/bootstrap_env.php';
if (is_file($bootstrap)) require_once $bootstrap;

require_once __DIR__ . '/../../src/ai_chat.php';

header('Content-Type: application/json; charset=utf-8');

try {
  $userId = requireUserApi($pdo);

  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok'=>false, 'error'=>'method_not_allowed']); exit;
  }

  $body = json_decode(file_get_contents('php://input'), true) ?: [];
  $message = trim((string)($body['message'] ?? ''));
  $prev    = isset($body['previous_response_id']) ? (string)$body['previous_response_id'] : null;

  if ($message === '') {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'missing_message']); exit;
  }

  $out = sm_ai_reply($pdo, $userId, $message, $prev);
  echo json_encode(['ok'=>true, 'reply'=>$out['reply'], 'response_id'=>$out['response_id']]);

} catch (RuntimeException $e) {
  // Common case: OPENAI_API_KEY not found, etc.
  error_log('chat.php runtime: '.$e->getMessage());
  http_response_code(400);
  $code = (stripos($e->getMessage(), 'OPENAI_API_KEY') !== false) ? 'missing_api_key' : 'runtime_error';
  echo json_encode(['ok'=>false,'error'=>$code, 'detail'=>$e->getMessage()]);

} catch (Throwable $e) {
  error_log('chat.php error: '.$e->getMessage());
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'server_error','detail'=>'See server logs for details.']);
}
