<?php
/**
 * SimpleMoney Auth Pages (MVP UI)
 * Files in this single snippet:
 *  - /public/_shared_head.php   (shared <head> with Tailwind + Inter)
 *  - /public/login.php          (Login page + "Continue with Google")
 *  - /public/signup.php         (Signup page + "Continue with Google")
 *
 * Notes:
 *  - Replace GOOGLE_CLIENT_ID in the data attribute if you render a GIS widget later.
 *  - "Continue with Google" buttons currently link to /auth/google/start which
 *    you will implement server-side (OAuth2) in the next step.
 *  - Color palette & typography match the agreed design system.
 */
?>

<!-- /public/_shared_head.php -->
<!DOCTYPE html>
<html lang="en" class="h-full bg-gray-50">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>SimpleMoney</title>
  <!-- Inter font -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
  <!-- Tailwind CDN -->
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      theme: {
        extend: {
          fontFamily: { sans: ['Inter', 'ui-sans-serif', 'system-ui'] },
          colors: {
            primary: { DEFAULT: '#2563EB' },       // blue-600
            success: { DEFAULT: '#16A34A' },       // green-600
            danger:  { DEFAULT: '#DC2626' },       // red-600
          }
        }
      }
    }
  </script>
  <style>
    html, body { height: 100%; }
  </style>
</head>

<!-- Utility component: Google button (reusable markup) -->
<?php /* You can extract this to a PHP include later if you prefer. */ ?>

<!-- /public/login.php -->
<body class="h-full">
  <div class="min-h-full flex flex-col justify-center py-12 sm:px-6 lg:px-8">
    <div class="sm:mx-auto sm:w-full sm:max-w-md">
      <div class="mx-auto h-12 w-12 rounded-xl bg-primary/10 flex items-center justify-center shadow-sm">
        <!-- Pound icon -->
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-6 h-6 text-primary">
          <path fill-rule="evenodd" d="M12 2.25a.75.75 0 01.75.75v1.5h2.25a.75.75 0 010 1.5H12.75V9a3.75 3.75 0 01-2.77 3.612.75.75 0 00-.48.705V14.25h6a.75.75 0 010 1.5h-6v1.5h7.5a.75.75 0 010 1.5H3.75a.75.75 0 010-1.5H9v-1.5H6.75a.75.75 0 010-1.5H9v-1.45a5.25 5.25 0 003.75-5.05V6H9.75a.75.75 0 010-1.5H12V3a.75.75 0 01.75-.75z" clip-rule="evenodd" />
        </svg>
      </div>
      <h2 class="mt-6 text-center text-2xl font-semibold text-gray-900">Welcome back</h2>
      <p class="mt-2 text-center text-sm text-gray-500">Sign in to your SimpleMoney account</p>
    </div>

    <div class="mt-8 sm:mx-auto sm:w-full sm:max-w-md">
      <div class="bg-white py-8 px-6 shadow-sm rounded-lg sm:px-8">
        <form class="space-y-6" method="POST" action="/SimpleMoney/public/auth/login.php">
          <div>
            <label for="email" class="block text-sm font-medium text-gray-700">Email</label>
            <div class="mt-1">
              <input id="email" name="email" type="email" autocomplete="email" required
                     class="block w-full rounded-md border-gray-300 shadow-sm focus:border-primary focus:ring-primary">
            </div>
          </div>

          <div>
            <label for="password" class="block text-sm font-medium text-gray-700">Password</label>
            <div class="mt-1">
              <input id="password" name="password" type="password" autocomplete="current-password" required
                     class="block w-full rounded-md border-gray-300 shadow-sm focus:border-primary focus:ring-primary">
            </div>
          </div>

          <div class="flex items-center justify-between">
            <div class="flex items-center">
              <input id="remember-me" name="remember" type="checkbox"
                     class="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary">
              <label for="remember-me" class="ml-2 block text-sm text-gray-700">Remember me</label>
            </div>
            <a href="#" class="text-sm font-medium text-primary hover:text-blue-700">Forgot password?</a>
          </div>

          <div>
            <button type="submit"
                    class="w-full flex justify-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-blue-700 focus:outline-none">
              Sign in
            </button>
          </div>
        </form>

        <div class="mt-6">
          <div class="relative">
            <div class="absolute inset-0 flex items-center" aria-hidden="true">
              <div class="w-full border-t border-gray-200"></div>
            </div>
            <div class="relative flex justify-center text-sm">
              <span class="bg-white px-2 text-gray-500">or</span>
            </div>
          </div>

          <div class="mt-6 grid gap-3">
           <button onclick="window.location.href='/simplemoney/public/auth/google_start.php'"
                    class="w-full inline-flex items-center justify-center gap-3 rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50">
              <img alt="Google" src="https://www.gstatic.com/firebasejs/ui/2.0.0/images/auth/google.svg" class="h-5 w-5"/>
              Continue with Google
            </button>
          </div>
        </div>

        <p class="mt-6 text-center text-sm text-gray-500">
          Don't have an account?
          <a href="/SimpleMoney/public/signup.php" class="font-medium text-primary hover:text-blue-700">Create one</a>
        </p>
      </div>
    </div>
  </div>
</body>
</html>

