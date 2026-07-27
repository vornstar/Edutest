-- Step 3 of 3 - encrypting existing plaintext student data at rest.
--
-- Run this ONLY after migrate_encrypt.php (step 2) has reported it
-- encrypted the rows you expected, and you've spot-checked the app still
-- shows the right answers/comments/annotations for a real submission.
-- This permanently removes the plaintext columns - make sure you have a
-- database backup/export first (hPanel > Databases > phpMyAdmin > Export)
-- in case anything needs to be re-checked afterwards.

ALTER TABLE answers DROP COLUMN answer_text;
ALTER TABLE self_marks DROP COLUMN reflection_comment;
ALTER TABLE marks DROP COLUMN comment;
ALTER TABLE annotations DROP COLUMN data_json;

-- annotations.data_cipher is always set going forward (the app never
-- writes a row without it) - tighten it to NOT NULL now that every
-- existing row has been migrated, matching a fresh install's schema.
ALTER TABLE annotations MODIFY COLUMN data_cipher MEDIUMBLOB NOT NULL;
