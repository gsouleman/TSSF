-- Transactions ledger: link rows to fiscal receipts + optional cleanup of legacy row #1 (#TS0001).
-- Run after 014_transactions_table.sql and 011_receipt_invoice_module.sql.
--
-- IMPORTANT (InfinityFree / shared hosting): Do not use information_schema or PREPARE … FROM
-- user variables — many hosts deny access to information_schema for the web DB user.
--
-- Idempotent on MariaDB 10.0.2+ (ADD COLUMN IF NOT EXISTS) and MariaDB 10.5.2+ (CREATE INDEX IF NOT EXISTS).
-- On plain MySQL without IF NOT EXISTS: run each statement once; if you see "Duplicate column" / "Duplicate key name",
-- that part is already applied — skip that line.

SET NAMES utf8mb4;

-- Optional: remove legacy test row #TS0001 (skip this line if id=1 is real production data).
DELETE FROM tbl_transaction WHERE id = 1 LIMIT 1;

ALTER TABLE tbl_transaction
ADD COLUMN IF NOT EXISTS billing_document_id INT UNSIGNED NULL DEFAULT NULL COMMENT 'Issued receipt id (tbl_billing_document.id)' AFTER appointment_id;

CREATE UNIQUE INDEX IF NOT EXISTS uq_txn_billing_document ON tbl_transaction (billing_document_id);
