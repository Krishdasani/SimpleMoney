<?php
// src/bootstrap.php
declare(strict_types=1);

/**
 * SimpleMoney bootstrap:
 * - Load .env
 * - Configure error reporting
 * - Start session
 * - Create $pdo (PDO MySQL)
 * - Provide helpers: env(), redirect()
 */

// ---- 1) Load .env (tiny loader; no external package) ----
if (!function_exists('env')) {
  function env(string $key, ?string $default = null): ?string {
    return $_ENV[$key] ?? getenv($key) ?: $default;
  }
}

$envPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';
if (is_readable($envPath)) {
  $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
  foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#') continue;
    [$k, $v] = array_map('trim', explode('=', $line, 2) + [null, null]);
    if ($k === null) continue;
    // Strip surrounding quotes
    if ($v !== null && ((str_starts_with($v, '"') && str_ends_with($v, '"')) || (str_starts_with($v, "'") && str_ends_with($v, "'")))) {
      $v = substr($v, 1, -1);
    }
    $_ENV[$k] = $v;
    putenv("$k=$v");
  }
}

// ---- 2) Error reporting (friendlier in local) ----
$env = env('APP_ENV', 'local');
if ($env === 'local') {
  ini_set('display_errors', '1');
  ini_set('display_startup_errors', '1');
  error_reporting(E_ALL);
} else {
  ini_set('display_errors', '0');
  error_reporting(E_ALL & ~E_NOTICE & ~E_STRICT & ~E_DEPRECATED);
}

// ---- 3) Sessions (still useful for flash messages/state) ----
if (session_status() === PHP_SESSION_NONE) {
  $sessionName = env('SESSION_NAME', 'sm_sid');
  session_name($sessionName);
  session_start();
}

// ---- 4) PDO MySQL connection ----
$dsn  = env('DB_DSN', 'mysql:host=127.0.0.1;dbname=simplemoney;charset=utf8mb4');
$user = env('DB_USER', 'root');
$pass = env('DB_PASS', '');

try {
  $pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
  ]);
} catch (Throwable $e) {
  http_response_code(500);
  // Minimal safe message; check Apache/PHP error log for details
  exit('Database connection failed.');
}

// ---- 5) Small helpers ----
function base_url(): string {
  $cfg = rtrim(env('BASE_URL', ''), '/');
  if ($cfg !== '') return $cfg;

  // Fallback: build from server vars (for localhost dev)
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
  $script = $_SERVER['SCRIPT_NAME'] ?? '/';
  // assume /public as web root
  $root = preg_replace('#/public/.*$#', '/public', $script);
  return "$scheme://$host$root";
}

function redirect(string $path): never {
  // Accept absolute or relative
  if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
    header('Location: ' . $path);
  } else {
    header('Location: ' . rtrim(base_url(), '/') . $path);
  }
  exit;
}
