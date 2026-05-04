-- ---------------------------------------------------------------------------
-- HMS — Patient Pre-Paid Wallet System (Phase 1)
-- Run after previous migrations.
-- Adds closed-loop payment tracking and QR-code ready wallets.
-- ---------------------------------------------------------------------------

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS tbl_patient_wallet (
  id INT NOT NULL AUTO_INCREMENT,
  facility_id INT NOT NULL,
  patient_id INT NOT NULL,
  balance DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  status VARCHAR(20) NOT NULL DEFAULT 'active' COMMENT 'active, suspended, closed',
  qr_token VARCHAR(255) NULL COMMENT 'Unique token derived for QR code generation',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_wallet_pat_fac (patient_id, facility_id),
  UNIQUE KEY uq_wallet_qr_token (qr_token),
  CONSTRAINT fk_wallet_pat FOREIGN KEY (patient_id) REFERENCES tbl_patient (id) ON DELETE CASCADE,
  CONSTRAINT fk_wallet_fac FOREIGN KEY (facility_id) REFERENCES tbl_facility (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS tbl_patient_wallet_txn (
  id INT NOT NULL AUTO_INCREMENT,
  wallet_id INT NOT NULL,
  txn_type VARCHAR(32) NOT NULL COMMENT 'deposit_cash, deposit_gbpay, payment_services, refund',
  direction VARCHAR(10) NOT NULL COMMENT 'cr (credit/top-up) or dr (debit/spend)',
  amount DECIMAL(15,2) NOT NULL,
  balance_after DECIMAL(15,2) NOT NULL,
  reference_id VARCHAR(100) NULL COMMENT 'External GBPAY ref or Internal HMS Receipt ID',
  gl_journal_id INT NULL COMMENT 'Link to tbl_fin_journal_header if applicable',
  notes VARCHAR(255) NULL,
  created_by INT NULL COMMENT 'Employee ID if done by cashier, NULL if by webhook',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_wallet_txn_wid (wallet_id),
  CONSTRAINT fk_wallet_txn_wid FOREIGN KEY (wallet_id) REFERENCES tbl_patient_wallet (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Auto-provision a wallet for existing patients
INSERT IGNORE INTO tbl_patient_wallet (facility_id, patient_id, balance, status, qr_token)
SELECT facility_id, id, 0.00, 'active', SHA2(CONCAT(id, '-', facility_id, '-', UUID()), 256)
FROM tbl_patient;
