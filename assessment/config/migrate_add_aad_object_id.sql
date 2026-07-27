-- Lets a teacher's saved mark be written back to the matching Teams
-- assignment submission (see TeamsService::pushGrade / MarkingController::
-- saveMark). Captured automatically the next time each class's roster is
-- re-synced (Teacher > Classes > Import/sync) - existing users are
-- backfilled then, nothing else to do here. Safe to run any time; purely
-- additive.

ALTER TABLE users
    ADD COLUMN aad_object_id VARCHAR(64) NULL
    COMMENT 'Azure AD object id, captured at Teams roster sync - used to match this user to their Teams submission when writing a grade back'
    AFTER display_name_cipher;
