-- Adds support for a single "overall mark" per submission, for pdf-type
-- papers where you don't want to build a per-question structure - just
-- upload the PDF, set a max marks value, and type in one number when
-- marking. Safe to run any time; purely additive/widening.

ALTER TABLE papers
    ADD COLUMN max_marks DECIMAL(6,2) NULL
    COMMENT 'For pdf-type papers with no per-question breakdown - a single overall mark out of this, also pushed as the Teams assignment''s points value'
    AFTER self_marking_enabled;

-- question_id must become nullable so a mark row can represent a
-- whole-paper mark (question_id IS NULL) instead of always being tied to
-- one of the digital question-bank rows.
ALTER TABLE marks MODIFY COLUMN question_id INT UNSIGNED NULL;
