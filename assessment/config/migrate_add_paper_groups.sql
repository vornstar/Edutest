-- Lets a teacher bundle related papers together (e.g. every test for one
-- topic/course) - distinct from Subject, which is a school-wide taxonomy
-- managed via Admin > Subjects. Any teacher-portal user can create a group,
-- not just admins. Purely additive - existing papers just start ungrouped.
-- Safe to run any time.

CREATE TABLE IF NOT EXISTS paper_groups (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(128) NOT NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_paper_group_name (name),
    CONSTRAINT fk_paper_groups_creator FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

ALTER TABLE papers
    ADD COLUMN group_id INT UNSIGNED NULL COMMENT 'Optional - see paper_groups' AFTER subject,
    ADD CONSTRAINT fk_papers_group FOREIGN KEY (group_id) REFERENCES paper_groups(id) ON DELETE SET NULL;
