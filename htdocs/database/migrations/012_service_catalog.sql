-- ============================================================
-- Migration 012: Service Price Catalog
-- TSSF Solidarity of Hearts Hospital SOA — FCFA pricing
-- ============================================================

CREATE TABLE IF NOT EXISTS tbl_service_catalog (
    id            INT            NOT NULL AUTO_INCREMENT PRIMARY KEY,
    facility_id   INT            NOT NULL DEFAULT 0,
    category      VARCHAR(50)    NOT NULL DEFAULT 'service',
    subcategory   VARCHAR(100)   NOT NULL DEFAULT '',
    name          VARCHAR(255)   NOT NULL,
    description   TEXT,
    cpt_code      VARCHAR(20)    NOT NULL DEFAULT '',
    price         DECIMAL(12,2)  NOT NULL DEFAULT 0.00,
    currency      VARCHAR(10)    NOT NULL DEFAULT 'XAF',
    status        TINYINT(1)     NOT NULL DEFAULT 1,
    sort_order    INT            NOT NULL DEFAULT 0,
    created_at    TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_facility_cat (facility_id, category),
    KEY idx_status (status),
    UNIQUE KEY idx_unique_cpt (facility_id, cpt_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Seed: Consultation fees
-- ============================================================
INSERT INTO tbl_service_catalog (facility_id, category, subcategory, name, cpt_code, price) VALUES
(0, 'consultation', 'General',     'General Consultation',             'C001', 3000),
(0, 'consultation', 'General',     'Consultation With Prescription',   'C002', 4000),
(0, 'consultation', 'Specialist',  'Specialist Consultation',          'C010', 8000),
(0, 'consultation', 'Specialist',  'Cardiologist Consultation',        'C011', 12000),
(0, 'consultation', 'Specialist',  'Gynecologist Consultation',        'C012', 10000),
(0, 'consultation', 'Specialist',  'Pediatrician Consultation',        'C013', 10000),
(0, 'consultation', 'Specialist',  'Ophthalmologist Consultation',     'C014', 10000),
(0, 'consultation', 'Specialist',  'Dermatologist Consultation',       'C015', 10000),
(0, 'consultation', 'Specialist',  'Orthopedist Consultation',         'C016', 12000),
(0, 'consultation', 'Emergency',   'Emergency Consultation',           'C020', 5000),
(0, 'consultation', 'Emergency',   'Night / Weekend Consultation',     'C021', 7000)
ON DUPLICATE KEY UPDATE name=VALUES(name), price=VALUES(price);

-- ============================================================
-- Seed: Laboratory tests
-- ============================================================
INSERT INTO tbl_service_catalog (facility_id, category, subcategory, name, cpt_code, price) VALUES
(0, 'laboratory', 'Hematology',   'Full Blood Count (FBC)',              'L001', 5000),
(0, 'laboratory', 'Hematology',   'Blood Group + Rhesus',                'L002', 3000),
(0, 'laboratory', 'Hematology',   'Blood Smear',                         'L003', 3500),
(0, 'laboratory', 'Hematology',   'Platelet Count',                      'L004', 3000),
(0, 'laboratory', 'Biochemistry', 'Fasting Blood Sugar',                 'L010', 2500),
(0, 'laboratory', 'Biochemistry', 'Urea & Creatinine',                   'L011', 4000),
(0, 'laboratory', 'Biochemistry', 'Liver Function Test',                 'L012', 5000),
(0, 'laboratory', 'Biochemistry', 'Lipid Profile',                       'L013', 6000),
(0, 'laboratory', 'Biochemistry', 'C-Reactive Protein (CRP)',            'L014', 3500),
(0, 'laboratory', 'Microbiology', 'Urinalysis (ECBU)',                   'L020', 5000),
(0, 'laboratory', 'Microbiology', 'Blood Culture',                       'L021', 8000),
(0, 'laboratory', 'Microbiology', 'Malaria Rapid Test / Thin Smear',     'L022', 2500),
(0, 'laboratory', 'Microbiology', 'HIV Test',                            'L023', 3000),
(0, 'laboratory', 'Microbiology', 'Hepatitis B Test',                    'L024', 4000),
(0, 'laboratory', 'Microbiology', 'Syphilis Test',                       'L025', 3500),
(0, 'laboratory', 'Hormones',     'Pregnancy Test (Beta-HCG)',           'L030', 3000),
(0, 'laboratory', 'Hormones',     'Thyroid Profile',                     'L031', 8000),
(0, 'laboratory', 'Hormones',     'Prostate Specific Antigen (PSA)',     'L032', 7500),
(0, 'laboratory', 'Imaging',      'X-Ray (1 View)',                      'L040', 8000),
(0, 'laboratory', 'Imaging',      'Abdominal Ultrasound',                'L041', 15000),
(0, 'laboratory', 'Imaging',      'Obstetric Ultrasound',                'L042', 18000),
(0, 'laboratory', 'Imaging',      'Electrocardiogram (ECG)',             'L043', 6000)
ON DUPLICATE KEY UPDATE name=VALUES(name), price=VALUES(price);

-- ============================================================
-- Seed: Other hospital services
-- ============================================================
INSERT INTO tbl_service_catalog (facility_id, category, subcategory, name, cpt_code, price) VALUES
(0, 'service', 'Admission',      'Admission / File Fee',                  'S001', 2000),
(0, 'service', 'Nursing',        'Nursing Care (Injection/Dressing)',     'S010', 1500),
(0, 'service', 'Nursing',        'IV Drip Placement',                     'S011', 3000),
(0, 'service', 'Nursing',        'Vitals / Blood Pressure Check',         'S012', 1000),
(0, 'service', 'Procedures',     'Wound Suturing (Simple)',               'S020', 10000),
(0, 'service', 'Procedures',     'Wound Suturing (Complex)',              'S021', 20000),
(0, 'service', 'Procedures',     'Foreign Body Extraction',               'S022', 8000),
(0, 'service', 'Procedures',     'Urinary Catheter Placement',            'S023', 5000),
(0, 'service', 'Procedures',     'Circumcision',                          'S024', 35000),
(0, 'service', 'Hospitalization','Hospitalization (Ward / Day)',          'S030', 8000),
(0, 'service', 'Hospitalization','Hospitalization (Private Room / Day)',  'S031', 20000),
(0, 'service', 'Hospitalization','Intensive Care (ICU / Day)',            'S032', 45000),
(0, 'service', 'Maternity',      'Normal Delivery',                       'S040', 65000),
(0, 'service', 'Maternity',      'Cesarean Section',                      'S041', 180000),
(0, 'service', 'Maternity',      'Antenatal Consultation (ANC)',          'S042', 5000),
(0, 'service', 'Pharmacy',       'Dispensing Fee',                        'S050', 500),
(0, 'service', 'Certificate',    'Medical Certificate',                   'S060', 3000),
(0, 'service', 'Certificate',    'Health Card / Medical Visit',           'S061', 5000),
(0, 'service', 'Ambulance',      'Ambulance Transport (Local)',           'S070', 15000),
(0, 'service', 'Ambulance',      'Ambulance Transport (Inter-City)',      'S071', 45000)
ON DUPLICATE KEY UPDATE name=VALUES(name), price=VALUES(price);

-- ============================================================
-- Seed: Pharmacy
-- ============================================================
INSERT INTO tbl_service_catalog (facility_id, category, subcategory, name, cpt_code, price) VALUES
(0, 'pharmacy', 'Analgesics',       'Paracetamol 500mg',               'P001', 500),
(0, 'pharmacy', 'Analgesics',       'Ibuprofen 400mg',                 'P002', 800),
(0, 'pharmacy', 'Analgesics',       'Diclofenac 50mg',                 'P003', 1000),
(0, 'pharmacy', 'Analgesics',       'Efferalgan 500mg',                'P004', 1500),
(0, 'pharmacy', 'Antimalarials',    'Artemether/Lumefantrine 20/120mg','P010', 2500),
(0, 'pharmacy', 'Antimalarials',    'Artesunate Injection 60mg',       'P011', 3000),
(0, 'pharmacy', 'Antimalarials',    'Quinine Sulfate 300mg',           'P012', 1500),
(0, 'pharmacy', 'Antibiotics',      'Amoxicillin 500mg',               'P020', 1000),
(0, 'pharmacy', 'Antibiotics',      'Azithromycin 500mg',              'P021', 2500),
(0, 'pharmacy', 'Antibiotics',      'Ciprofloxacin 500mg',             'P022', 1800),
(0, 'pharmacy', 'Antibiotics',      'Metronidazole 500mg',             'P023', 800),
(0, 'pharmacy', 'Antibiotics',      'Ceftriaxone 1g IV',               'P024', 3000),
(0, 'pharmacy', 'Gastrointestinal', 'Omeprazole 20mg',                 'P030', 2000),
(0, 'pharmacy', 'Gastrointestinal', 'Spasfon (Phloroglucinol 80mg)',   'P031', 2000),
(0, 'pharmacy', 'Supplements',      'Vitamin C 1000mg',                'P040', 1000),
(0, 'pharmacy', 'Supplements',      'Oral Rehydration Salts (ORS)',    'P041', 500)
ON DUPLICATE KEY UPDATE name=VALUES(name), price=VALUES(price);
