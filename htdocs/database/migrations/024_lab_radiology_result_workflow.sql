-- ---------------------------------------------------------------------------
-- HMS — Lab / radiology result workflow: ticket linkage, structured template JSON,
-- conclusion, and shared notices (patient portal + doctor).
-- Run after 013 (tbl_lab_result), 016 (tbl_radiology_result), 023 (payment tickets).
-- Idempotent: CREATE TABLE IF NOT EXISTS; ALTERs may need manual skip if columns exist.
-- ---------------------------------------------------------------------------

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS tbl_result_shared_notice (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  facility_id INT NOT NULL,
  audience VARCHAR(16) NOT NULL COMMENT 'patient|doctor',
  patient_id INT NOT NULL,
  doctor_employee_id INT NULL,
  lab_result_id INT NULL,
  radiology_result_id INT NULL,
  payment_ticket_code VARCHAR(32) NULL,
  test_label VARCHAR(255) NOT NULL DEFAULT '',
  summary TEXT NULL,
  conclusion_code VARCHAR(32) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_rsn_pat (patient_id, created_at),
  KEY idx_rsn_doc (doctor_employee_id, created_at),
  KEY idx_rsn_fac (facility_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- tbl_lab_result extensions (ignore "Duplicate column" if re-run)
ALTER TABLE tbl_lab_result
  ADD COLUMN payment_ticket_code VARCHAR(32) NULL DEFAULT NULL AFTER patient_id,
  ADD COLUMN payment_ticket_line SMALLINT UNSIGNED NULL DEFAULT NULL AFTER payment_ticket_code,
  ADD COLUMN result_template_json MEDIUMTEXT NULL AFTER notes,
  ADD COLUMN conclusion_code VARCHAR(32) NULL DEFAULT NULL AFTER result_template_json;

ALTER TABLE tbl_lab_result
  ADD KEY idx_lab_ticket (facility_id, payment_ticket_code, payment_ticket_line);

ALTER TABLE tbl_radiology_result
  ADD COLUMN payment_ticket_code VARCHAR(32) NULL DEFAULT NULL AFTER patient_id,
  ADD COLUMN payment_ticket_line SMALLINT UNSIGNED NULL DEFAULT NULL AFTER payment_ticket_code,
  ADD COLUMN result_template_json MEDIUMTEXT NULL AFTER notes,
  ADD COLUMN conclusion_code VARCHAR(32) NULL DEFAULT NULL AFTER result_template_json;

ALTER TABLE tbl_radiology_result
  ADD KEY idx_rad_ticket (facility_id, payment_ticket_code, payment_ticket_line);
