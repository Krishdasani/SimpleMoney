<?php
declare(strict_types=1);

// SimpleMoney • Logout
require_once __DIR__ . '/../../src/auth_guard.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$userId = $_SESSION['user_id'] ?? null;

/* --- Try to revoke any app refresh tokens for this user (if your table/column exists) --- */
if ($userId) {
    try {
        // If you have a 'revoked' boolean and 'revoked_at' timestamp, this marks them as revoked.
        // If your schema differs, this will just fail silently and continue.
        $stmt = $pdo->prepare("
            UPDATE user_refresh_tokens
               SET revoked = 1, revoked_at = NOW()
             WHERE user_id = :uid AND (revoked = 0 OR revoked IS NULL)
        ");
        $stmt->execute([':uid' => $userId]);
    } catch (Throwable $e) {
        error_log('logout: token revoke skipped - ' . $e->getMessage());
    }
}

/* --- Destroy session --- */
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params['path'], $params['domain'],
        $params['secure'], $params['httponly']
    );
}
session_destroy();

/* --- Clear any app auth cookies you might use --- */
@setcookie('sm_auth', '', time() - 3600, '/');
@setcookie('jwt',     '', time() - 3600, '/');

/* --- Compute correct /public base and redirect to login --- */
if (!function_exists('sm_public_base')) {
    function sm_public_base(): string {
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        $pos = stripos($script, '/public/');
        if ($pos !== false) return substr($script, 0, $pos + 7); // includes "/public"
        return '/SimpleMoney/public'; // fallback
    }
}
$base = sm_public_base();
header('Location: ' . $base . '/login.php?logged_out=1');
exit;
