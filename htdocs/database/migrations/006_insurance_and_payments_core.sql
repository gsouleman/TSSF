-- ---------------------------------------------------------------------------
-- HMS — Insurance carriers + patient coverage (foundation)
-- Run after 001_multi_site_platform.sql (needs tbl_facility, tbl_patient.facility_id).
-- Idempotent: CREATE TABLE IF NOT EXISTS + INSERT IGNORE for seed carriers.
-- ---------------------------------------------------------------------------

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS tbl_insurance_carrier (
  id INT NOT NULL AUTO_INCREMENT,
  facility_id INT NOT NULL DEFAULT 1,
  code VARCHAR(32) NOT NULL,
  name VARCHAR(200) NOT NULL,
  phone VARCHAR(32) NULL,
  email VARCHAR(160) NULL,
  status TINYINT NOT NULL DEFAULT 1 COMMENT '1=active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ins_carrier_fac_code (facility_id, code),
  KEY idx_ins_carrier_fac (facility_id),
  CONSTRAINT fk_ins_carrier_fac FOREIGN KEY (facility_id) REFERENCES tbl_facility (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS tbl_patient_insurance (
  id INT NOT NULL AUTO_INCREMENT,
  facility_id INT NOT NULL,
  patient_id INT NOT NULL,
  carrier_id INT NOT NULL,
  policy_number VARCHAR(80) NULL,
  member_id VARCHAR(80) NULL,
  group_number VARCHAR(80) NULL,
  coverage_type VARCHAR(64) NULL COMMENT 'e.g. primary, secondary',
  effective_from DATE NULL,
  effective_to DATE NULL,
  is_primary TINYINT NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_pins_pat (patient_id),
  KEY idx_pins_fac (facility_id),
  KEY idx_pins_carrier (carrier_id),
  CONSTRAINT fk_pins_pat FOREIGN KEY (patient_id) REFERENCES tbl_patient (id) ON DELETE CASCADE,
  CONSTRAINT fk_pins_carrier FOREIGN KEY (carrier_id) REFERENCES tbl_insurance_carrier (id) ON DELETE RESTRICT,
  CONSTRAINT fk_pins_fac FOREIGN KEY (facility_id) REFERENCES tbl_facility (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO tbl_insurance_carrier (facility_id, code, name, phone, email, status) VALUES
(1, 'CNPS', 'Caisse Nationale de Prévoyance Sociale', NULL, NULL, 1),
(1, 'PRIVATE', 'Assurance privée — pool démo', '+237600000000', 'contact@demo-assurance.cm', 1),
(1, 'MUTUELLE', 'Mutuelle santé communautaire', NULL, NULL, 1);
