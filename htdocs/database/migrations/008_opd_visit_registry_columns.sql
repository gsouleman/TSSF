-- ---------------------------------------------------------------------------
-- HMS — Optional columns on tbl_opd_visit for Visits registry (doctor, treatment, payment).
-- Run after 004_opd_queue_admission.sql. Plain ALTERs (no information_schema).
-- If a column already exists (#1060 Duplicate column), skip that line on your host.
-- ---------------------------------------------------------------------------

SET NAMES utf8mb4;

ALTER TABLE tbl_opd_visit ADD COLUMN assigned_doctor_id INT NULL;
ALTER TABLE tbl_opd_visit ADD COLUMN treatment_note VARCHAR(255) NULL;
ALTER TABLE tbl_opd_visit ADD COLUMN payment_mode VARCHAR(64) NULL;
