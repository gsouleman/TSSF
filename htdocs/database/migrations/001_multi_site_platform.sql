-- HMS multi-site + platform extensions (gaps 1–12 foundation)
-- Run on your MySQL/MariaDB database after importing hms_db.sql.
-- Column/index additions below are idempotent (safe if already applied).
-- Default site id = 1 for existing rows.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------------
-- Multi-site (all modules scoped by facility_id where applicable)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tbl_facility (
  id INT NOT NULL AUTO_INCREMENT,
  code VARCHAR(32) NOT NULL,
  name VARCHAR(250) NOT NULL,
  address TEXT NULL,
  timezone VARCHAR(64) NOT NULL DEFAULT 'UTC',
  status TINYINT NOT NULL DEFAULT 1 COMMENT '1=active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_facility_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO tbl_facility (id, code, name, status) VALUES (1, 'MAIN', 'TSSF Solidarity of Hearts Hospital SOA', 1)
  ON DUPLICATE KEY UPDATE name = VALUES(name);

CREATE TABLE IF NOT EXISTS tbl_user_facility (
  id INT NOT NULL AUTO_INCREMENT,
  employee_id INT NOT NULL,
  facility_id INT NOT NULL,
  is_default TINYINT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_emp_fac (employee_id, facility_id),
  KEY idx_uf_fac (facility_id),
  CONSTRAINT fk_uf_employee FOREIGN KEY (employee_id) REFERENCES tbl_employee (id) ON DELETE CASCADE,
  CONSTRAINT fk_uf_facility FOREIGN KEY (facility_id) REFERENCES tbl_facility (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Scope existing data to primary facility (id=1)
INSERT IGNORE INTO tbl_user_facility (employee_id, facility_id, is_default)
SELECT id, 1, 1 FROM tbl_employee;

-- Idempotent: skip if column or index already exists (avoids #1060 / #1061).
SELECT COUNT(*) INTO @hms_c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_patient' AND COLUMN_NAME = 'facility_id';
SET @hms_sql = IF(@hms_c = 0, 'ALTER TABLE tbl_patient ADD COLUMN facility_id INT NOT NULL DEFAULT 1', 'SELECT 1');
PREPARE hms_stmt FROM @hms_sql; EXECUTE hms_stmt; DEALLOCATE PREPARE hms_stmt;

SELECT COUNT(*) INTO @hms_c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_department' AND COLUMN_NAME = 'facility_id';
SET @hms_sql = IF(@hms_c = 0, 'ALTER TABLE tbl_department ADD COLUMN facility_id INT NOT NULL DEFAULT 1', 'SELECT 1');
PREPARE hms_stmt FROM @hms_sql; EXECUTE hms_stmt; DEALLOCATE PREPARE hms_stmt;

SELECT COUNT(*) INTO @hms_c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_appointment' AND COLUMN_NAME = 'facility_id';
SET @hms_sql = IF(@hms_c = 0, 'ALTER TABLE tbl_appointment ADD COLUMN facility_id INT NOT NULL DEFAULT 1', 'SELECT 1');
PREPARE hms_stmt FROM @hms_sql; EXECUTE hms_stmt; DEALLOCATE PREPARE hms_stmt;

SELECT COUNT(*) INTO @hms_c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_appointment' AND COLUMN_NAME = 'patient_id';
SET @hms_sql = IF(@hms_c = 0, 'ALTER TABLE tbl_appointment ADD COLUMN patient_id INT NULL', 'SELECT 1');
PREPARE hms_stmt FROM @hms_sql; EXECUTE hms_stmt; DEALLOCATE PREPARE hms_stmt;

SELECT COUNT(*) INTO @hms_c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_schedule' AND COLUMN_NAME = 'facility_id';
SET @hms_sql = IF(@hms_c = 0, 'ALTER TABLE tbl_schedule ADD COLUMN facility_id INT NOT NULL DEFAULT 1', 'SELECT 1');
PREPARE hms_stmt FROM @hms_sql; EXECUTE hms_stmt; DEALLOCATE PREPARE hms_stmt;

SELECT COUNT(*) INTO @hms_c FROM information_schema.statistics WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_patient' AND INDEX_NAME = 'idx_patient_fac';
SET @hms_sql = IF(@hms_c = 0, 'ALTER TABLE tbl_patient ADD KEY idx_patient_fac (facility_id)', 'SELECT 1');
PREPARE hms_stmt FROM @hms_sql; EXECUTE hms_stmt; DEALLOCATE PREPARE hms_stmt;

SELECT COUNT(*) INTO @hms_c FROM information_schema.statistics WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_department' AND INDEX_NAME = 'idx_dept_fac';
SET @hms_sql = IF(@hms_c = 0, 'ALTER TABLE tbl_department ADD KEY idx_dept_fac (facility_id)', 'SELECT 1');
PREPARE hms_stmt FROM @hms_sql; EXECUTE hms_stmt; DEALLOCATE PREPARE hms_stmt;

SELECT COUNT(*) INTO @hms_c FROM information_schema.statistics WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_appointment' AND INDEX_NAME = 'idx_appt_fac';
SET @hms_sql = IF(@hms_c = 0, 'ALTER TABLE tbl_appointment ADD KEY idx_appt_fac (facility_id)', 'SELECT 1');
PREPARE hms_stmt FROM @hms_sql; EXECUTE hms_stmt; DEALLOCATE PREPARE hms_stmt;

SELECT COUNT(*) INTO @hms_c FROM information_schema.statistics WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_appointment' AND INDEX_NAME = 'idx_appt_patient';
SET @hms_sql = IF(@hms_c = 0, 'ALTER TABLE tbl_appointment ADD KEY idx_appt_patient (patient_id)', 'SELECT 1');
PREPARE hms_stmt FROM @hms_sql; EXECUTE hms_stmt; DEALLOCATE PREPARE hms_stmt;

-- ---------------------------------------------------------------------------
-- Gap 8 — Audit trail
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tbl_audit_log (
  id BIGINT NOT NULL AUTO_INCREMENT,
  user_id INT NOT NULL DEFAULT 0,
  facility_id INT NOT NULL DEFAULT 0,
  action VARCHAR(64) NOT NULL,
  entity VARCHAR(64) NOT NULL,
  entity_id INT NULL,
  ip VARCHAR(45) NULL,
  user_agent VARCHAR(512) NULL,
  payload_json TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_user (user_id),
  KEY idx_audit_fac (facility_id),
  KEY idx_audit_entity (entity, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------------
-- Gap 8 — RBAC (permission codes; role string matches tbl_employee.role)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tbl_acl_permission (
  id INT NOT NULL AUTO_INCREMENT,
  code VARCHAR(80) NOT NULL,
  label VARCHAR(160) NOT NULL,
  gap_area TINYINT NOT NULL DEFAULT 0 COMMENT '1-12 roadmap area',
  PRIMARY KEY (id),
  UNIQUE KEY uq_acl_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS tbl_acl_role_permission (
  role VARCHAR(20) NOT NULL,
  permission_id INT NOT NULL,
  PRIMARY KEY (role, permission_id),
  CONSTRAINT fk_acl_rp_perm FOREIGN KEY (permission_id) REFERENCES tbl_acl_permission (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO tbl_acl_permission (code, label, gap_area) VALUES
('facility.admin', 'Manage facilities / sites', 9),
('clinical.read', 'View clinical data', 1),
('clinical.write', 'Edit clinical data', 1),
('patient.read', 'View patients', 2),
('patient.write', 'Edit patients', 2),
('scheduling.read', 'View scheduling', 3),
('scheduling.write', 'Edit scheduling', 3),
('adt.read', 'View ADT / beds', 4),
('adt.write', 'Edit ADT / beds', 4),
('billing.read', 'View charges / invoices', 5),
('billing.write', 'Post charges', 5),
('inventory.read', 'View inventory', 6),
('inventory.write', 'Adjust inventory', 6),
('interop.read', 'Use interoperability APIs', 7),
('audit.read', 'View audit log', 8),
('analytics.read', 'View analytics', 11),
('ai.manage', 'Manage AI job queue', 12),
('mpi.merge', 'Merge patient records', 2);

-- Admin (role 1): all permissions
INSERT IGNORE INTO tbl_acl_role_permission (role, permission_id)
SELECT '1', id FROM tbl_acl_permission;

-- Doctor (2): clinical + patients + scheduling read
INSERT IGNORE INTO tbl_acl_role_permission (role, permission_id)
SELECT '2', id FROM tbl_acl_permission WHERE code IN (
  'clinical.read','clinical.write','patient.read','patient.write','scheduling.read','scheduling.write','adt.read','interop.read','analytics.read'
);

-- Nurse (3)
INSERT IGNORE INTO tbl_acl_role_permission (role, permission_id)
SELECT '3', id FROM tbl_acl_permission WHERE code IN (
  'clinical.read','clinical.write','patient.read','patient.write','scheduling.read','adt.read','adt.write','inventory.read','analytics.read'
);

-- Accountant (4)
INSERT IGNORE INTO tbl_acl_role_permission (role, permission_id)
SELECT '4', id FROM tbl_acl_permission WHERE code IN (
  'patient.read','billing.read','billing.write','analytics.read'
);

-- ---------------------------------------------------------------------------
-- Gap 1 — Clinical (encounters, problems, allergies, meds, vitals, orders)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tbl_encounter (
  id INT NOT NULL AUTO_INCREMENT,
  facility_id INT NOT NULL,
  patient_id INT NOT NULL,
  provider_employee_id INT NULL,
  encounter_type VARCHAR(64) NOT NULL DEFAULT 'ambulatory',
  status VARCHAR(32) NOT NULL DEFAULT 'in_progress',
  chief_complaint TEXT NULL,
  started_at DATETIME NOT NULL,
  ended_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_enc_pat (patient_id),
  KEY idx_enc_fac (facility_id),
  CONSTRAINT fk_enc_fac FOREIGN KEY (facility_id) REFERENCES tbl_facility (id),
  CONSTRAINT fk_enc_patient FOREIGN KEY (patient_id) REFERENCES tbl_patient (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS tbl_problem (
  id INT NOT NULL AUTO_INCREMENT,
  encounter_id INT NULL,
  patient_id INT NOT NULL,
  facility_id INT NOT NULL,
  icd_code VARCHAR(16) NULL,
  description VARCHAR(512) NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'active',
  recorded_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_prob_pat (patient_id),
  CONSTRAINT fk_prob_enc FOREIGN KEY (encounter_id) REFERENCES tbl_encounter (id) ON DELETE SET NULL,
  CONSTRAINT fk_prob_patient FOREIGN KEY (patient_id) REFERENCES tbl_patient (id) ON DELETE CASCADE,
  CONSTRAINT fk_prob_fac FOREIGN KEY (facility_id) REFERENCES tbl_facility (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS tbl_patient_allergy (
  id INT NOT NULL AUTO_INCREMENT,
  patient_id INT NOT NULL,
  facility_id INT NOT NULL,
  substance VARCHAR(250) NOT NULL,
  reaction VARCHAR(250) NULL,
  severity VARCHAR(32) NULL,
  recorded_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_allergy_pat (patient_id),
  CONSTRAINT fk_allergy_pat FOREIGN KEY (patient_id) REFERENCES tbl_patient (id) ON DELETE CASCADE,
  CONSTRAINT fk_allergy_fac FOREIGN KEY (facility_id) REFERENCES tbl_facility (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS tbl_patient_medication (
  id INT NOT NULL AUTO_INCREMENT,
  patient_id INT NOT NULL,
  facility_id INT NOT NULL,
  name VARCHAR(250) NOT NULL,
  dose VARCHAR(120) NULL,
  route VARCHAR(64) NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'active',
  started_at DATE NULL,
  PRIMARY KEY (id),
  KEY idx_med_pat (patient_id),
  CONSTRAINT fk_med_pat FOREIGN KEY (patient_id) REFERENCES tbl_patient (id) ON DELETE CASCADE,
  CONSTRAINT fk_med_fac FOREIGN KEY (facility_id) REFERENCES tbl_facility (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS tbl_vital_sign (
  id INT NOT NULL AUTO_INCREMENT,
  patient_id INT NOT NULL,
  encounter_id INT NULL,
  facility_id INT NOT NULL,
  recorded_at DATETIME NOT NULL,
  bp_sys SMALLINT NULL,
  bp_dia SMALLINT NULL,
  heart_rate SMALLINT NULL,
  temp_c DECIMAL(4,1) NULL,
  spo2 SMALLINT NULL,
  rr SMALLINT NULL,
  PRIMARY KEY (id),
  KEY idx_vitals_pat (patient_id),
  CONSTRAINT fk_vs_pat FOREIGN KEY (patient_id) REFERENCES tbl_patient (id) ON DELETE CASCADE,
  CONSTRAINT fk_vs_enc FOREIGN KEY (encounter_id) REFERENCES tbl_encounter (id) ON DELETE SET NULL,
  CONSTRAINT fk_vs_fac FOREIGN KEY (facility_id) REFERENCES tbl_facility (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS tbl_clinical_order (
  id INT NOT NULL AUTO_INCREMENT,
  facility_id INT NOT NULL,
  patient_id INT NOT NULL,
  encounter_id INT NULL,
  order_type VARCHAR(32) NOT NULL COMMENT 'lab,imaging,medication,other',
  code VARCHAR(64) NULL,
  description VARCHAR(512) NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'ordered',
  ordered_by INT NULL,
  ordered_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_ord_pat (patient_id),
  CONSTRAINT fk_ord_fac FOREIGN KEY (facility_id) REFERENCES tbl_facility (id),
  CONSTRAINT fk_ord_pat FOREIGN KEY (patient_id) REFERENCES tbl_patient (id) ON DELETE CASCADE,
  CONSTRAINT fk_ord_enc FOREIGN KEY (encounter_id) REFERENCES tbl_encounter (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS tbl_order_result (
  id INT NOT NULL AUTO_INCREMENT,
  order_id INT NOT NULL,
  result_text TEXT NOT NULL,
  resulted_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_res_order (order_id),
  CONSTRAINT fk_res_order FOREIGN KEY (order_id) REFERENCES tbl_clinical_order (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------------
-- Gap 4 — ADT / beds
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tbl_bed (
  id INT NOT NULL AUTO_INCREMENT,
  facility_id INT NOT NULL,
  ward_name VARCHAR(120) NOT NULL,
  bed_label VARCHAR(32) NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'available',
  PRIMARY KEY (id),
  UNIQUE KEY uq_bed (facility_id, ward_name, bed_label),
  CONSTRAINT fk_bed_fac FOREIGN KEY (facility_id) REFERENCES tbl_facility (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS tbl_admission (
  id INT NOT NULL AUTO_INCREMENT,
  facility_id INT NOT NULL,
  patient_id INT NOT NULL,
  encounter_id INT NULL,
  bed_id INT NULL,
  admitted_at DATETIME NOT NULL,
  discharged_at DATETIME NULL,
  admission_status VARCHAR(32) NOT NULL DEFAULT 'admitted',
  PRIMARY KEY (id),
  KEY idx_adm_pat (patient_id),
  CONSTRAINT fk_adm_fac FOREIGN KEY (facility_id) REFERENCES tbl_facility (id),
  CONSTRAINT fk_adm_pat FOREIGN KEY (patient_id) REFERENCES tbl_patient (id) ON DELETE CASCADE,
  CONSTRAINT fk_adm_enc FOREIGN KEY (encounter_id) REFERENCES tbl_encounter (id) ON DELETE SET NULL,
  CONSTRAINT fk_adm_bed FOREIGN KEY (bed_id) REFERENCES tbl_bed (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------------
-- Gap 5 — Revenue (charges / invoices stub)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tbl_charge (
  id INT NOT NULL AUTO_INCREMENT,
  facility_id INT NOT NULL,
  patient_id INT NOT NULL,
  encounter_id INT NULL,
  cpt_code VARCHAR(16) NULL,
  description VARCHAR(512) NOT NULL,
  amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  posted_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_ch_pat (patient_id),
  CONSTRAINT fk_ch_fac FOREIGN KEY (facility_id) REFERENCES tbl_facility (id),
  CONSTRAINT fk_ch_pat FOREIGN KEY (patient_id) REFERENCES tbl_patient (id) ON DELETE CASCADE,
  CONSTRAINT fk_ch_enc FOREIGN KEY (encounter_id) REFERENCES tbl_encounter (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS tbl_invoice (
  id INT NOT NULL AUTO_INCREMENT,
  facility_id INT NOT NULL,
  patient_id INT NOT NULL,
  total DECIMAL(12,2) NOT NULL DEFAULT 0,
  status VARCHAR(32) NOT NULL DEFAULT 'open',
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_inv_pat (patient_id),
  CONSTRAINT fk_inv_fac FOREIGN KEY (facility_id) REFERENCES tbl_facility (id),
  CONSTRAINT fk_inv_pat FOREIGN KEY (patient_id) REFERENCES tbl_patient (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------------
-- Gap 6 — Inventory / pharmacy stub
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tbl_inventory_item (
  id INT NOT NULL AUTO_INCREMENT,
  facility_id INT NOT NULL,
  sku VARCHAR(64) NOT NULL,
  name VARCHAR(250) NOT NULL,
  category VARCHAR(64) NOT NULL DEFAULT 'supply',
  quantity INT NOT NULL DEFAULT 0,
  reorder_level INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_inv_sku (facility_id, sku),
  CONSTRAINT fk_invitem_fac FOREIGN KEY (facility_id) REFERENCES tbl_facility (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------------
-- Gap 2 — MPI / identifiers / merge log / consents / portal queue
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tbl_patient_identifier (
  id INT NOT NULL AUTO_INCREMENT,
  patient_id INT NOT NULL,
  facility_id INT NOT NULL,
  id_system VARCHAR(64) NOT NULL,
  id_value VARCHAR(128) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pid (facility_id, id_system, id_value),
  CONSTRAINT fk_pident_pat FOREIGN KEY (patient_id) REFERENCES tbl_patient (id) ON DELETE CASCADE,
  CONSTRAINT fk_pident_fac FOREIGN KEY (facility_id) REFERENCES tbl_facility (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS tbl_patient_merge_log (
  id INT NOT NULL AUTO_INCREMENT,
  from_patient_id INT NOT NULL,
  to_patient_id INT NOT NULL,
  merged_by INT NULL,
  merged_at DATETIME NOT NULL,
  note VARCHAR(512) NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS tbl_consent (
  id INT NOT NULL AUTO_INCREMENT,
  patient_id INT NOT NULL,
  facility_id INT NOT NULL,
  consent_type VARCHAR(64) NOT NULL,
  version VARCHAR(32) NOT NULL,
  obtained_at DATETIME NOT NULL,
  document_ref VARCHAR(250) NULL,
  PRIMARY KEY (id),
  KEY idx_consent_pat (patient_id),
  CONSTRAINT fk_consent_pat FOREIGN KEY (patient_id) REFERENCES tbl_patient (id) ON DELETE CASCADE,
  CONSTRAINT fk_consent_fac FOREIGN KEY (facility_id) REFERENCES tbl_facility (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS tbl_notification_queue (
  id BIGINT NOT NULL AUTO_INCREMENT,
  facility_id INT NOT NULL,
  patient_id INT NULL,
  channel VARCHAR(32) NOT NULL,
  template_code VARCHAR(64) NOT NULL,
  send_after DATETIME NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'pending',
  payload_json TEXT NULL,
  PRIMARY KEY (id),
  KEY idx_notif_pending (status, send_after),
  CONSTRAINT fk_notif_fac FOREIGN KEY (facility_id) REFERENCES tbl_facility (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------------
-- Gap 3 — Scheduling resources (rooms / equipment stub)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tbl_scheduling_resource (
  id INT NOT NULL AUTO_INCREMENT,
  facility_id INT NOT NULL,
  resource_type VARCHAR(32) NOT NULL,
  name VARCHAR(160) NOT NULL,
  status TINYINT NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY idx_schres_fac (facility_id),
  CONSTRAINT fk_schres_fac FOREIGN KEY (facility_id) REFERENCES tbl_facility (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------------
-- Gap 7 — Interop stub
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tbl_fhir_export_log (
  id BIGINT NOT NULL AUTO_INCREMENT,
  resource_type VARCHAR(32) NOT NULL,
  resource_id INT NOT NULL,
  exported_at DATETIME NOT NULL,
  client_ref VARCHAR(128) NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------------
-- Gap 8 — MFA / SSO placeholder
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tbl_user_mfa (
  id INT NOT NULL AUTO_INCREMENT,
  employee_id INT NOT NULL,
  method VARCHAR(32) NOT NULL DEFAULT 'totp',
  enabled TINYINT NOT NULL DEFAULT 0,
  secret_cipher VARBINARY(512) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mfa_emp (employee_id),
  CONSTRAINT fk_mfa_emp FOREIGN KEY (employee_id) REFERENCES tbl_employee (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS tbl_sso_provider (
  id INT NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  protocol VARCHAR(32) NOT NULL DEFAULT 'oidc',
  metadata_url VARCHAR(512) NULL,
  status TINYINT NOT NULL DEFAULT 0,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------------
-- Gap 11 — Analytics daily aggregates
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tbl_analytics_daily (
  id BIGINT NOT NULL AUTO_INCREMENT,
  facility_id INT NOT NULL,
  metric_date DATE NOT NULL,
  metric_code VARCHAR(64) NOT NULL,
  metric_value DECIMAL(18,4) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_analytics (facility_id, metric_date, metric_code),
  CONSTRAINT fk_analytics_fac FOREIGN KEY (facility_id) REFERENCES tbl_facility (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------------
-- Gap 12 — AI job queue stub
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tbl_ai_job (
  id BIGINT NOT NULL AUTO_INCREMENT,
  facility_id INT NOT NULL,
  job_type VARCHAR(64) NOT NULL,
  payload_json TEXT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'queued',
  created_at DATETIME NOT NULL,
  processed_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_ai_fac (facility_id),
  CONSTRAINT fk_ai_fac FOREIGN KEY (facility_id) REFERENCES tbl_facility (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO tbl_bed (facility_id, ward_name, bed_label, status) VALUES
(1, 'Medical Ward', 'A-01', 'available'),
(1, 'Medical Ward', 'A-02', 'available'),
(1, 'Surgical Ward', 'B-01', 'available');

SET FOREIGN_KEY_CHECKS = 1;
