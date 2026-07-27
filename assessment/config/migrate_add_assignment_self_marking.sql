-- Self-marking moved from paper-creation-time to assignment-time, and is
-- now toggleable after the fact (see TestAssignment::setSelfMarking) - so
-- it lives on test_assignments instead of papers. Safe to run any time;
-- purely additive, defaults everyone's existing assignments to disabled.
--
-- papers.self_marking_enabled is no longer read anywhere in the app but is
-- left in place rather than dropped - harmless to keep, and avoids another
-- destructive migration step. Drop it later if you want to tidy up:
--   ALTER TABLE papers DROP COLUMN self_marking_enabled;

ALTER TABLE test_assignments
    ADD COLUMN self_marking_enabled TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Set at assign-time or toggled afterward - per-assignment, not per-paper, so a teacher can withhold it until everyone has finished'
    AFTER sync_to_teams;
