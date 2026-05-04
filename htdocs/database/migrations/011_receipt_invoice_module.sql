-- ---------------------------------------------------------------------------
-- HMS — Receipts & invoices (fiscal documents) + billing companies
-- Run after 001_multi_site_platform.sql (tbl_facility, tbl_patient, tbl_charge).
-- Optional: 010_transactions_table.sql for transaction_id FK usage (column nullable).
-- Idempotent: CREATE TABLE IF NOT EXISTS.
-- ---------------------------------------------------------------------------

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS tbl_billing_company (
  id INT NOT NULL AUTO_INCREMENT,
  facility_id INT NOT NULL,
  name VARCHAR(220) NOT NULL,
  tax_id VARCHAR(80) NULL,
  billing_address TEXT NULL,
  phone VARCHAR(48) NULL,
  email VARCHAR(180) NULL,
  status TINYINT NOT NULL DEFAULT 1 COMMENT '1=active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_bcompany_fac (facility_id),
  CONSTRAINT fk_bcompany_fac FOREIGN KEY (facility_id) REFERENCES tbl_facility (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS tbl_billing_document (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  facility_id INT NOT NULL,
  doc_type VARCHAR(16) NOT NULL COMMENT 'receipt|invoice',
  doc_number VARCHAR(48) NOT NULL,
  patient_id INT NULL,
  company_id INT NULL,
  payer_snapshot VARCHAR(255) NULL,
  company_snapshot VARCHAR(255) NULL,
  total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  tax_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  payment_method VARCHAR(64) NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'issued',
  source_module VARCHAR(48) NOT NULL DEFAULT '' COMMENT 'consultation_fee|charge|transaction|pharmacy_dispense|lab_fee|manual_invoice',
  source_pk INT UNSIGNED NOT NULL DEFAULT 0,
  charge_id INT NULL,
  transaction_id INT UNSIGNED NULL,
  consultation_id INT NULL,
  prescription_id INT NULL,
  prescription_line_id INT NULL,
  lab_result_id INT NULL,
  notes VARCHAR(600) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_bdoc_fac_number (facility_id, doc_number),
  KEY idx_bdoc_pat (patient_id),
  KEY idx_bdoc_company (company_id),
  KEY idx_bdoc_charge (charge_id),
  KEY idx_bdoc_txn (transaction_id),
  KEY idx_bdoc_source (facility_id, source_module, source_pk),
  CONSTRAINT fk_bdoc_fac FOREIGN KEY (facility_id) REFERENCES tbl_facility (id) ON DELETE CASCADE,
  CONSTRAINT fk_bdoc_pat FOREIGN KEY (patient_id) REFERENCES tbl_patient (id) ON DELETE SET NULL,
  CONSTRAINT fk_bdoc_company FOREIGN KEY (company_id) REFERENCES tbl_billing_company (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS tbl_billing_document_line (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  document_id INT UNSIGNED NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  description VARCHAR(512) NOT NULL,
  quantity DECIMAL(12,2) NOT NULL DEFAULT 1.00,
  unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  line_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (id),
  KEY idx_bdocline_doc (document_id),
  CONSTRAINT fk_bdocline_doc FOREIGN KEY (document_id) REFERENCES tbl_billing_document (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
