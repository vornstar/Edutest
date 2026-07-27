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

-- Every user's email and display name are identifiable personal data, so
-- they get the same treatment as student work: encrypted, plus a keyed
-- HMAC (email_hash) standing in for the plaintext email wherever the app
-- needs an exact-match lookup or a uniqueness constraint - AES-256-GCM's
-- ciphertext can't be used for either, since its random nonce makes the
-- same plaintext encrypt differently every time.
ALTER TABLE users
    ADD COLUMN email_cipher MEDIUMBLOB NULL COMMENT 'AES-256-GCM encrypted email address' AFTER site_user_id,
    ADD COLUMN email_hash CHAR(64) NULL COMMENT 'HMAC-SHA256 of the normalized email - for lookup/uniqueness' AFTER email_cipher,
    ADD COLUMN display_name_cipher MEDIUMBLOB NULL COMMENT 'AES-256-GCM encrypted display name' AFTER email_hash;
