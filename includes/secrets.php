<?php
/**
 * Filename: includes/secrets.php
 * Description: Loads secrets (Azure AD client secret, database passwords,
 *              encryption keys) from a root-level .env file instead of
 *              hardcoding them in source. .env is not committed to version
 *              control - see .env.example for the keys it must define.
 *              Shared by auth_handler.php and the assessment platform so
 *              there is exactly one place these values live on disk.
 */

declare(strict_types=1);

if (!function_exists('qmhs_load_env')) {
    function qmhs_load_env(string $path): void
    {
        if (!is_file($path) || !is_readable($path)) {
            return;
        }
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
            $key = trim($key);
            $value = trim($value, " \t\n\r\0\x0B\"'");
            if ($key !== '' && getenv($key) === false) {
                putenv("$key=$value");
                $_ENV[$key] = $value;
            }
        }
    }
}

if (!function_exists('qmhs_env')) {
    function qmhs_env(string $key, ?string $default = null): ?string
    {
        $value = getenv($key);
        return $value === false ? $default : $value;
    }
}

qmhs_load_env(__DIR__ . '/../.env');
