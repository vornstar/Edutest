-- Lets a school put its own house style on the platform - name, logo, and
-- a couple of brand colours - via Admin > Branding, without a code change
-- or redeploy. Single-row settings table (always id=1). Purely additive -
-- safe to run any time.

CREATE TABLE IF NOT EXISTS branding (
    id            TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    school_name   VARCHAR(255) NOT NULL DEFAULT 'Assessment Platform',
    logo_filename VARCHAR(255) NULL COMMENT 'Filename only (not a path), under assets/uploads/branding/ - NULL = no logo uploaded, the header shows school_name as text instead.',
    primary_color CHAR(7) NULL COMMENT 'Hex e.g. #2952e3 - overrides --color-primary (buttons, links, brand text). NULL = platform default.',
    accent_color  CHAR(7) NULL COMMENT 'Hex e.g. #b3261e - overrides --color-accent (a secondary highlight, e.g. the header underline). NULL = platform default.',
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;
