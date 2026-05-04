-- ---------------------------------------------------------------------------
-- HMS — Standard hospital / clinic departments (per facility)
-- Run after 001_multi_site_platform.sql (requires tbl_facility and
-- tbl_department.facility_id). Idempotent: skips rows that already exist for
-- the same facility_id + department_name.
-- ---------------------------------------------------------------------------

SET NAMES utf8mb4;

INSERT INTO tbl_department (department_name, description, status, facility_id)
SELECT v.department_name, v.description, 1, f.id
FROM tbl_facility f
CROSS JOIN (
    SELECT 'Emergency / A&E' AS department_name, 'Emergency medicine and acute admissions' AS description
    UNION ALL SELECT 'General Medicine', 'Internal medicine and general adult outpatient care'
    UNION ALL SELECT 'General Surgery', 'Elective and emergency general surgery'
    UNION ALL SELECT 'Orthopedics', 'Bones, joints, sports injuries, and musculoskeletal trauma'
    UNION ALL SELECT 'Pediatrics', 'Infants, children, and adolescent care'
    UNION ALL SELECT 'Obstetrics & Gynecology', 'Pregnancy, childbirth, and women''s health'
    UNION ALL SELECT 'Cardiology', 'Heart and vascular disorders'
    UNION ALL SELECT 'Pulmonology', 'Lung and respiratory disease'
    UNION ALL SELECT 'Gastroenterology', 'Digestive tract and liver disorders'
    UNION ALL SELECT 'Dermatology', 'Skin, hair, and nail conditions'
    UNION ALL SELECT 'Psychiatry', 'Mental and behavioral health'
    UNION ALL SELECT 'Radiology & Imaging', 'X-ray, CT, MRI, ultrasound, and interventional radiology'
    UNION ALL SELECT 'Anesthesiology', 'Perioperative anesthesia and acute pain'
    UNION ALL SELECT 'Critical Care / ICU', 'Intensive care and life support'
    UNION ALL SELECT 'Clinical Pathology & Laboratory', 'Laboratory diagnostics and blood bank support'
    UNION ALL SELECT 'Ophthalmology', 'Eye disease, vision, and ocular surgery'
    UNION ALL SELECT 'Urology', 'Urinary tract, male reproductive system, and renal surgery'
    UNION ALL SELECT 'Nephrology', 'Kidney disease, dialysis, and electrolyte disorders'
    UNION ALL SELECT 'Oncology', 'Medical oncology, chemotherapy, and tumour care coordination'
    UNION ALL SELECT 'Infectious Diseases', 'Tropical medicine, HIV/TB, and hospital infection control'
    UNION ALL SELECT 'Physical Medicine & Rehabilitation', 'Physiotherapy, occupational therapy, and rehab'
    UNION ALL SELECT 'Plastic & Reconstructive Surgery', 'Reconstructive, burns, and aesthetic surgery'
    UNION ALL SELECT 'Endocrinology & Diabetes', 'Hormonal disorders, thyroid, and diabetes mellitus'
    UNION ALL SELECT 'Hematology', 'Blood disorders and benign haematology'
    UNION ALL SELECT 'Geriatrics', 'Care of older adults and frailty'
    UNION ALL SELECT 'Allergy & Immunology', 'Allergic disease and immune-mediated conditions'
    UNION ALL SELECT 'Pain Management', 'Chronic pain and interventional pain procedures'
    UNION ALL SELECT 'Neurosurgery', 'Brain, spine, and peripheral nerve surgery'
    UNION ALL SELECT 'ENT / Otolaryngology', 'Ear, nose, throat, head and neck surgery'
    UNION ALL SELECT 'Dental / Oral & Maxillofacial', 'Dental surgery and oral-maxillofacial care'
    UNION ALL SELECT 'Family Medicine', 'Primary care across all ages'
    UNION ALL SELECT 'Nuclear Medicine', 'Radionuclide imaging and targeted therapies'
) AS v
WHERE NOT EXISTS (
    SELECT 1 FROM tbl_department d
    WHERE d.facility_id = f.id AND d.department_name = v.department_name
);
