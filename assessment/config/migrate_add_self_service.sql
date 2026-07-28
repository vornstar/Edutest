-- Self-service mode: a teacher releases a selection of papers to a class
-- to attempt and self-mark independently, with no teacher marking step -
-- see TestController::releaseSelfService, GradeBoundary resolution via
-- the student's own self-mark total. Purely additive - safe to run any
-- time.

ALTER TABLE test_assignments
    ADD COLUMN mode ENUM('assigned','self_service') NOT NULL DEFAULT 'assigned'
        COMMENT 'self_service = teacher released this paper to the class to attempt and self-mark independently (see TestController::releaseSelfService) - always self_marking_enabled, never enters the marking/moderation queue, and the resolved grade comes from the student''s own self-mark total rather than a teacher mark. assigned = the normal single-paper, teacher-marked flow.'
        AFTER self_marking_enabled;

-- Lets a self-mark be a single whole-paper score (question_id NULL) for a
-- pdf-type paper with no question breakdown, mirroring how marks.question_id
-- already works for teacher marking. NULL is handled explicitly in
-- Submission::recordSelfMark() rather than relied on for the table's own
-- ON DUPLICATE KEY UPDATE, since MySQL treats each NULL as distinct for a
-- UNIQUE index - two NULLs never "conflict" with each other there.
ALTER TABLE self_marks
    MODIFY COLUMN question_id INT UNSIGNED NULL COMMENT 'NULL for a whole-paper overall self-mark (pdf-type papers with no question breakdown) - mirrors marks.question_id';
