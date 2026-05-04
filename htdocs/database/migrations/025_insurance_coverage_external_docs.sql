-- ---------------------------------------------------------------------------
-- HMS — Insurance coverage % on patient policies + external clinical documents
-- Run after 006_insurance_and_payments_core.sql (tbl_patient_insurance).
-- Idempotent: insurer_covered_percent is added only if missing (safe re-run).
-- ---------------------------------------------------------------------------

SET NAMES utf8mb4;

-- Add column only when not present (avoids #1060 Duplicate column)
SET @hms_db := DATABASE();
SET @hms_col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @hms_db
    AND TABLE_NAME = 'tbl_patient_insurance'
    AND COLUMN_NAME = 'insurer_covered_percent'
);
SET @hms_sql := IF(
  @hms_col_exists = 0,
  'ALTER TABLE tbl_patient_insurance ADD COLUMN insurer_covered_percent TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT ''Insurer share 0-100 of list price at cashier; patient pays (100 minus this).'' AFTER is_primary',
  'SELECT ''025: insurer_covered_percent already present — skipped'' AS migration_note'
);
PREPARE hms_stmt FROM @hms_sql;
EXECUTE hms_stmt;
DEALLOCATE PREPARE hms_stmt;

CREATE TABLE IF NOT EXISTS tbl_patient_external_document (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  facility_id INT NOT NULL,
  patient_id INT NOT NULL,
  consultation_id INT NULL,
  doc_kind VARCHAR(32) NOT NULL DEFAULT 'other' COMMENT 'lab|radiology|pharmacy|other',
  title VARCHAR(255) NOT NULL DEFAULT '',
  notes TEXT NULL,
  file_path VARCHAR(512) NOT NULL COMMENT 'Relative to hms/ root',
  mime VARCHAR(120) NOT NULL DEFAULT 'application/octet-stream',
  file_size INT UNSIGNED NOT NULL DEFAULT 0,
  original_name VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_ped_pat (patient_id, created_at),
  KEY idx_ped_fac (facility_id, created_at),
  CONSTRAINT fk_ped_fac FOREIGN KEY (facility_id) REFERENCES tbl_facility (id) ON DELETE CASCADE,
  CONSTRAINT fk_ped_pat FOREIGN KEY (patient_id) REFERENCES tbl_patient (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
