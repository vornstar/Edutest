<?php
declare(strict_types=1);

require_once __DIR__ . '/Database.php';

/**
 * A school's own house style for this deployment - name, logo, and a
 * couple of brand colours - editable via Admin > Branding (see
 * AdminController::brandingForm/updateBranding). Single row, always id=1:
 * one school per deployment, same assumption everything else here already
 * makes (see OneDriveService's single shared master folder).
 */
final class Branding
{
    private const DEFAULTS = [
        'school_name' => 'Assessment Platform',
        'logo_filename' => null,
        'primary_color' => null,
        'accent_color' => null,
    ];

    /** Always returns every key in DEFAULTS, even before the row has ever been saved. */
    public static function get(): array
    {
        $row = Database::connection()->query('SELECT * FROM branding WHERE id = 1')->fetch();
        return $row ? array_merge(self::DEFAULTS, $row) : self::DEFAULTS;
    }

    public static function save(string $schoolName, ?string $logoFilename, ?string $primaryColor, ?string $accentColor): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO branding (id, school_name, logo_filename, primary_color, accent_color)
             VALUES (1, :school_name, :logo, :primary, :accent)
             ON DUPLICATE KEY UPDATE
                school_name = VALUES(school_name),
                logo_filename = VALUES(logo_filename),
                primary_color = VALUES(primary_color),
                accent_color = VALUES(accent_color)'
        );
        $stmt->execute([
            'school_name' => $schoolName,
            'logo' => $logoFilename,
            'primary' => $primaryColor,
            'accent' => $accentColor,
        ]);
    }
}
