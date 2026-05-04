-- HMS — Doctor / employee profile photo (path relative to hms/ web root)
--
-- Many shared hosts (InfinityFree, etc.) block access to information_schema,
-- so this file uses a plain ALTER only — no PREPARE / information_schema.
--
-- Run this ONCE in phpMyAdmin (or your SQL client) while your HMS database is selected.
-- If MySQL returns:  Duplicate column name 'photo_path'  → the column already exists; skip.

SET NAMES utf8mb4;

ALTER TABLE tbl_employee
    ADD COLUMN photo_path VARCHAR(255) NULL DEFAULT NULL
    COMMENT 'Relative to hms/ (uploads/doctors/... or assets/...)'
    AFTER bio;
