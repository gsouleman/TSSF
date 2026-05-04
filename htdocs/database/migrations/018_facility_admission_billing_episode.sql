-- ---------------------------------------------------------------------------
-- HMS — Facility admission (patient arrived on site) vs hospitalization (bed + IPD billing)
-- + Billing document anchors: OPD visit OR facility admission OR hospitalization (tbl_admission).
-- Run after 001, 004, 011. MariaDB-oriented ADD COLUMN IF NOT EXISTS (no information_schema).
-- ---------------------------------------------------------------------------

SET NAMES utf8mb4;

-- Admission (arrival): patient is on site / in the system for this episode.
CREATE TABLE IF NOT EXISTS tbl_facility_admission (
  id INT NOT NULL AUTO_INCREMENT,
  facility_id INT NOT NULL,
  patient_id INT NOT NULL,
  arrival_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  closed_at DATETIME NULL,
  arrival_note VARCHAR(255) NULL,
  created_by INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_fa_fac_pat (facility_id, patient_id),
  KEY idx_fa_open (facility_id, patient_id, closed_at),
  CONSTRAINT fk_fa_fac FOREIGN KEY (facility_id) REFERENCES tbl_facility (id) ON DELETE CASCADE,
  CONSTRAINT fk_fa_pat FOREIGN KEY (patient_id) REFERENCES tbl_patient (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Billing must reference at least one episode anchor (enforced in PHP for patient receipts).
ALTER TABLE tbl_billing_document ADD COLUMN IF NOT EXISTS opd_visit_id INT NULL DEFAULT NULL;
ALTER TABLE tbl_billing_document ADD COLUMN IF NOT EXISTS facility_admission_id INT NULL DEFAULT NULL;
ALTER TABLE tbl_billing_document ADD COLUMN IF NOT EXISTS hospitalization_id INT NULL DEFAULT NULL COMMENT 'Open or past tbl_admission.id (inpatient stay / daily bed billing)';

ALTER TABLE tbl_opd_visit ADD COLUMN IF NOT EXISTS facility_admission_id INT NULL DEFAULT NULL;
ALTER TABLE tbl_admission ADD COLUMN IF NOT EXISTS facility_admission_id INT NULL DEFAULT NULL;

CREATE INDEX IF NOT EXISTS idx_bdoc_opd ON tbl_billing_document (opd_visit_id);
CREATE INDEX IF NOT EXISTS idx_bdoc_fa ON tbl_billing_document (facility_admission_id);
CREATE INDEX IF NOT EXISTS idx_bdoc_hz ON tbl_billing_document (hospitalization_id);
CREATE INDEX IF NOT EXISTS idx_opd_fa ON tbl_opd_visit (facility_admission_id);
CREATE INDEX IF NOT EXISTS idx_adm_fa ON tbl_admission (facility_admission_id);
