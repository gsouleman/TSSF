-- ---------------------------------------------------------------------------
-- HMS — Credit & receivables (patient AR), installment plans, follow-up log,
-- optional OHADA-style simple journal (accrual + collection).
-- Run after 001 (tbl_charge, tbl_patient, tbl_facility), 011 (billing documents).
-- MariaDB-oriented ADD COLUMN IF NOT EXISTS on tbl_charge.
-- ---------------------------------------------------------------------------

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS tbl_credit_account (
  id INT NOT NULL AUTO_INCREMENT,
  facility_id INT NOT NULL,
  patient_id INT NOT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'active' COMMENT 'active|closed|collections|written_off',
  emergency_payment_pending TINYINT NOT NULL DEFAULT 0 COMMENT '1=walk-in / payment pending flag',
  guarantor_name VARCHAR(220) NULL,
  guarantor_phone VARCHAR(64) NULL,
  guarantor_relation VARCHAR(120) NULL,
  notes TEXT NULL,
  invoice_due_date DATE NULL COMMENT 'Optional target for full payment',
  opened_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  closed_at DATETIME NULL,
  writeoff_at DATETIME NULL,
  writeoff_approved_by INT NULL,
  writeoff_note VARCHAR(600) NULL,
  PRIMARY KEY (id),
  KEY idx_cr_fac_pat (facility_id, patient_id),
  KEY idx_cr_fac_stat (facility_id, status),
  CONSTRAINT fk_cr_fac FOREIGN KEY (facility_id) REFERENCES tbl_facility (id) ON DELETE CASCADE,
  CONSTRAINT fk_cr_pat FOREIGN KEY (patient_id) REFERENCES tbl_patient (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS tbl_credit_payment (
  id INT NOT NULL AUTO_INCREMENT,
  credit_account_id INT NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  payment_method VARCHAR(64) NOT NULL DEFAULT 'Cash',
  notes VARCHAR(600) NULL,
  billing_document_id INT UNSIGNED NULL COMMENT 'Fiscal receipt when issued',
  installment_plan_id INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_cpay_acct (credit_account_id),
  KEY idx_cpay_bdoc (billing_document_id),
  CONSTRAINT fk_cpay_acct FOREIGN KEY (credit_account_id) REFERENCES tbl_credit_account (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS tbl_credit_installment_plan (
  id INT NOT NULL AUTO_INCREMENT,
  credit_account_id INT NOT NULL,
  title VARCHAR(220) NOT NULL DEFAULT 'Installment plan',
  installment_count INT NOT NULL DEFAULT 1,
  amount_each DECIMAL(12,2) NOT NULL,
  first_due_date DATE NOT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'active' COMMENT 'active|completed|defaulted',
  notes VARCHAR(600) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_cplan_acct (credit_account_id),
  CONSTRAINT fk_cplan_acct FOREIGN KEY (credit_account_id) REFERENCES tbl_credit_account (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS tbl_credit_adjustment (
  id INT NOT NULL AUTO_INCREMENT,
  credit_account_id INT NOT NULL,
  kind VARCHAR(24) NOT NULL DEFAULT 'writeoff' COMMENT 'writeoff|discount|other',
  amount DECIMAL(12,2) NOT NULL COMMENT 'Positive reduces balance',
  approved_by INT NOT NULL DEFAULT 0,
  notes VARCHAR(600) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_cadj_acct (credit_account_id),
  CONSTRAINT fk_cadj_acct FOREIGN KEY (credit_account_id) REFERENCES tbl_credit_account (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS tbl_credit_followup (
  id INT NOT NULL AUTO_INCREMENT,
  credit_account_id INT NOT NULL,
  channel VARCHAR(32) NOT NULL DEFAULT 'note' COMMENT 'sms|email|call|note|escalate',
  summary VARCHAR(600) NOT NULL,
  created_by INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_cfup_acct (credit_account_id),
  CONSTRAINT fk_cfup_acct FOREIGN KEY (credit_account_id) REFERENCES tbl_credit_account (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Simple GL (OHADA-oriented codes as labels; extend later with full chart)
CREATE TABLE IF NOT EXISTS tbl_fin_journal_header (
  id INT NOT NULL AUTO_INCREMENT,
  facility_id INT NOT NULL,
  entry_date DATE NOT NULL,
  reference VARCHAR(64) NOT NULL DEFAULT '',
  narration VARCHAR(512) NOT NULL DEFAULT '',
  source_type VARCHAR(48) NOT NULL DEFAULT '' COMMENT 'credit_charge|credit_payment|receipt_sync|manual',
  source_id INT NOT NULL DEFAULT 0,
  created_by INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_fin_jrnl_src (facility_id, source_type, source_id),
  KEY idx_fin_jrnl_date (facility_id, entry_date),
  CONSTRAINT fk_fin_jh_fac FOREIGN KEY (facility_id) REFERENCES tbl_facility (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS tbl_fin_journal_line (
  id INT NOT NULL AUTO_INCREMENT,
  journal_id INT NOT NULL,
  account_code VARCHAR(32) NOT NULL,
  account_label VARCHAR(160) NOT NULL DEFAULT '',
  debit DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  credit DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (id),
  KEY idx_fin_jl_j (journal_id),
  CONSTRAINT fk_fin_jl_j FOREIGN KEY (journal_id) REFERENCES tbl_fin_journal_header (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE tbl_charge ADD COLUMN IF NOT EXISTS credit_account_id INT NULL DEFAULT NULL;
ALTER TABLE tbl_charge ADD COLUMN IF NOT EXISTS on_credit TINYINT NOT NULL DEFAULT 0 COMMENT '1=posted to patient AR, no immediate receipt';

CREATE INDEX IF NOT EXISTS idx_charge_credit_acct ON tbl_charge (credit_account_id);
CREATE INDEX IF NOT EXISTS idx_charge_on_credit ON tbl_charge (facility_id, patient_id, on_credit);

-- Optional FK from charge to credit account (skip if orphan rows possible on downgrade)
-- ALTER TABLE tbl_charge ADD CONSTRAINT fk_charge_credit FOREIGN KEY (credit_account_id) REFERENCES tbl_credit_account (id) ON DELETE SET NULL;

INSERT IGNORE INTO tbl_acl_permission (code, label, gap_area) VALUES
('credit.read', 'View patient credit / receivables', 5),
('credit.write', 'Manage credit accounts, payments, write-offs', 5);

INSERT IGNORE INTO tbl_acl_role_permission (role, permission_id)
SELECT '1', id FROM tbl_acl_permission WHERE code IN ('credit.read', 'credit.write');

INSERT IGNORE INTO tbl_acl_role_permission (role, permission_id)
SELECT '4', id FROM tbl_acl_permission WHERE code IN ('credit.read', 'credit.write');
