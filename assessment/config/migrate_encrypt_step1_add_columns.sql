-- Step 1 of 3 - encrypting existing plaintext student data at rest.
--
-- Adds the new *_cipher columns alongside the existing plaintext ones
-- (which are left untouched for now, so nothing breaks while you migrate).
-- Run this in phpMyAdmin / hPanel's SQL tab against the assessment
-- database, THEN run migrate_encrypt.php once (step 2), THEN run
-- migrate_encrypt_step3_drop_plaintext.sql (step 3).

ALTER TABLE answers
    ADD COLUMN answer_cipher MEDIUMBLOB NULL COMMENT 'AES-256-GCM encrypted student answer text' AFTER question_id;

ALTER TABLE self_marks
    ADD COLUMN reflection_cipher MEDIUMBLOB NULL COMMENT 'AES-256-GCM encrypted student reflection comment' AFTER student_mark;

ALTER TABLE marks
    ADD COLUMN comment_cipher MEDIUMBLOB NULL COMMENT 'AES-256-GCM encrypted marker comment' AFTER score;

ALTER TABLE annotations
    ADD COLUMN data_cipher MEDIUMBLOB NULL COMMENT 'AES-256-GCM encrypted Fabric.js/PDF.js vector overlay JSON' AFTER marker_id;
