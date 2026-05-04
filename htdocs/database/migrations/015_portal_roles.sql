-- Migration: Add Lab Technician, Pharmacist, and Front Desk roles
-- Run this once on the production database

INSERT IGNORE INTO tbl_role (title, role) VALUES
    ('Front Desk', 3),
    ('Lab Technician', 4),
    ('Pharmacist', 5);

-- Update existing role 3 to "Front Desk" if it was "Nurse"
UPDATE tbl_role SET title = 'Front Desk' WHERE role = 3 AND title IN ('Nurse','nurse');
