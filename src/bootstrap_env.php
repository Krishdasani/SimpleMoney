<?php
// Minimal .env loader (no external deps)
$envPath = dirname(__DIR__) . '/.env';
if (is_readable($envPath)) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        [$name, $value] = array_pad(explode('=', $line, 2), 2, '');
        $name  = trim($name);
        $value = trim($value, " \t\n\r\0\x0B\"'");
        if ($name === '') continue;
        putenv("$name=$value");
        $_ENV[$name]    = $value;
        $_SERVER[$name] = $value;
    }
}
