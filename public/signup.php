<!-- /public/signup.php -->
<!DOCTYPE html>
<html lang="en" class="h-full bg-gray-50">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>SimpleMoney • Create account</title>
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
          colors: { primary: { DEFAULT: '#2563EB' } }
        }
      }
    }
  </script>
</head>
<body class="h-full">
  <div class="min-h-full flex flex-col justify-center py-12 sm:px-6 lg:px-8">
    <div class="sm:mx-auto sm:w-full sm:max-w-md">
      <div class="mx-auto h-12 w-12 rounded-xl bg-primary/10 flex items-center justify-center shadow-sm">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-6 h-6 text-primary">
          <path fill-rule="evenodd" d="M12 2.25a.75.75 0 01.75.75v1.5h2.25a.75.75 0 010 1.5H12.75V9a3.75 3.75 0 01-2.77 3.612.75.75 0 00-.48.705V14.25h6a.75.75 0 010 1.5h-6v1.5h7.5a.75.75 0 010 1.5H3.75a.75.75 0 010-1.5H9v-1.5H6.75a.75.75 0 010-1.5H9v-1.45a5.25 5.25 0 003.75-5.05V6H9.75a.75.75 0 010-1.5H12V3a.75.75 0 01.75-.75z" clip-rule="evenodd" />
        </svg>
      </div>
      <h2 class="mt-6 text-center text-2xl font-semibold text-gray-900">Create your account</h2>
      <p class="mt-2 text-center text-sm text-gray-500">Start tracking your money in minutes</p>
    </div>

    <div class="mt-8 sm:mx-auto sm:w-full sm:max-w-md">
      <div class="bg-white py-8 px-6 shadow-sm rounded-lg sm:px-8">
        <form class="space-y-6" method="POST" action="/SimpleMoney/public/auth/register.php" onsubmit="return validateSignup()">
          <div>
            <label for="email_s" class="block text-sm font-medium text-gray-700">Email</label>
            <div class="mt-1">
              <input id="email_s" name="email" type="email" autocomplete="email" required
                     class="block w-full rounded-md border-gray-300 shadow-sm focus:border-primary focus:ring-primary">
            </div>
          </div>

          <div>
            <label for="password_s" class="block text-sm font-medium text-gray-700">Password</label>
            <div class="mt-1">
              <input id="password_s" name="password" type="password" minlength="8" required
                     class="block w-full rounded-md border-gray-300 shadow-sm focus:border-primary focus:ring-primary">
              <p class="mt-1 text-xs text-gray-500">Use at least 8 characters.</p>
            </div>
          </div>

          <div>
            <label for="confirm_s" class="block text-sm font-medium text-gray-700">Confirm password</label>
            <div class="mt-1">
              <input id="confirm_s" name="confirm" type="password" required
                     class="block w-full rounded-md border-gray-300 shadow-sm focus:border-primary focus:ring-primary">
            </div>
          </div>

          <div>
            <button type="submit"
                    class="w-full flex justify-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-blue-700 focus:outline-none">
              Create account
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
          Already have an account?
          <a href="/SimpleMoney/public/login.php" class="font-medium text-primary hover:text-blue-700">Sign in</a>
        </p>
      </div>
    </div>
  </div>

  <script>
    function validateSignup(){
      const p = document.getElementById('password_s').value;
      const c = document.getElementById('confirm_s').value;
      if(p !== c){
        alert('Passwords do not match.');
        return false;
      }
      return true;
    }
  </script>
</body>
</html>
