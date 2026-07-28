-- Broadens stamp_shortcuts (see migrate_add_stamp_shortcuts.sql) to also
-- cover the Pen/Highlighter/Text/Circle/Delete toolbar tools, not just
-- stamps - a marker can now arm either kind of thing with a keypress while
-- marking/moderating. Existing rows are all stamps, so they default to
-- target_type='stamp' and keep working unchanged. Purely additive - safe
-- to run any time.

ALTER TABLE stamp_shortcuts
    ADD COLUMN target_type ENUM('tool','stamp') NOT NULL DEFAULT 'stamp' COMMENT 'Whether stamp_label identifies a toolbar tool (pen/highlighter/text/circle/delete) or a stamp (built-in or custom) - see StampShortcut.' AFTER user_id;

ALTER TABLE stamp_shortcuts DROP INDEX uq_stamp_shortcuts_label;
ALTER TABLE stamp_shortcuts ADD UNIQUE KEY uq_stamp_shortcuts_label (user_id, target_type, stamp_label);
