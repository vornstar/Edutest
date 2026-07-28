<?php
declare(strict_types=1);

require_once __DIR__ . '/Database.php';

/**
 * A school's own house style for this deployment - name, logo, and brand
 * colours - editable via Admin > Branding (see AdminController::
 * branding/updateBranding). Single row, always id=1: one school per
 * deployment, same assumption everything else here already makes (see
 * OneDriveService's single shared master folder).
 */
final class Branding
{
    // Platform defaults used wherever a colour hasn't been overridden -
    // kept as named constants (not just inline literals) so every call
    // site that needs "the marking ink colour, branded or not" resolves
    // it the same way. Match the values these contexts already used
    // before branding existed, so an unconfigured deployment looks
    // exactly as it did before.
    public const DEFAULT_STUDENT_WORK_COLOR = '#1d4ed8';
    public const DEFAULT_TEACHER_MARKING_COLOR = '#e11d48';
    public const DEFAULT_TEACHER_MODERATION_COLOR = '#059669';
    public const DEFAULT_SELF_MARKING_COLOR = '#16a34a';

    private const DEFAULTS = [
        'school_name' => 'Assessment Platform',
        'logo_drive_item_id' => null,
        'logo_content_type' => null,
        'primary_color' => null,
        'accent_color' => null,
        'student_work_color' => null,
        'teacher_marking_color' => null,
        'teacher_moderation_color' => null,
        'self_marking_color' => null,
    ];

    /** Always returns every key in DEFAULTS, even before the row has ever been saved. */
    public static function get(): array
    {
        $row = Database::connection()->query('SELECT * FROM branding WHERE id = 1')->fetch();
        return $row ? array_merge(self::DEFAULTS, $row) : self::DEFAULTS;
    }

    public static function save(
        string $schoolName,
        ?string $logoDriveItemId,
        ?string $logoContentType,
        ?string $primaryColor,
        ?string $accentColor,
        ?string $studentWorkColor,
        ?string $teacherMarkingColor,
        ?string $teacherModerationColor,
        ?string $selfMarkingColor
    ): void {
        $stmt = Database::connection()->prepare(
            'INSERT INTO branding (
                id, school_name, logo_drive_item_id, logo_content_type, primary_color, accent_color,
                student_work_color, teacher_marking_color, teacher_moderation_color, self_marking_color
            ) VALUES (
                1, :school_name, :logo_item, :logo_type, :primary, :accent,
                :student_work, :teacher_marking, :teacher_moderation, :self_marking
            )
            ON DUPLICATE KEY UPDATE
                school_name = VALUES(school_name),
                logo_drive_item_id = VALUES(logo_drive_item_id),
                logo_content_type = VALUES(logo_content_type),
                primary_color = VALUES(primary_color),
                accent_color = VALUES(accent_color),
                student_work_color = VALUES(student_work_color),
                teacher_marking_color = VALUES(teacher_marking_color),
                teacher_moderation_color = VALUES(teacher_moderation_color),
                self_marking_color = VALUES(self_marking_color)'
        );
        $stmt->execute([
            'school_name' => $schoolName,
            'logo_item' => $logoDriveItemId,
            'logo_type' => $logoContentType,
            'primary' => $primaryColor,
            'accent' => $accentColor,
            'student_work' => $studentWorkColor,
            'teacher_marking' => $teacherMarkingColor,
            'teacher_moderation' => $teacherModerationColor,
            'self_marking' => $selfMarkingColor,
        ]);
    }
}
