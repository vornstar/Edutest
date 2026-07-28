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
    aad_object_id   VARCHAR(64) NULL COMMENT 'Azure AD object id, captured at Teams roster sync - used to match this user to their Teams submission when writing a grade back (see TeamsService::pushGrade)',
    role            ENUM('student','teacher','subject_leader','data','admin') NOT NULL DEFAULT 'student',
    managed_subject VARCHAR(128) NULL COMMENT 'Which subject (matches papers.subject, see subjects table) this teacher-portal user belongs to - drives Paper::visibleTo() for every teacher/subject_leader, and additionally grants department-wide MANAGE authority for subject_leader specifically (set by Admin)',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_site_user (site_user_id),
    UNIQUE KEY uq_email_hash (email_hash)
) ENGINE=InnoDB;

-- Canonical subject list, purely so Admin > Users and paper creation offer a
-- consistent dropdown instead of free text (which drifted into mismatches
-- like "Maths" vs "Mathematics" silently breaking subject-based visibility).
-- users.managed_subject and papers.subject stay plain VARCHAR matched by
-- name (not a foreign key) - editing/removing an entry here never touches
-- existing assignments, it only changes what the dropdowns offer going
-- forward.
CREATE TABLE IF NOT EXISTS subjects (
    id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(128) NOT NULL,
    UNIQUE KEY uq_subject_name (name)
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

-- A teacher's own way of bundling related papers together - e.g. every test
-- for one topic or course - distinct from Subject (a school-wide taxonomy
-- driving visibility, managed via Admin > Subjects). Any teacher-portal
-- user can create one, not just admins, since this is meant to be a
-- lightweight organisational tool a teacher reaches for while creating a
-- paper, not something that needs gatekeeping.
CREATE TABLE IF NOT EXISTS paper_groups (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(128) NOT NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_paper_group_name (name),
    CONSTRAINT fk_paper_groups_creator FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS papers (
    id                       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title                    VARCHAR(255) NOT NULL,
    subject                  VARCHAR(128) NULL,
    group_id                 INT UNSIGNED NULL COMMENT 'Optional - see paper_groups',
    type                     ENUM('digital','pdf') NOT NULL DEFAULT 'digital',
    created_by               INT UNSIGNED NOT NULL,
    pdf_drive_item_id        VARCHAR(255) NULL COMMENT 'OneDrive item id for the exam paper PDF',
    mark_scheme_drive_item_id VARCHAR(255) NULL COMMENT 'OneDrive item id for the PDF mark scheme',
    self_marking_enabled     TINYINT(1) NOT NULL DEFAULT 0,
    max_marks                DECIMAL(6,2) NULL COMMENT 'For pdf-type papers with no per-question breakdown - a single overall mark out of this, also pushed as the Teams assignment''s points value',
    duration_minutes         INT UNSIGNED NULL,
    status                   ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
    grade_boundary_source_paper_id INT UNSIGNED NULL COMMENT 'If set, use THAT paper''s own grade_boundaries rows instead of this paper''s own (see GradeBoundary::resolveForPaper) - must point at a paper with boundaries directly defined, not itself borrowing (Paper::forGroupWithOwnBoundaries only offers such papers, keeping resolution to one hop). NULL = use this paper''s own boundaries if it has any, else no grade boundaries at all.',
    created_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_papers_creator FOREIGN KEY (created_by) REFERENCES users(id),
    CONSTRAINT fk_papers_group FOREIGN KEY (group_id) REFERENCES paper_groups(id) ON DELETE SET NULL,
    CONSTRAINT fk_papers_grade_boundary_source FOREIGN KEY (grade_boundary_source_paper_id) REFERENCES papers(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- A paper's own grade boundary bands (e.g. "9" >= 90%, "8" >= 80%, ...) -
-- percentage of that paper's own max marks, not a raw score, so a set of
-- boundaries stays meaningful even when another paper borrows it (see
-- papers.grade_boundary_source_paper_id) despite having a different total.
-- Optional: a paper with no rows here (and no borrow link) simply has no
-- grade boundaries - nothing else is blocked by that.
CREATE TABLE IF NOT EXISTS grade_boundaries (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    paper_id    INT UNSIGNED NOT NULL,
    grade_label VARCHAR(10) NOT NULL COMMENT 'e.g. "9", "A*", "Distinction"',
    min_percent DECIMAL(5,2) NOT NULL COMMENT 'Minimum percentage of max marks (0-100) to achieve this grade',
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_paper (paper_id),
    CONSTRAINT fk_grade_boundaries_paper FOREIGN KEY (paper_id) REFERENCES papers(id) ON DELETE CASCADE
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
    self_marking_enabled TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Set at assign-time or toggled afterward (see TestAssignment::setSelfMarking) - per-assignment, not per-paper, so a teacher can withhold it until everyone has finished',
    mode                ENUM('assigned','self_service') NOT NULL DEFAULT 'assigned' COMMENT 'self_service = teacher released this paper to the class to attempt and self-mark independently (see TestController::releaseSelfService) - always self_marking_enabled, never enters the marking/moderation queue, and the resolved grade comes from the student''s own self-mark total rather than a teacher mark. assigned = the normal single-paper, teacher-marked flow.',
    closed_at           DATETIME NULL COMMENT 'NULL = open (accepting student work). Set/cleared via TestAssignment::close()/reopen() - lets a teacher end a test window early or reopen it, independent of due_at.',
    cancelled_at        DATETIME NULL COMMENT 'NULL = active. Set/cleared via TestAssignment::cancel()/restore() - a soft delete: hides the assignment from the student entirely and removes it from Teams if it was pushed there, but nothing (submissions, marks, annotations) is ever actually deleted.',
    grade_released_at   DATETIME NULL COMMENT 'NULL = not released. Set/cleared via TestAssignment::releaseGrades()/unreleaseGrades() - once set, every student on this assignment can see their own resolved grade (see GradeBoundary) and the boundary table it came from, on their submission page.',
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
    annotation_version  INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'The student''s current in-PDF writing version (see annotations.version) - bumped by "Start over", never decremented, so earlier attempts stay in the DB for a teacher to review',
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
    question_id         INT UNSIGNED NULL COMMENT 'NULL for a whole-paper overall self-mark (pdf-type papers with no question breakdown) - mirrors marks.question_id',
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
    question_id    INT UNSIGNED NULL COMMENT 'NULL for a whole-paper overall mark (pdf-type papers with no question breakdown)',
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
    version        INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Matches submissions.annotation_version at save time for the student''s own layer (marker_id = student) - always 1 for a teacher/moderator marker, who has no "start over". A page is only ever autosaved in place within one version; a new version starts blank.',
    data_cipher    MEDIUMBLOB NOT NULL COMMENT 'AES-256-GCM encrypted Fabric.js/PDF.js vector overlay JSON',
    flattened      TINYINT(1) NOT NULL DEFAULT 0,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_submission_page_marker_version (submission_id, page_number, marker_id, version),
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

-- A marker's own quick-stamp shortcuts (beyond the built-in Tick/Cross/
-- SEEN/NE/LC/BOD, which aren't stored anywhere - they're hardcoded in the
-- marking toolbar) - e.g. a personal shorthand. Placed on the canvas the
-- same way the Text tool is, just with fixed content, so nothing else
-- needs to know about this table: a stamp is an ordinary object in the
-- marker's saved annotation layer once placed.
CREATE TABLE IF NOT EXISTS custom_stamps (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    label      VARCHAR(20) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_custom_stamps_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- A marker's own keyboard shortcut per stamp OR per annotation tool
-- (Pen/Highlighter/Text/Circle/Delete) - target_type tells the two apart.
-- Covers the built-in stamps (Tick/Cross/SEEN/NE/LC/BOD, matched by their
-- fixed label - see partials/stamp_toolbar.php) and their own custom_stamps
-- (matched by label there too), uniformly, without needing to touch
-- custom_stamps itself. Two unique constraints keep the mapping unambiguous
-- per user: one stamp/tool has at most one key, and one key triggers at
-- most one thing - see StampShortcut::set(), which reassigns rather than
-- erroring if a key is already in use by something else.
CREATE TABLE IF NOT EXISTS stamp_shortcuts (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id      INT UNSIGNED NOT NULL,
    target_type  ENUM('tool','stamp') NOT NULL DEFAULT 'stamp' COMMENT 'Whether stamp_label identifies a toolbar tool (pen/highlighter/text/circle/delete) or a stamp (built-in or custom) - see StampShortcut.',
    stamp_label  VARCHAR(20) NOT NULL,
    shortcut_key CHAR(1) NOT NULL COMMENT 'A single keyboard character (case-insensitive) that arms this stamp/tool while marking/moderating - see canvas-annotate.js',
    UNIQUE KEY uq_stamp_shortcuts_label (user_id, target_type, stamp_label),
    UNIQUE KEY uq_stamp_shortcuts_key (user_id, shortcut_key),
    CONSTRAINT fk_stamp_shortcuts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- A single-row "settings" table (always id=1) so a school can put its own
-- house style on the platform - name, logo, and a couple of brand colours -
-- without a code change or redeploy. See models/Branding.php. This is
-- deployment-wide (one school per deployment, per the OneDrive/Graph
-- tenant model everything else here already assumes), not per-user.
CREATE TABLE IF NOT EXISTS branding (
    id                        TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    school_name               VARCHAR(255) NOT NULL DEFAULT 'Assessment Platform',
    logo_drive_item_id        VARCHAR(255) NULL COMMENT 'OneDrive item id for the school logo (see OneDriveService::uploadBrandingLogo), served via /assessment/files/branding/logo - NULL = no logo uploaded, the header shows school_name as text instead. Same OneDrive-backed storage as every other file in this app - not a local upload, so it works the same on any host regardless of local filesystem write permissions.',
    logo_content_type         VARCHAR(50) NULL COMMENT 'MIME type of the uploaded logo (image/png, image/jpeg, image/webp) - needed to serve it with the right Content-Type without an extra Graph metadata lookup on every page load.',
    primary_color             CHAR(7) NULL COMMENT 'Hex e.g. #2952e3 - overrides --color-primary (buttons, links, brand text). NULL = platform default.',
    accent_color              CHAR(7) NULL COMMENT 'Hex e.g. #b3261e - overrides --color-accent (a secondary highlight, e.g. the header underline). NULL = platform default.',
    student_work_color        CHAR(7) NULL COMMENT 'Canvas ink colour for a student''s own typing/annotation on a pdf-type paper, shown to the student themselves and as the read-only reference layer on marking/moderation. NULL = platform default (#1d4ed8).',
    teacher_marking_color     CHAR(7) NULL COMMENT 'Default canvas ink colour on the primary marking screen (still changeable per-session via the colour picker there). NULL = platform default (#e11d48).',
    teacher_moderation_color  CHAR(7) NULL COMMENT 'Default canvas ink colour on the moderation screen. NULL = platform default (#059669).',
    self_marking_color        CHAR(7) NULL COMMENT 'Text colour for a student''s self-mark/reflection notes, shown to markers alongside their own marking. NULL = platform default (#16a34a).',
    updated_at                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
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
