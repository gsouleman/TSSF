-- ---------------------------------------------------------------------------
-- HMS — Laboratory registry (Dreams-style Lab Results + Medical Results)
-- Run after 001_multi_site_platform.sql (requires tbl_facility, tbl_patient, tbl_employee).
-- Uses existing RBAC: lab.read / lab.write (003_clinical_workflow.sql).
-- ---------------------------------------------------------------------------

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS tbl_lab_result (
  id INT NOT NULL AUTO_INCREMENT,
  facility_id INT NOT NULL,
  patient_id INT NOT NULL,
  referred_by_id INT NULL COMMENT 'Ordering / referring clinician (tbl_employee)',
  test_name VARCHAR(255) NOT NULL,
  appointment_date DATE NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'pending' COMMENT 'pending | in_progress | received',
  notes TEXT NULL,
  created_by INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_lab_res_fac_date (facility_id, appointment_date),
  KEY idx_lab_res_fac_status (facility_id, status),
  KEY idx_lab_res_patient (patient_id),
  CONSTRAINT fk_lab_res_fac FOREIGN KEY (facility_id) REFERENCES tbl_facility (id),
  CONSTRAINT fk_lab_res_patient FOREIGN KEY (patient_id) REFERENCES tbl_patient (id) ON DELETE CASCADE,
  CONSTRAINT fk_lab_res_ref FOREIGN KEY (referred_by_id) REFERENCES tbl_employee (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS tbl_medical_result (
  id INT NOT NULL AUTO_INCREMENT,
  facility_id INT NOT NULL,
  patient_id INT NOT NULL,
  record_name VARCHAR(255) NOT NULL COMMENT 'e.g. Blood Report, MRI Scan',
  appointment_date DATE NOT NULL,
  notes TEXT NULL,
  created_by INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_med_res_fac_date (facility_id, appointment_date),
  KEY idx_med_res_patient (patient_id),
  CONSTRAINT fk_med_res_fac FOREIGN KEY (facility_id) REFERENCES tbl_facility (id),
  CONSTRAINT fk_med_res_patient FOREIGN KEY (patient_id) REFERENCES tbl_patient (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
