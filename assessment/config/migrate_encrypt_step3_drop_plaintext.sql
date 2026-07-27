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

-- users: drop the old unique index + plaintext columns, add the new
-- hash-based unique index, and tighten the new columns to NOT NULL now
-- that every existing row has an encrypted email/display_name.
ALTER TABLE users DROP INDEX uq_email;
ALTER TABLE users DROP COLUMN email;
ALTER TABLE users DROP COLUMN display_name;
ALTER TABLE users MODIFY COLUMN email_cipher MEDIUMBLOB NOT NULL;
ALTER TABLE users MODIFY COLUMN email_hash CHAR(64) NOT NULL;
ALTER TABLE users MODIFY COLUMN display_name_cipher MEDIUMBLOB NOT NULL;
ALTER TABLE users ADD UNIQUE KEY uq_email_hash (email_hash);
