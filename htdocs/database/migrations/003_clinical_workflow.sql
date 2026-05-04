-- HMS — Consultation, prescriptions, lab catalog, pharmacy dispense (run once after 001).
-- Plain SQL (no information_schema). If a column already exists, skip that ALTER line.

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- Lab catalog (codes + labels for autopopulate)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tbl_lab_catalog (
  id INT NOT NULL AUTO_INCREMENT,
  code VARCHAR(40) NOT NULL,
  name VARCHAR(220) NOT NULL,
  category VARCHAR(80) NOT NULL DEFAULT 'Biologie',
  specimen_hint VARCHAR(255) NULL,
  active TINYINT NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_lab_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO tbl_lab_catalog (code, name, category, specimen_hint, sort_order) VALUES
('CBC', 'Numération formule sanguine complète', 'Hématologie', 'EDTA tube', 10),
('HB', 'Hémoglobine', 'Hématologie', 'EDTA tube', 11),
('WBC', 'Globules blancs', 'Hématologie', 'EDTA tube', 12),
('PLT', 'Plaquettes', 'Hématologie', 'EDTA tube', 13),
('ESR', 'VS (vitesse sédimentation)', 'Hématologie', 'Citrate', 14),
('GLU', 'Glycémie à jeun', 'Biochimie', 'Tube fluoré', 20),
('HbA1c', 'Hémoglobine glyquée', 'Biochimie', 'EDTA tube', 21),
('UREA', 'Urée / BUN', 'Biochimie', 'Sérum', 22),
('CREA', 'Créatinine', 'Biochimie', 'Sérum', 23),
('ALT', 'Transaminases ALAT', 'Biochimie', 'Sérum', 24),
('AST', 'Transaminases ASAT', 'Biochimie', 'Sérum', 25),
('BILI_T', 'Bilirubine totale', 'Biochimie', 'Sérum', 26),
('TP', 'Taux de prothrombine / INR', 'Hémostase', 'Citrate', 30),
('HIV', 'Sérologie VIH', 'Sérologie', 'Sérum (consentement)', 40),
('HBSAG', 'Ag HBs', 'Sérologie', 'Sérum', 41),
('WIDAL', 'Widal', 'Sérologie', 'Sérum', 42),
('MALARIA', 'Goutte épaisse / TDR paludisme', 'Parasitologie', 'Sang capillaire / EDTA', 50),
('URINE', 'Examen cytobactériologique des urines', 'Microbiologie', 'Urine milieu jet', 60),
('CULTURE_BC', 'Hémoculture', 'Microbiologie', 'Bouteilles hémoculture', 61),
('CRP', 'Protéine C réactive', 'Biochimie', 'Sérum', 70),
('LDH', 'LDH', 'Biochimie', 'Sérum', 71),
('FERR', 'Ferritine', 'Biochimie', 'Sérum', 72),
('TSH', 'TSH', 'Hormonologie', 'Sérum', 80),
('FT4', 'T4 libre', 'Hormonologie', 'Sérum', 81),
('PSA', 'PSA total', 'Hormonologie', 'Sérum', 82),
('LIPASE', 'Lipase', 'Biochimie', 'Sérum', 83),
('AMYLASE', 'Amylase', 'Biochimie', 'Sérum', 84),
('VDRL', 'VDRL', 'Sérologie', 'Sérum', 85),
('CHEST_XR', 'Radiographie thorax (demande)', 'Imagerie', '—', 200);

-- ---------------------------------------------------------------------------
-- Dynamic consultation parameters (per site)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tbl_consult_param_def (
  id INT NOT NULL AUTO_INCREMENT,
  facility_id INT NOT NULL DEFAULT 1,
  param_code VARCHAR(48) NOT NULL,
  label VARCHAR(160) NOT NULL,
  field_type VARCHAR(20) NOT NULL DEFAULT 'text' COMMENT 'number,text,select',
  options_csv VARCHAR(512) NULL COMMENT 'for select: opt1,opt2,opt3',
  unit VARCHAR(32) NULL,
  sort_order INT NOT NULL DEFAULT 0,
  active TINYINT NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cparam (facility_id, param_code),
  CONSTRAINT fk_cparam_fac FOREIGN KEY (facility_id) REFERENCES tbl_facility (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO tbl_consult_param_def (facility_id, param_code, label, field_type, options_csv, unit, sort_order) VALUES
(1, 'weight_kg', 'Poids', 'number', NULL, 'kg', 1),
(1, 'height_cm', 'Taille', 'number', NULL, 'cm', 2),
(1, 'bp_sys', 'TA systolique', 'number', NULL, 'mmHg', 3),
(1, 'bp_dia', 'TA diastolique', 'number', NULL, 'mmHg', 4),
(1, 'temp_c', 'Température', 'number', NULL, '°C', 5),
(1, 'spo2', 'SpO₂', 'number', NULL, '%', 6),
(1, 'hr', 'Fréquence cardiaque', 'number', NULL, 'bpm', 7),
(1, 'rr', 'Fréquence respiratoire', 'number', NULL, '/min', 8),
(1, 'pain', 'Douleur (EVA)', 'select', '0,1,2,3,4,5,6,7,8,9,10', '/10', 9),
(1, 'glycemia', 'Glycémie capillaire', 'number', NULL, 'mg/dL', 10),
(1, 'complaint_note', 'Motif / plainte (court)', 'text', NULL, NULL, 11);

-- ---------------------------------------------------------------------------
-- Consultation session (triage → fee → appointment)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tbl_consultation (
  id INT NOT NULL AUTO_INCREMENT,
  facility_id INT NOT NULL,
  patient_id INT NOT NULL,
  encounter_id INT NULL,
  consultation_type VARCHAR(24) NOT NULL DEFAULT 'general' COMMENT 'general|specialist',
  status VARCHAR(32) NOT NULL DEFAULT 'triaged',
  chief_complaint TEXT NULL,
  consult_fee_xaf INT NOT NULL DEFAULT 5000,
  fee_charge_id INT NULL,
  fee_paid_at DATETIME NULL,
  appointment_id INT NULL,
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_cons_pat (patient_id),
  KEY idx_cons_fac (facility_id),
  CONSTRAINT fk_cons_fac FOREIGN KEY (facility_id) REFERENCES tbl_facility (id),
  CONSTRAINT fk_cons_pat FOREIGN KEY (patient_id) REFERENCES tbl_patient (id) ON DELETE CASCADE,
  CONSTRAINT fk_cons_enc FOREIGN KEY (encounter_id) REFERENCES tbl_encounter (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS tbl_consult_observation (
  id INT NOT NULL AUTO_INCREMENT,
  consultation_id INT NOT NULL,
  param_code VARCHAR(48) NOT NULL,
  value_text VARCHAR(512) NOT NULL,
  PRIMARY KEY (id),
  KEY idx_cobs_cons (consultation_id),
  CONSTRAINT fk_cobs_cons FOREIGN KEY (consultation_id) REFERENCES tbl_consultation (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------------
-- Prescriptions (lab + medication lines → clinical orders + pharmacy)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tbl_prescription (
  id INT NOT NULL AUTO_INCREMENT,
  facility_id INT NOT NULL,
  patient_id INT NOT NULL,
  encounter_id INT NULL,
  consultation_id INT NULL,
  prescriber_employee_id INT NULL,
  title VARCHAR(200) NOT NULL DEFAULT 'Prescription',
  status VARCHAR(24) NOT NULL DEFAULT 'active',
  notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_rx_pat (patient_id),
  KEY idx_rx_fac (facility_id),
  CONSTRAINT fk_rx_fac FOREIGN KEY (facility_id) REFERENCES tbl_facility (id),
  CONSTRAINT fk_rx_pat FOREIGN KEY (patient_id) REFERENCES tbl_patient (id) ON DELETE CASCADE,
  CONSTRAINT fk_rx_cons FOREIGN KEY (consultation_id) REFERENCES tbl_consultation (id) ON DELETE SET NULL,
  CONSTRAINT fk_rx_enc FOREIGN KEY (encounter_id) REFERENCES tbl_encounter (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS tbl_prescription_line (
  id INT NOT NULL AUTO_INCREMENT,
  prescription_id INT NOT NULL,
  line_type VARCHAR(20) NOT NULL COMMENT 'lab|medication',
  lab_catalog_id INT NULL,
  medication_name VARCHAR(200) NULL,
  medication_dose VARCHAR(120) NULL,
  medication_route VARCHAR(80) NULL,
  medication_frequency VARCHAR(120) NULL,
  duration_days INT NULL,
  instructions TEXT NULL,
  clinical_order_id INT NULL,
  dispense_status VARCHAR(24) NOT NULL DEFAULT 'pending',
  dispensed_qty INT NOT NULL DEFAULT 0,
  dispensed_at DATETIME NULL,
  inventory_item_id INT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_rxl_rx (prescription_id),
  KEY idx_rxl_order (clinical_order_id),
  CONSTRAINT fk_rxl_rx FOREIGN KEY (prescription_id) REFERENCES tbl_prescription (id) ON DELETE CASCADE,
  CONSTRAINT fk_rxl_lab FOREIGN KEY (lab_catalog_id) REFERENCES tbl_lab_catalog (id) ON DELETE SET NULL,
  CONSTRAINT fk_rxl_inv FOREIGN KEY (inventory_item_id) REFERENCES tbl_inventory_item (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------------
-- RBAC — new permissions (admin already has all if re-seeded; these lines grant explicitly)
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO tbl_acl_permission (code, label, gap_area) VALUES
('lab.read', 'View lab worklist / catalog', 1),
('lab.write', 'Enter lab results', 1),
('consult.read', 'View consultations', 1),
('consult.write', 'Create consultations & book follow-up', 1),
('prescription.read', 'View prescriptions', 1),
('prescription.write', 'Create prescriptions & place orders', 1),
('pharmacy.read', 'View pharmacy queue', 6),
('pharmacy.write', 'Dispense medications', 6);

INSERT IGNORE INTO tbl_acl_role_permission (role, permission_id)
SELECT '1', id FROM tbl_acl_permission WHERE code IN (
  'lab.read','lab.write','consult.read','consult.write','prescription.read','prescription.write','pharmacy.read','pharmacy.write'
);
INSERT IGNORE INTO tbl_acl_role_permission (role, permission_id)
SELECT '2', id FROM tbl_acl_permission WHERE code IN (
  'lab.read','lab.write','consult.read','consult.write','prescription.read','prescription.write','pharmacy.read','pharmacy.write'
);
INSERT IGNORE INTO tbl_acl_role_permission (role, permission_id)
SELECT '3', id FROM tbl_acl_permission WHERE code IN (
  'lab.read','lab.write','consult.read','consult.write','prescription.read','prescription.write','pharmacy.read','pharmacy.write'
);
INSERT IGNORE INTO tbl_acl_role_permission (role, permission_id)
SELECT '4', id FROM tbl_acl_permission WHERE code IN ('pharmacy.read');

-- ---------------------------------------------------------------------------
-- Clinical order — link to lab catalog & prescription line (run once; ignore duplicate column errors)
-- ---------------------------------------------------------------------------
ALTER TABLE tbl_clinical_order ADD COLUMN lab_catalog_id INT NULL;
ALTER TABLE tbl_clinical_order ADD COLUMN prescription_line_id INT NULL;

ALTER TABLE tbl_prescription_line
  ADD CONSTRAINT fk_rxl_ord FOREIGN KEY (clinical_order_id) REFERENCES tbl_clinical_order (id) ON DELETE SET NULL;
