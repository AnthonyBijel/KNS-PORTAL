-- Additive MariaDB migration for the existing kns_portal database. Safe to rerun.
CREATE TABLE IF NOT EXISTS academic_terms (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    academic_year VARCHAR(20) NOT NULL,
    semester ENUM('1st Semester', '2nd Semester', 'Summer') NOT NULL,
    start_date DATE NOT NULL,
    official_end_date DATE NOT NULL,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_academic_term (academic_year, semester),
    KEY ix_term_dates (start_date, official_end_date),
    CONSTRAINT chk_academic_term_dates CHECK (official_end_date >= start_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Serialize calendar validation even when the calendar is empty.
CREATE TABLE IF NOT EXISTS academic_term_calendar_lock (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY
) ENGINE=InnoDB;
INSERT IGNORE INTO academic_term_calendar_lock (id) VALUES (1);

CREATE TABLE IF NOT EXISTS student_enrollments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    student_id INT UNSIGNED NOT NULL,
    term_id INT UNSIGNED NULL,
    academic_year VARCHAR(20) NULL,
    semester VARCHAR(30) NULL,
    course VARCHAR(150) NOT NULL,
    year_level TINYINT UNSIGNED NOT NULL,
    section VARCHAR(50) NOT NULL,
    enrollment_status ENUM('Active', 'Inactive', 'Graduated') NOT NULL,
    recorded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_enrollment_student_term (student_id, term_id, id),
    KEY ix_enrollment_term (term_id, student_id),
    KEY ix_enrollment_labels (academic_year, semester),
    CONSTRAINT fk_term_enrollment_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE RESTRICT,
    CONSTRAINT fk_term_enrollment_term FOREIGN KEY (term_id) REFERENCES academic_terms(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE students
    ADD COLUMN IF NOT EXISTS term_auto_inactive TINYINT(1) NOT NULL DEFAULT 0;

-- Preserve the current academic profile before future imports can change it.
-- Unknown historic dates are deliberately left unknown (term_id remains NULL).
INSERT INTO student_enrollments
    (student_id, academic_year, semester, course, year_level, section, enrollment_status)
SELECT s.id, s.academic_year, s.semester, s.course, s.year_level, s.section, s.status
FROM students s
WHERE NOT EXISTS (SELECT 1 FROM student_enrollments e WHERE e.student_id = s.id);
-- Existing accounts, hashes, photos, grades, assessments, and requests are untouched.
