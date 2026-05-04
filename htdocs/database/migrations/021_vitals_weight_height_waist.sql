-- ---------------------------------------------------------------------------
-- HMS — Optional anthropometrics on vitals (weight, height, waist)
-- Run after 001 (tbl_vital_sign). Idempotent ADD COLUMN IF NOT EXISTS.
-- ---------------------------------------------------------------------------

SET NAMES utf8mb4;

ALTER TABLE tbl_vital_sign ADD COLUMN IF NOT EXISTS weight_kg DECIMAL(6,2) NULL DEFAULT NULL COMMENT 'kg';
ALTER TABLE tbl_vital_sign ADD COLUMN IF NOT EXISTS height_cm DECIMAL(6,1) NULL DEFAULT NULL COMMENT 'cm';
ALTER TABLE tbl_vital_sign ADD COLUMN IF NOT EXISTS waist_cm DECIMAL(6,1) NULL DEFAULT NULL COMMENT 'cm';
