<?php
/**
 * Filename: includes/secrets.php
 * Description: Loads secrets (Azure AD client secret, database passwords,
 *              encryption keys) from a root-level .env.php file instead of
 *              hardcoding them in source. .env.php is not committed to
 *              version control - see .env.php.example for the keys it must
 *              define.
 *
 *              IMPORTANT: this is named .env.php (not .env) deliberately.
 *              A plain ".env" is a static file - if a server's .htaccess
 *              access rules aren't honoured (which turned out to be the
 *              case on this host - Apache returned a 500 the moment a
 *              `Require all denied` block was added), a direct request to
 *              it gets served as plain text, secrets and all. A file ending
 *              in .php is always handed to the PHP interpreter instead of
 *              being served as-is, on every PHP host regardless of
 *              .htaccess/AllowOverride restrictions - so .env.php starts
 *              with a bare `<?php exit;` line, which makes a direct request
 *              to it return an empty response no matter what.
 *
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
            // Skip blanks, comments, and the leading `<?php exit;` guard line.
            if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, '<?php') || !str_contains($line, '=')) {
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

qmhs_load_env(__DIR__ . '/../.env.php');
