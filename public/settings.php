<?php
// public/settings.php
declare(strict_types=1);

require_once __DIR__ . '/../src/auth_guard.php';   // provides $pdo + requireUser()
require_once __DIR__ . '/../src/auth_tokens.php';  // for clearing cookies on logout-all
$userId = requireUser($pdo);
$current = 'settings';

// Fetch user
$st = $pdo->prepare('SELECT email, name, password_hash FROM users WHERE id = ?');
$st->execute([$userId]);
$user = $st->fetch(PDO::FETCH_ASSOC);
if (!$user) { http_response_code(404); exit('User not found'); }

// Simple CSRF
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$CSRF = $_SESSION['csrf'];

$flash = ['type'=>null,'msg'=>null];

function flash($type,$msg){ global $flash; $flash=['type'=>$type,'msg'=>$msg]; }
function verify_csrf(){
  if (($_POST['csrf'] ?? '') !== ($_SESSION['csrf'] ?? '')) {
    http_response_code(400); exit('Invalid CSRF');
  }
}

// Handle forms
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';

  if ($action === 'profile') {
    verify_csrf();
    $name = trim($_POST['name'] ?? '');
    if ($name === '' || mb_strlen($name) > 120) {
      flash('error','Please enter a valid name.');
    } else {
      $upd = $pdo->prepare('UPDATE users SET name = ? WHERE id = ?');
      $upd->execute([$name, $userId]);
      $user['name'] = $name;
      flash('success','Profile updated.');
    }
  }

  if ($action === 'password') {
    verify_csrf();
    $current = $_POST['current'] ?? '';
    $new     = $_POST['new'] ?? '';
    $confirm = $_POST['confirm'] ?? '';

    if (!password_verify($current, $user['password_hash'] ?? '')) {
      flash('error','Current password is incorrect.');
    } elseif (strlen($new) < 8) {
      flash('error','New password must be at least 8 characters.');
    } elseif ($new !== $confirm) {
      flash('error','New password and confirmation do not match.');
    } else {
      $hash = password_hash($new, PASSWORD_BCRYPT);
      $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, $userId]);

      // rotate all refresh tokens so other sessions die
      $pdo->prepare('DELETE FROM user_refresh_tokens WHERE user_id = ?')->execute([$userId]);

      flash('success','Password changed. You may need to sign in again on other devices.');
    }
  }

  if ($action === 'logout_all') {
    verify_csrf();
    // Remove all refresh tokens for this user
    $pdo->prepare('DELETE FROM user_refresh_tokens WHERE user_id = ?')->execute([$userId]);

    // Clear cookies on THIS device, then send to login
    // (access token is short-lived; clearing these ends the session here)
    setcookie('sm_at','', time()-3600, '/','', false, true);
    setcookie('sm_rt','', time()-3600, '/','', false, true);
    setcookie('sm_sid','', time()-3600, '/','', false, true);

    header('Location: /SimpleMoney/public/login.php');
    exit;
  }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <title>SimpleMoney • Settings</title>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
  <style>html{font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif}</style>
</head>
<body class="bg-gray-50">
  <?php include __DIR__ . '/partials/navbar.php'; ?>

  <main class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8 py-8 space-y-6">

    <?php if ($flash['msg']): ?>
      <div class="rounded-md px-4 py-3 text-sm
                  <?= $flash['type']==='success' ? 'bg-green-50 text-green-800 border border-green-200'
                                                 : 'bg-rose-50 text-rose-800 border border-rose-200' ?>">
        <?= htmlspecialchars($flash['msg']) ?>
      </div>
    <?php endif; ?>

    <!-- Profile -->
    <section class="rounded-lg bg-white p-6 shadow-sm">
      <h2 class="text-base font-semibold text-gray-900">Profile</h2>
      <p class="mt-1 text-sm text-gray-600">Update your display name.</p>

      <form method="post" class="mt-4 grid grid-cols-1 gap-4">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($CSRF) ?>">
        <input type="hidden" name="action" value="profile">
        <div>
          <label class="block text-sm font-medium text-gray-700">Email</label>
          <input type="email" value="<?= htmlspecialchars($user['email']) ?>" disabled
                 class="mt-1 w-full rounded-md border-gray-300 bg-gray-50 text-gray-500"/>
        </div>
        <div>
          <label for="name" class="block text-sm font-medium text-gray-700">Name</label>
          <input id="name" name="name" type="text" required maxlength="120"
                 value="<?= htmlspecialchars($user['name'] ?? '') ?>"
                 class="mt-1 w-full rounded-md border-gray-300 focus:border-blue-600 focus:ring-blue-600"/>
        </div>
        <div>
          <button class="inline-flex items-center rounded-md bg-blue-600 px-4 py-2 text-white text-sm font-medium hover:bg-blue-700">
            Save changes
          </button>
        </div>
      </form>
    </section>

    <!-- Password -->
    <section class="rounded-lg bg-white p-6 shadow-sm">
      <h2 class="text-base font-semibold text-gray-900">Change password</h2>
      <p class="mt-1 text-sm text-gray-600">Passwords must be at least 8 characters.</p>

      <form method="post" class="mt-4 grid grid-cols-1 gap-4">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($CSRF) ?>">
        <input type="hidden" name="action" value="password">
        <div>
          <label class="block text-sm font-medium text-gray-700">Current password</label>
          <input name="current" type="password" required
                 class="mt-1 w-full rounded-md border-gray-300 focus:border-blue-600 focus:ring-blue-600"/>
        </div>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <label class="block text-sm font-medium text-gray-700">New password</label>
            <input name="new" type="password" minlength="8" required
                   class="mt-1 w-full rounded-md border-gray-300 focus:border-blue-600 focus:ring-blue-600"/>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700">Confirm new password</label>
            <input name="confirm" type="password" minlength="8" required
                   class="mt-1 w-full rounded-md border-gray-300 focus:border-blue-600 focus:ring-blue-600"/>
          </div>
        </div>
        <div>
          <button class="inline-flex items-center rounded-md bg-blue-600 px-4 py-2 text-white text-sm font-medium hover:bg-blue-700">
            Update password
          </button>
        </div>
      </form>
    </section>

    <!-- Security -->
    <section class="rounded-lg bg-white p-6 shadow-sm">
      <h2 class="text-base font-semibold text-gray-900">Security</h2>
      <p class="mt-1 text-sm text-gray-600">Sign out everywhere and invalidate all remembered devices.</p>
      <form method="post" onsubmit="return confirm('Sign out on all devices?');" class="mt-4">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($CSRF) ?>">
        <input type="hidden" name="action" value="logout_all">
        <button class="inline-flex items-center rounded-md bg-rose-600 px-4 py-2 text-white text-sm font-medium hover:bg-rose-700">
          Log out of all devices
        </button>
      </form>
    </section>

    <!-- Optional: account deletion (commented out for safety) -->
    
    <section class="rounded-lg bg-white p-6 shadow-sm">
      <h2 class="text-base font-semibold text-gray-900">Danger zone</h2>
      <p class="mt-1 text-sm text-gray-600">Permanently delete your account and all data.</p>
      <form method="post" onsubmit="return confirm('This will permanently delete your account. Are you sure?');" class="mt-4">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($CSRF) ?>">
        <input type="hidden" name="action" value="delete_account">
        <button class="inline-flex items-center rounded-md bg-black px-4 py-2 text-white text-sm font-medium hover:opacity-90">
          Delete account
        </button>
      </form>
    </section>
    

  </main>
</body>
</html>
