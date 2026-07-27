-- Canonical subject list backing the new Admin > Subjects page and the
-- dropdowns for assigning a teacher's subject / a paper's subject. Purely
-- additive - existing free-text values in users.managed_subject and
-- papers.subject are untouched (this table doesn't reference them via a
-- foreign key, so nothing breaks if it starts out empty). Safe to run any
-- time.

CREATE TABLE IF NOT EXISTS subjects (
    id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(128) NOT NULL,
    UNIQUE KEY uq_subject_name (name)
) ENGINE=InnoDB;

-- Optional convenience: seed the list from whatever subject values are
-- already in use, so existing papers/teachers immediately show up
-- correctly-selected in the new dropdowns instead of you having to
-- re-type them all in Admin > Subjects first.
INSERT IGNORE INTO subjects (name)
SELECT DISTINCT subject FROM papers WHERE subject IS NOT NULL AND subject <> '';
INSERT IGNORE INTO subjects (name)
SELECT DISTINCT managed_subject FROM users WHERE managed_subject IS NOT NULL AND managed_subject <> '';
