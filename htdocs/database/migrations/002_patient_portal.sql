-- Patient portal: adds columns to tbl_patient (run after base schema / 001 migration).
--
-- Does NOT use information_schema (many shared hosts deny access — e.g. InfinityFree).
-- Run this file once in phpMyAdmin → SQL.
--
-- If you see "Duplicate column name", that column already exists — skip that line or ignore the error.

ALTER TABLE tbl_patient
  ADD COLUMN portal_password_hash VARCHAR(255) NULL DEFAULT NULL COMMENT 'bcrypt hash for patient portal';

ALTER TABLE tbl_patient
  ADD COLUMN portal_enabled TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=patient may sign in to portal';
