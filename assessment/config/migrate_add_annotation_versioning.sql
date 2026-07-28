-- Lets a student "start over" on their in-PDF writing without losing the
-- previous attempt: each save is tagged with a version number, "start over"
-- bumps submissions.annotation_version so new saves land in a fresh version,
-- and the teacher's marking view can browse any earlier version - the
-- latest is what shows everywhere else (results, review, moderation) by
-- default, unchanged. Purely additive - existing rows all become "version 1".
-- Safe to run any time.

ALTER TABLE submissions
    ADD COLUMN annotation_version INT UNSIGNED NOT NULL DEFAULT 1
        COMMENT 'The student''s current in-PDF writing version (see annotations.version) - bumped by "Start over", never decremented, so earlier attempts stay in the DB for a teacher to review'
        AFTER scan_drive_item_id;

ALTER TABLE annotations
    ADD COLUMN version INT UNSIGNED NOT NULL DEFAULT 1
        COMMENT 'Matches submissions.annotation_version at save time for the student''s own layer (marker_id = student) - always 1 for a teacher/moderator marker, who has no "start over". A page is only ever autosaved in place within one version; a new version starts blank.'
        AFTER marker_id,
    ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER flattened;

ALTER TABLE annotations
    DROP INDEX uq_submission_page_marker,
    ADD UNIQUE KEY uq_submission_page_marker_version (submission_id, page_number, marker_id, version);
