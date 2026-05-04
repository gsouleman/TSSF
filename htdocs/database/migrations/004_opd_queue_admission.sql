-- ---------------------------------------------------------------------------
-- HMS — OPD visit queue + inpatient admission/discharge documentation
-- Run after 001 (and 003 optional).
-- Admission columns: uses MariaDB "ADD COLUMN IF NOT EXISTS" (no information_schema — works on
-- InfinityFree and other hosts that deny information_schema). Requires MariaDB 10.0.2+.
-- On Oracle MySQL without that syntax, run the four ALTERs once manually and ignore #1060 duplicates.
-- ---------------------------------------------------------------------------

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS tbl_opd_visit (
  id INT NOT NULL AUTO_INCREMENT,
  facility_id INT NOT NULL,
  patient_id INT NOT NULL,
  encounter_id INT NULL,
  ticket_number VARCHAR(40) NOT NULL,
  queue_status VARCHAR(40) NOT NULL DEFAULT 'registered',
  chief_complaint VARCHAR(512) NULL,
  department VARCHAR(120) NULL,
  priority VARCHAR(16) NOT NULL DEFAULT 'normal',
  visit_date DATE NOT NULL,
  queue_started_at DATETIME NOT NULL,
  completed_at DATETIME NULL,
  cancelled_reason VARCHAR(255) NULL,
  created_by INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_opd_ticket (facility_id, ticket_number),
  KEY idx_opd_fac_date (facility_id, visit_date),
  KEY idx_opd_status (facility_id, queue_status),
  CONSTRAINT fk_opd_fac FOREIGN KEY (facility_id) REFERENCES tbl_facility (id),
  CONSTRAINT fk_opd_pat FOREIGN KEY (patient_id) REFERENCES tbl_patient (id) ON DELETE CASCADE,
  CONSTRAINT fk_opd_enc FOREIGN KEY (encounter_id) REFERENCES tbl_encounter (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Inpatient documentation (district hospital) — MariaDB IF NOT EXISTS (no information_schema)
ALTER TABLE tbl_admission ADD COLUMN IF NOT EXISTS admitting_diagnosis VARCHAR(512) NULL;
ALTER TABLE tbl_admission ADD COLUMN IF NOT EXISTS discharge_summary TEXT NULL;
ALTER TABLE tbl_admission ADD COLUMN IF NOT EXISTS admitted_from VARCHAR(32) NULL DEFAULT 'walk_in';
ALTER TABLE tbl_admission ADD COLUMN IF NOT EXISTS opd_visit_id INT NULL;

-- ---------------------------------------------------------------------------
-- RBAC
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO tbl_acl_permission (code, label, gap_area) VALUES
('opd.read', 'View OPD queue', 1),
('opd.write', 'Manage OPD queue & visit steps', 1);

INSERT IGNORE INTO tbl_acl_role_permission (role, permission_id)
SELECT '1', id FROM tbl_acl_permission WHERE code IN ('opd.read', 'opd.write');
INSERT IGNORE INTO tbl_acl_role_permission (role, permission_id)
SELECT '2', id FROM tbl_acl_permission WHERE code IN ('opd.read', 'opd.write');
INSERT IGNORE INTO tbl_acl_role_permission (role, permission_id)
SELECT '3', id FROM tbl_acl_permission WHERE code IN ('opd.read', 'opd.write');
INSERT IGNORE INTO tbl_acl_role_permission (role, permission_id)
SELECT '4', id FROM tbl_acl_permission WHERE code IN ('opd.read');
