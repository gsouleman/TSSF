-- ---------------------------------------------------------------------------
-- HMS — Vitals: who recorded + where (front desk / nursing / chart)
-- Run after 001 (tbl_vital_sign, tbl_employee). Idempotent ADD COLUMN IF NOT EXISTS.
-- ---------------------------------------------------------------------------

SET NAMES utf8mb4;

ALTER TABLE tbl_vital_sign ADD COLUMN IF NOT EXISTS recorded_by INT NULL DEFAULT NULL COMMENT 'tbl_employee.id';
ALTER TABLE tbl_vital_sign ADD COLUMN IF NOT EXISTS source_station VARCHAR(32) NULL DEFAULT NULL COMMENT 'front_desk|nursing|chart|other';

CREATE INDEX IF NOT EXISTS idx_vitals_recorded_by ON tbl_vital_sign (recorded_by);
