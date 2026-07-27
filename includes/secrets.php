<?php
/**
 * Filename: includes/secrets.php
 * Description: Loads secrets (Azure AD client secret, database passwords,
 *              encryption keys) from a .env.php file instead of hardcoding
 *              them in source. .env.php is not committed to version
 *              control - see .env.php.example for the keys it must define.
 *
 *              Looked for OUTSIDE the web-servable folder first (one
 *              directory above the site root - e.g. next to public_html,
 *              not inside it), which is the real fix: a file the web
 *              server's document root doesn't contain can never be served
 *              over HTTP, full stop, regardless of any .htaccess/PHP
 *              handler quirk. Falls back to a copy inside the site root
 *              (still guarded by a leading `<?php exit;` line) only if the
 *              outside-webroot copy isn't found, so setup can't silently
 *              fail with everything blank.
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

// __DIR__ here is <site root>/includes. Two levels up is the directory
// THAT CONTAINS the site root (e.g. one level above public_html) - a path
// no web request can ever reach, because the web server only serves the
// site root and below.
$outsideWebroot = dirname(__DIR__, 2) . '/.env.php';
$insideWebroot = __DIR__ . '/../.env.php';

if (is_file($outsideWebroot)) {
    qmhs_load_env($outsideWebroot);
} else {
    qmhs_load_env($insideWebroot);
}
