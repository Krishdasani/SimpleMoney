<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/auth_guard.php';
$userId = requireUserApi($pdo);

header('Content-Type: application/json; charset=utf-8');

try {
  // adjust table name if yours differs
  $stmt = $pdo->query('SELECT id, name FROM categories ORDER BY id');
  $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  echo json_encode(['items' => $items]);
} catch (Throwable $e) {
  error_log('categories.php error: '.$e->getMessage());
  http_response_code(500);
  echo json_encode(['error' => 'server_error']);
}
