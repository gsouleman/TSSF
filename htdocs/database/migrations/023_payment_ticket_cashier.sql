-- ---------------------------------------------------------------------------
-- HMS — Payment tickets (cashier lookup codes) + cashier permission
-- Run after 001 (tbl_facility, tbl_patient), optional 003 (tbl_consultation), 011 (billing documents).
-- Idempotent: CREATE TABLE IF NOT EXISTS; INSERT IGNORE permissions.
-- ---------------------------------------------------------------------------

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS tbl_payment_ticket (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  facility_id INT NOT NULL,
  ticket_code VARCHAR(32) NOT NULL COMMENT 'Human code e.g. PAY-2026-00000001',
  patient_id INT NOT NULL,
  consultation_id INT NULL,
  prescription_id INT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'pending' COMMENT 'pending|paid|cancelled',
  total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  lines_json MEDIUMTEXT NOT NULL COMMENT 'JSON array of line items (consultation, laboratory, radiology, pharmacy, other)',
  charge_id INT NULL,
  billing_document_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by INT NOT NULL DEFAULT 0,
  paid_at DATETIME NULL,
  paid_by INT NULL,
  notes VARCHAR(500) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_payticket_fac_code (facility_id, ticket_code),
  KEY idx_payticket_pat (patient_id),
  KEY idx_payticket_cons (facility_id, consultation_id),
  KEY idx_payticket_status (facility_id, status),
  CONSTRAINT fk_payticket_fac FOREIGN KEY (facility_id) REFERENCES tbl_facility (id) ON DELETE CASCADE,
  CONSTRAINT fk_payticket_pat FOREIGN KEY (patient_id) REFERENCES tbl_patient (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO tbl_acl_permission (code, label, gap_area) VALUES
('cashier.write', 'Cashier: collect payments and issue receipts from payment codes', 1);

INSERT IGNORE INTO tbl_acl_role_permission (role, permission_id)
SELECT '1', id FROM tbl_acl_permission WHERE code = 'cashier.write';
