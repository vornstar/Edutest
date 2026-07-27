-- Lets a teacher close a test window early (blocks further student work on
-- it) or reopen one, from Teacher > Open tests. Safe to run any time;
-- purely additive - every existing assignment defaults to open (NULL).

ALTER TABLE test_assignments
    ADD COLUMN closed_at DATETIME NULL
    COMMENT 'NULL = open (accepting student work). Set/cleared via TestAssignment::close()/reopen()'
    AFTER self_marking_enabled;
