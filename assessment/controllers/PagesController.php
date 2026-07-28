<?php
declare(strict_types=1);

require_once __DIR__ . '/AuthController.php';
require_once __DIR__ . '/../models/Branding.php';

/** Static informational pages (About, Instructions) - available to any signed-in user regardless of role. */
final class PagesController
{
    public static function about(): void
    {
        AuthController::requireLogin();
        $branding = Branding::get();
        require __DIR__ . '/../views/pages/about.php';
    }

    public static function instructions(): void
    {
        $user = AuthController::requireLogin();
        require __DIR__ . '/../views/pages/instructions.php';
    }
}
