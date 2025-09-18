<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/auth_guard.php';

header('Content-Type: application/json; charset=utf-8');

try {
  $userId = requireUserApi($pdo);
  $body = json_decode(file_get_contents('php://input'), true) ?: [];
  $id   = (int)($body['id'] ?? 0);
  if ($id <= 0) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'bad_id']); exit; }

  $stmt = $pdo->prepare('DELETE FROM tl_connections WHERE id = :id AND user_id = :uid');
  $stmt->execute([':id'=>$id, ':uid'=>$userId]);

  echo json_encode(['ok'=>true]);
} catch (Throwable $e) {
  error_log('connection_delete: ' . $e->getMessage());
  http_response_code(500);
  echo json_encode(['ok'=>false, 'error'=>'server_error']);
}
