-- ---------------------------------------------------------------------------
-- HMS — Purchase order workflow (vendor, approve, issue, receive)
-- Run after 034_inventory_purchase_orders.sql
-- Idempotent where possible: safe to re-run if columns already exist (may skip errors manually).
-- ---------------------------------------------------------------------------

SET NAMES utf8mb4;

-- Legacy value from 034 enum included "sent" — map to "issued" before enum change
UPDATE tbl_purchase_order SET status = 'issued' WHERE status = 'sent';

ALTER TABLE tbl_purchase_order
  MODIFY COLUMN status ENUM('draft','approved','issued','received','cancelled') NOT NULL DEFAULT 'draft';

-- Optional: ignore "Duplicate column" if re-running
ALTER TABLE tbl_purchase_order ADD COLUMN approved_at DATETIME NULL DEFAULT NULL;
ALTER TABLE tbl_purchase_order ADD COLUMN approved_by INT NULL DEFAULT NULL COMMENT 'tbl_employee.id';
ALTER TABLE tbl_purchase_order ADD COLUMN issued_at DATETIME NULL DEFAULT NULL;
ALTER TABLE tbl_purchase_order ADD COLUMN issued_by INT NULL DEFAULT NULL COMMENT 'tbl_employee.id';
ALTER TABLE tbl_purchase_order ADD COLUMN po_notes VARCHAR(500) NULL DEFAULT NULL COMMENT 'Internal notes for audit / reference';
