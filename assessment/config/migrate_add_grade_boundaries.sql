-- Lets a teacher set grade boundaries (e.g. "9" >= 90%) for a paper, see
-- the resolved grade immediately once marks are entered, optionally
-- release it to the student (per assignment), and let a paper borrow
-- another paper's boundaries instead of defining its own. Purely
-- additive - safe to run any time.

ALTER TABLE papers
    ADD COLUMN grade_boundary_source_paper_id INT UNSIGNED NULL
        COMMENT 'If set, use THAT paper''s own grade_boundaries rows instead of this paper''s own (see GradeBoundary::resolveForPaper) - must point at a paper with boundaries directly defined, not itself borrowing (Paper::forGroupWithOwnBoundaries only offers such papers, keeping resolution to one hop). NULL = use this paper''s own boundaries if it has any, else no grade boundaries at all.'
        AFTER status,
    ADD CONSTRAINT fk_papers_grade_boundary_source FOREIGN KEY (grade_boundary_source_paper_id) REFERENCES papers(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS grade_boundaries (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    paper_id    INT UNSIGNED NOT NULL,
    grade_label VARCHAR(10) NOT NULL COMMENT 'e.g. "9", "A*", "Distinction"',
    min_percent DECIMAL(5,2) NOT NULL COMMENT 'Minimum percentage of max marks (0-100) to achieve this grade',
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_paper (paper_id),
    CONSTRAINT fk_grade_boundaries_paper FOREIGN KEY (paper_id) REFERENCES papers(id) ON DELETE CASCADE
) ENGINE=InnoDB;

ALTER TABLE test_assignments
    ADD COLUMN grade_released_at DATETIME NULL
        COMMENT 'NULL = not released. Set/cleared via TestAssignment::releaseGrades()/unreleaseGrades() - once set, every student on this assignment can see their own resolved grade (see GradeBoundary) and the boundary table it came from, on their submission page.'
        AFTER cancelled_at;
