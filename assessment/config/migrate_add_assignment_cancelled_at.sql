-- Lets a teacher "cancel" an assigned test - a soft delete: it disappears
-- from the student's own list and (if it was pushed) gets removed from
-- Teams, but the assignment row and everything under it (submissions,
-- marks, annotations) stays in the database untouched, and shows up in a
-- "Deleted tests" section the teacher can restore from. Purely additive -
-- safe to run any time.

ALTER TABLE test_assignments
    ADD COLUMN cancelled_at DATETIME NULL
        COMMENT 'NULL = active. Set/cleared via TestAssignment::cancel()/restore() - a soft delete: hides the assignment from the student entirely and removes it from Teams if it was pushed there, but nothing (submissions, marks, annotations) is ever actually deleted.'
        AFTER closed_at;
