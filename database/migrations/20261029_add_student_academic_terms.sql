-- Add optional current academic term fields to student accounts.
-- Existing records retain NULL until supplied by an account import.
ALTER TABLE students
    ADD COLUMN academic_year VARCHAR(20) NULL DEFAULT NULL AFTER section,
    ADD COLUMN semester VARCHAR(30) NULL DEFAULT NULL AFTER academic_year;
