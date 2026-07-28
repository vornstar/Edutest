-- Lets a marker assign a keyboard shortcut to any stamp - built-in
-- (Tick/Cross/SEEN/NE/LC/BOD) or their own custom ones - so they can arm
-- it with a key press instead of a click while marking/moderating. Purely
-- additive - safe to run any time.

CREATE TABLE IF NOT EXISTS stamp_shortcuts (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id      INT UNSIGNED NOT NULL,
    stamp_label  VARCHAR(20) NOT NULL,
    shortcut_key CHAR(1) NOT NULL COMMENT 'A single keyboard character (case-insensitive) that arms this stamp while marking/moderating - see canvas-annotate.js',
    UNIQUE KEY uq_stamp_shortcuts_label (user_id, stamp_label),
    UNIQUE KEY uq_stamp_shortcuts_key (user_id, shortcut_key),
    CONSTRAINT fk_stamp_shortcuts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
