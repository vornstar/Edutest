-- Online Assessment Platform - MySQL / MariaDB schema
-- Deployed to its own database (u781387176_assessment), deliberately
-- decoupled from the site's u781387176_core / legacy identity databases -
-- see models/User.php for how identity is synced in from the shared PHP
-- session set by the site-wide root auth_handler.php.
--
-- All tables use InnoDB. Every column holding actual student work or marker
-- feedback (mark schemes, model answers, typed/handwritten answers, in-PDF
-- annotations, self-mark reflections, marker comments) is stored as a
-- MEDIUMBLOB and encrypted/decrypted in the application layer with
-- AES-256-GCM (see models/Crypto.php). Do not store encryption keys in this DB.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- Local, assessment-only identity + role. `site_user_id` links back to the
-- `id` the root login system assigns (kept in sync between its own core and
-- legacy databases already), but is nullable so an Admin can pre-provision
-- a colleague by email - see models/User.php::addByEmail() - before they
-- have ever signed in. On first sign-in the row is matched by email and
-- site_user_id is backfilled, preserving whatever role was pre-assigned.
-- Every brand-new identity (no admin pre-provisioning, no existing row)
-- defaults to 'student' - the roster sync and login sync paths never
-- elevate a role on their own, only Admin > Users does.
CREATE TABLE IF NOT EXISTS users (
    id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    site_user_id         INT UNSIGNED NULL,
    email_cipher         MEDIUMBLOB NOT NULL COMMENT 'AES-256-GCM encrypted email address',
    email_hash           CHAR(64) NOT NULL COMMENT 'HMAC-SHA256 of the normalized email (see Crypto::searchHash) - used for exact-match lookup and uniqueness in place of the (non-deterministic) ciphertext',
    display_name_cipher  MEDIUMBLOB NOT NULL COMMENT 'AES-256-GCM encrypted display name',
    role            ENUM('student','teacher','subject_leader','data','admin') NOT NULL DEFAULT 'student',
    managed_subject VARCHAR(128) NULL COMMENT 'For subject_leader: which papers.subject they have department-wide authority over (set by Admin)',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_site_user (site_user_id),
    UNIQUE KEY uq_email_hash (email_hash)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS classes (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    teams_class_id   VARCHAR(128) NULL,
    name             VARCHAR(255) NOT NULL,
    subject          VARCHAR(128) NULL,
    owner_teacher_id INT UNSIGNED NOT NULL,
    last_synced_at   DATETIME NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_teams_class (teams_class_id),
    CONSTRAINT fk_classes_teacher FOREIGN KEY (owner_teacher_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS class_enrollments (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    class_id     INT UNSIGNED NOT NULL,
    user_id      INT UNSIGNED NOT NULL,
    role_in_class ENUM('student','teacher') NOT NULL DEFAULT 'student',
    UNIQUE KEY uq_class_user (class_id, user_id),
    CONSTRAINT fk_enroll_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    CONSTRAINT fk_enroll_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS papers (
    id                       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title                    VARCHAR(255) NOT NULL,
    subject                  VARCHAR(128) NULL,
    type                     ENUM('digital','pdf') NOT NULL DEFAULT 'digital',
    created_by               INT UNSIGNED NOT NULL,
    pdf_drive_item_id        VARCHAR(255) NULL COMMENT 'OneDrive item id for the exam paper PDF',
    mark_scheme_drive_item_id VARCHAR(255) NULL COMMENT 'OneDrive item id for the PDF mark scheme',
    self_marking_enabled     TINYINT(1) NOT NULL DEFAULT 0,
    duration_minutes         INT UNSIGNED NULL,
    status                   ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
    created_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_papers_creator FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS questions (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    paper_id              INT UNSIGNED NOT NULL,
    section               VARCHAR(64) NULL,
    order_index           INT UNSIGNED NOT NULL DEFAULT 0,
    type                  ENUM('mcq','short_answer','extended_text') NOT NULL,
    question_text         TEXT NOT NULL,
    options_json          TEXT NULL COMMENT 'JSON array of options for MCQ',
    correct_option        VARCHAR(8) NULL COMMENT 'MCQ correct option key',
    max_marks             DECIMAL(5,2) NOT NULL DEFAULT 1.00,
    mark_scheme_cipher    VARBINARY(4096) NULL COMMENT 'AES-256-GCM encrypted mark scheme text',
    model_answer_cipher   VARBINARY(4096) NULL COMMENT 'AES-256-GCM encrypted model answer text',
    created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_paper (paper_id),
    CONSTRAINT fk_questions_paper FOREIGN KEY (paper_id) REFERENCES papers(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS test_assignments (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    paper_id            INT UNSIGNED NOT NULL,
    class_id            INT UNSIGNED NULL,
    assigned_by         INT UNSIGNED NOT NULL,
    teams_assignment_id VARCHAR(128) NULL,
    due_at              DATETIME NULL,
    status              ENUM('assigned','submitted','graded') NOT NULL DEFAULT 'assigned',
    sync_to_teams       TINYINT(1) NOT NULL DEFAULT 0,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_assign_paper FOREIGN KEY (paper_id) REFERENCES papers(id) ON DELETE CASCADE,
    CONSTRAINT fk_assign_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE SET NULL,
    CONSTRAINT fk_assign_by FOREIGN KEY (assigned_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS submissions (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    assignment_id       INT UNSIGNED NOT NULL,
    student_id          INT UNSIGNED NOT NULL,
    status              ENUM('in_progress','submitted','self_marked','pending_moderation','marked','moderated') NOT NULL DEFAULT 'in_progress',
    scan_drive_item_id  VARCHAR(255) NULL COMMENT 'OneDrive item id for scanned handwritten script',
    started_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    submitted_at        DATETIME NULL,
    UNIQUE KEY uq_assignment_student (assignment_id, student_id),
    CONSTRAINT fk_sub_assignment FOREIGN KEY (assignment_id) REFERENCES test_assignments(id) ON DELETE CASCADE,
    CONSTRAINT fk_sub_student FOREIGN KEY (student_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS answers (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    submission_id  INT UNSIGNED NOT NULL,
    question_id    INT UNSIGNED NOT NULL,
    answer_cipher  MEDIUMBLOB NULL COMMENT 'AES-256-GCM encrypted student answer text',
    autosaved_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_submission_question (submission_id, question_id),
    CONSTRAINT fk_answers_submission FOREIGN KEY (submission_id) REFERENCES submissions(id) ON DELETE CASCADE,
    CONSTRAINT fk_answers_question FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS self_marks (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    submission_id       INT UNSIGNED NOT NULL,
    question_id         INT UNSIGNED NOT NULL,
    student_mark        DECIMAL(5,2) NOT NULL,
    reflection_cipher   MEDIUMBLOB NULL COMMENT 'AES-256-GCM encrypted student reflection comment',
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_selfmark (submission_id, question_id),
    CONSTRAINT fk_selfmark_submission FOREIGN KEY (submission_id) REFERENCES submissions(id) ON DELETE CASCADE,
    CONSTRAINT fk_selfmark_question FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS marks (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    submission_id  INT UNSIGNED NOT NULL,
    question_id    INT UNSIGNED NOT NULL,
    marker_id      INT UNSIGNED NOT NULL,
    mark_type      ENUM('primary','moderation') NOT NULL DEFAULT 'primary',
    score          DECIMAL(5,2) NOT NULL,
    comment_cipher MEDIUMBLOB NULL COMMENT 'AES-256-GCM encrypted marker comment',
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_marks_submission (submission_id),
    CONSTRAINT fk_marks_submission FOREIGN KEY (submission_id) REFERENCES submissions(id) ON DELETE CASCADE,
    CONSTRAINT fk_marks_question FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE,
    CONSTRAINT fk_marks_marker FOREIGN KEY (marker_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS annotations (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    submission_id  INT UNSIGNED NOT NULL,
    page_number    INT UNSIGNED NOT NULL DEFAULT 1,
    marker_id      INT UNSIGNED NOT NULL,
    data_cipher    MEDIUMBLOB NOT NULL COMMENT 'AES-256-GCM encrypted Fabric.js/PDF.js vector overlay JSON',
    flattened      TINYINT(1) NOT NULL DEFAULT 0,
    updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_submission_page_marker (submission_id, page_number, marker_id),
    CONSTRAINT fk_annotations_submission FOREIGN KEY (submission_id) REFERENCES submissions(id) ON DELETE CASCADE,
    CONSTRAINT fk_annotations_marker FOREIGN KEY (marker_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS moderation_assignments (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    submission_id       INT UNSIGNED NOT NULL,
    primary_marker_id   INT UNSIGNED NULL,
    secondary_marker_id INT UNSIGNED NOT NULL,
    mode                ENUM('open','blind') NOT NULL DEFAULT 'open',
    tolerance           DECIMAL(5,2) NOT NULL DEFAULT 2.00,
    variance            DECIMAL(5,2) NULL,
    status              ENUM('pending','in_progress','completed','flagged') NOT NULL DEFAULT 'pending',
    assigned_by         INT UNSIGNED NOT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at        DATETIME NULL,
    CONSTRAINT fk_mod_submission FOREIGN KEY (submission_id) REFERENCES submissions(id) ON DELETE CASCADE,
    CONSTRAINT fk_mod_primary FOREIGN KEY (primary_marker_id) REFERENCES users(id),
    CONSTRAINT fk_mod_secondary FOREIGN KEY (secondary_marker_id) REFERENCES users(id),
    CONSTRAINT fk_mod_assigned_by FOREIGN KEY (assigned_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS audit_log (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entity_type   VARCHAR(64) NOT NULL,
    entity_id     INT UNSIGNED NOT NULL,
    actor_id      INT UNSIGNED NULL,
    action        VARCHAR(64) NOT NULL,
    before_json   MEDIUMTEXT NULL,
    after_json    MEDIUMTEXT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_entity (entity_type, entity_id),
    KEY idx_actor (actor_id)
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS = 1;
