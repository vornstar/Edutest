-- Follow-up to migrate_add_branding.sql:
--   1. Moves the school logo from a local upload (which doesn't work
--      reliably on every host's filesystem permissions) to OneDrive-backed
--      storage, matching every other file in this app.
--   2. Adds four more brand colours: the canvas ink colour for a student's
--      own work, for primary marking, for moderation, and the text colour
--      for a student's self-mark/reflection notes.
-- Purely additive/renaming - safe to run any time. If logo_filename was
-- never successfully populated (the bug this fixes), there's nothing to
-- migrate off it.

ALTER TABLE branding
    DROP COLUMN logo_filename,
    ADD COLUMN logo_drive_item_id VARCHAR(255) NULL
        COMMENT 'OneDrive item id for the school logo (see OneDriveService::uploadBrandingLogo), served via /assessment/files/branding/logo - NULL = no logo uploaded, the header shows school_name as text instead. Same OneDrive-backed storage as every other file in this app - not a local upload, so it works the same on any host regardless of local filesystem write permissions.'
        AFTER school_name,
    ADD COLUMN logo_content_type VARCHAR(50) NULL
        COMMENT 'MIME type of the uploaded logo (image/png, image/jpeg, image/webp) - needed to serve it with the right Content-Type without an extra Graph metadata lookup on every page load.'
        AFTER logo_drive_item_id,
    ADD COLUMN student_work_color CHAR(7) NULL
        COMMENT 'Canvas ink colour for a student''s own typing/annotation on a pdf-type paper, shown to the student themselves and as the read-only reference layer on marking/moderation. NULL = platform default (#1d4ed8).'
        AFTER accent_color,
    ADD COLUMN teacher_marking_color CHAR(7) NULL
        COMMENT 'Default canvas ink colour on the primary marking screen (still changeable per-session via the colour picker there). NULL = platform default (#e11d48).'
        AFTER student_work_color,
    ADD COLUMN teacher_moderation_color CHAR(7) NULL
        COMMENT 'Default canvas ink colour on the moderation screen. NULL = platform default (#059669).'
        AFTER teacher_marking_color,
    ADD COLUMN self_marking_color CHAR(7) NULL
        COMMENT 'Text colour for a student''s self-mark/reflection notes, shown to markers alongside their own marking. NULL = platform default (#16a34a).'
        AFTER teacher_moderation_color;
