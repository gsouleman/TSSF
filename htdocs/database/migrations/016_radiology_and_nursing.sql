-- ============================================================
-- Migration 016: Radiology & Imaging + Nursing Station
-- TSSF Solidarity of Hearts Hospital SOA
-- ============================================================

-- -------------------------------------------------------
-- 1. Radiology Result Table
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS tbl_radiology_result (
    id              INT            NOT NULL AUTO_INCREMENT PRIMARY KEY,
    facility_id     INT            NOT NULL DEFAULT 0,
    patient_id      INT            NOT NULL,
    referred_by_id  INT            DEFAULT NULL,
    exam_name       VARCHAR(255)   NOT NULL,
    modality        VARCHAR(50)    NOT NULL DEFAULT 'X-Ray',
    body_part       VARCHAR(120)   NOT NULL DEFAULT '',
    appointment_date DATE          NOT NULL,
    status          ENUM('pending','in_progress','received') NOT NULL DEFAULT 'pending',
    findings        TEXT,
    notes           TEXT,
    created_by      INT            DEFAULT NULL,
    created_at      TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_facility (facility_id),
    KEY idx_patient  (patient_id),
    KEY idx_status   (status),
    KEY idx_date     (appointment_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- 2. New Staff Roles
-- -------------------------------------------------------
INSERT IGNORE INTO tbl_role (title, role) VALUES
    ('Radiology Tech', 6),
    ('Nurse',          7),
    ('Nursing Aid',    8);

-- -------------------------------------------------------
-- 3. Seed: Radiology & Imaging tests in Service Catalog
-- -------------------------------------------------------
INSERT INTO tbl_service_catalog (facility_id, category, subcategory, name, cpt_code, price) VALUES
-- X-Ray
(0, 'radiology', 'X-Ray',       'Chest X-Ray (1 View)',            'R001', 8000),
(0, 'radiology', 'X-Ray',       'Chest X-Ray (2 Views)',           'R002', 12000),
(0, 'radiology', 'X-Ray',       'Abdomen X-Ray',                   'R003', 10000),
(0, 'radiology', 'X-Ray',       'Pelvis X-Ray',                    'R004', 10000),
(0, 'radiology', 'X-Ray',       'Spine X-Ray (AP/Lateral)',        'R005', 12000),
(0, 'radiology', 'X-Ray',       'Limb / Extremity X-Ray',         'R006', 8000),
(0, 'radiology', 'X-Ray',       'Skull X-Ray',                     'R007', 10000),
-- Ultrasound
(0, 'radiology', 'Ultrasound',  'Abdominal Ultrasound',            'R010', 15000),
(0, 'radiology', 'Ultrasound',  'Pelvic Ultrasound',               'R011', 15000),
(0, 'radiology', 'Ultrasound',  'Obstetric Ultrasound',            'R012', 18000),
(0, 'radiology', 'Ultrasound',  'Renal Ultrasound',                'R013', 15000),
(0, 'radiology', 'Ultrasound',  'Thyroid Ultrasound',              'R014', 12000),
(0, 'radiology', 'Ultrasound',  'Breast Ultrasound',               'R015', 15000),
(0, 'radiology', 'Ultrasound',  'Doppler Ultrasound (Vascular)',   'R016', 25000),
(0, 'radiology', 'Ultrasound',  'Prostate Ultrasound',             'R017', 15000),
-- CT Scan
(0, 'radiology', 'CT Scan',     'CT Head / Brain',                 'R020', 45000),
(0, 'radiology', 'CT Scan',     'CT Chest',                        'R021', 50000),
(0, 'radiology', 'CT Scan',     'CT Abdomen & Pelvis',             'R022', 55000),
(0, 'radiology', 'CT Scan',     'CT Spine',                        'R023', 50000),
-- MRI
(0, 'radiology', 'MRI',         'MRI Brain',                       'R030', 80000),
(0, 'radiology', 'MRI',         'MRI Spine (Cervical/Lumbar)',     'R031', 85000),
(0, 'radiology', 'MRI',         'MRI Abdomen',                     'R032', 90000),
(0, 'radiology', 'MRI',         'MRI Knee / Joint',                'R033', 75000),
-- Other Imaging
(0, 'radiology', 'Cardiac',     'Electrocardiogram (ECG)',         'R040', 6000),
(0, 'radiology', 'Cardiac',     'Echocardiography',                'R041', 25000),
(0, 'radiology', 'Screening',   'Mammography',                     'R050', 20000),
(0, 'radiology', 'Screening',   'Fluoroscopy',                     'R051', 30000),
(0, 'radiology', 'Screening',   'Bone Densitometry (DEXA)',        'R052', 25000)
ON DUPLICATE KEY UPDATE name=VALUES(name), price=VALUES(price);

-- -------------------------------------------------------
-- 4. ACL Permissions: Radiology & Nursing
-- -------------------------------------------------------
INSERT IGNORE INTO tbl_acl_permission (code, label, gap_area) VALUES
    ('radiology.read',  'View radiology results',   1),
    ('radiology.write', 'Create/edit radiology results', 1),
    ('nursing.read',    'View nursing station',     1),
    ('nursing.write',   'Record vitals and care notes', 1);

-- Admin (role 1) — already gets everything via code

-- Doctor (role 2) — can view and order radiology, view nursing
INSERT IGNORE INTO tbl_acl_role_permission (role, permission_id)
SELECT '2', id FROM tbl_acl_permission WHERE code IN ('radiology.read', 'radiology.write', 'nursing.read');

-- Front Desk (role 3) — can view radiology
INSERT IGNORE INTO tbl_acl_role_permission (role, permission_id)
SELECT '3', id FROM tbl_acl_permission WHERE code IN ('radiology.read');

-- Radiology Tech (role 6) — full radiology access
INSERT IGNORE INTO tbl_acl_role_permission (role, permission_id)
SELECT '6', id FROM tbl_acl_permission WHERE code IN ('radiology.read', 'radiology.write');

-- Also give Radiology Tech basic OPD read and patient read
INSERT IGNORE INTO tbl_acl_role_permission (role, permission_id)
SELECT '6', id FROM tbl_acl_permission WHERE code IN ('opd.read', 'patient.read');

-- Nurse (role 7) — nursing station + OPD + patient + radiology view
INSERT IGNORE INTO tbl_acl_role_permission (role, permission_id)
SELECT '7', id FROM tbl_acl_permission WHERE code IN ('nursing.read', 'nursing.write', 'opd.read', 'opd.write', 'patient.read', 'patient.write', 'lab.read', 'radiology.read');

-- Nursing Aid (role 8) — nursing station read + OPD read + patient read
INSERT IGNORE INTO tbl_acl_role_permission (role, permission_id)
SELECT '8', id FROM tbl_acl_permission WHERE code IN ('nursing.read', 'opd.read', 'patient.read', 'lab.read', 'radiology.read');
