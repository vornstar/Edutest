<?php
/**
 * Front controller for the assessment platform subfolder. All requests
 * under /assessment/ are rewritten here by .htaccess. Routes are matched
 * against the path relative to /assessment/, keeping this app fully
 * self-contained and independent of the parent site's own routing.
 */

declare(strict_types=1);

// Matches the security headers already sent by the root index.php /
// auth_handler.php, with cdnjs.cloudflare.com additionally allowed for
// script-src so the on-screen marking canvas can load Fabric.js/PDF.js.
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: SAMEORIGIN");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline' cdnjs.cloudflare.com; font-src 'self' cdnjs.cloudflare.com; img-src 'self' data: blob:; frame-src 'self';");

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/controllers/AuthController.php';

AuthController::bootSession();

require_once __DIR__ . '/controllers/HomeController.php';
require_once __DIR__ . '/controllers/StudentController.php';
require_once __DIR__ . '/controllers/PaperController.php';
require_once __DIR__ . '/controllers/TestController.php';
require_once __DIR__ . '/controllers/TeamsController.php';
require_once __DIR__ . '/controllers/MarkingController.php';
require_once __DIR__ . '/controllers/ModerationController.php';
require_once __DIR__ . '/controllers/FileProxyController.php';
require_once __DIR__ . '/controllers/AdminController.php';
require_once __DIR__ . '/controllers/DataController.php';

$scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$path = '/' . ltrim(substr($uri, strlen($scriptDir)), '/');
$path = rtrim($path, '/') ?: '/';
$method = $_SERVER['REQUEST_METHOD'];

/**
 * @param string $pattern e.g. '/teacher/papers/{id}'
 * @return array<string,string>|null captured params, or null if no match
 */
function route_match(string $pattern, string $path): ?array
{
    $regex = preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern);
    if (preg_match('#^' . $regex . '$#', $path, $m)) {
        return array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
    }
    return null;
}

$routes = [
    ['GET', '/', [HomeController::class, 'index']],
    ['GET', '/logout', [AuthController::class, 'logout']],

    // Student portal
    ['GET', '/student', [StudentController::class, 'dashboard']],
    ['GET', '/student/assignments/{id}', [TestController::class, 'take']],
    ['POST', '/student/submissions/{id}/autosave', [TestController::class, 'autosave']],
    ['POST', '/student/submissions/{id}/submit', [TestController::class, 'submit']],
    ['POST', '/student/submissions/{id}/scan', [TestController::class, 'uploadScan']],
    ['GET', '/student/submissions/{id}/self-mark', [TestController::class, 'selfMarkForm']],
    ['POST', '/student/submissions/{id}/self-mark', [TestController::class, 'selfMarkSubmit']],
    ['GET', '/student/submissions/{id}', [StudentController::class, 'submissionSummary']],

    // Teacher / Subject Leader portal
    ['GET', '/teacher', [PaperController::class, 'index']],
    ['GET', '/teacher/papers', [PaperController::class, 'index']],
    ['GET', '/teacher/papers/create', [PaperController::class, 'createForm']],
    ['POST', '/teacher/papers', [PaperController::class, 'store']],
    ['GET', '/teacher/papers/{id}', [PaperController::class, 'show']],
    ['POST', '/teacher/papers/{id}/questions', [PaperController::class, 'addQuestion']],
    ['POST', '/teacher/papers/{id}/bulk-import', [PaperController::class, 'bulkImport']],
    ['POST', '/teacher/papers/{id}/publish', [PaperController::class, 'publish']],
    ['GET', '/teacher/papers/{id}/assign', [TestController::class, 'assignForm']],
    ['POST', '/teacher/papers/{id}/assign', [TestController::class, 'assign']],

    ['GET', '/teacher/classes', [TeamsController::class, 'classesIndex']],
    ['GET', '/teacher/classes/import', [TeamsController::class, 'browseTeamsClasses']],
    ['POST', '/teacher/classes/sync', [TeamsController::class, 'syncClass']],
    ['GET', '/teacher/classes/{id}', [TeamsController::class, 'classShow']],

    ['GET', '/teacher/marking', [MarkingController::class, 'queue']],
    ['GET', '/teacher/marking/{id}', [MarkingController::class, 'markSubmission']],
    ['POST', '/teacher/marking/{id}', [MarkingController::class, 'saveMark']],
    ['POST', '/teacher/marking/{id}/annotation', [MarkingController::class, 'saveAnnotation']],

    ['GET', '/teacher/moderation/queue', [ModerationController::class, 'myQueue']],
    ['GET', '/teacher/moderation/flagged', [ModerationController::class, 'flagged']],
    ['GET', '/teacher/moderation/{id}/allocate', [ModerationController::class, 'allocateForm']],
    ['POST', '/teacher/moderation/{id}/allocate', [ModerationController::class, 'allocate']],
    ['GET', '/teacher/moderation/review/{id}', [ModerationController::class, 'review']],
    ['POST', '/teacher/moderation/review/{id}', [ModerationController::class, 'submitReview']],

    // File streaming proxy (never exposes raw OneDrive URLs)
    ['GET', '/files/papers/{id}/{kind}', [FileProxyController::class, 'paperPdf']],
    ['GET', '/files/scans/{id}', [FileProxyController::class, 'scannedScript']],

    // Data (read-only reporting)
    ['GET', '/data', [DataController::class, 'dashboard']],

    // Admin
    ['GET', '/admin/users', [AdminController::class, 'users']],
    ['POST', '/admin/users/add', [AdminController::class, 'addUser']],
    ['POST', '/admin/users/{id}/role', [AdminController::class, 'setRole']],
    ['GET', '/admin/audit', [AdminController::class, 'auditLog']],
];

foreach ($routes as [$routeMethod, $pattern, $handler]) {
    if ($routeMethod !== $method) {
        continue;
    }
    $params = route_match($pattern, $path);
    if ($params === null) {
        continue;
    }

    $args = array_map(
        static fn($v) => ctype_digit($v) ? (int) $v : $v,
        array_values($params)
    );

    [$controller, $action] = $handler;
    $controller::$action(...$args);
    exit;
}

http_response_code(404);
require __DIR__ . '/views/partials/not_found.php';
