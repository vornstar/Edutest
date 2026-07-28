-- A marker's own quick-stamp shortcuts for marking (beyond the built-in
-- Tick/Cross/SEEN/NE/LC/BOD, which are hardcoded in the marking toolbar and
-- don't need a DB table). Purely additive - safe to run any time.

CREATE TABLE IF NOT EXISTS custom_stamps (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    label      VARCHAR(20) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_custom_stamps_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
