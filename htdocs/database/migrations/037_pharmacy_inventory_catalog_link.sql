-- ---------------------------------------------------------------------------
-- HMS — Pharmacy ↔ inventory ↔ service catalog (run after 012, 003, 001/027).
-- Idempotent: adds columns/keys/FKs only when missing.
-- ---------------------------------------------------------------------------

SET NAMES utf8mb4;

SET @hms_db := DATABASE();

-- tbl_inventory_item.service_catalog_id → tbl_service_catalog (unit sell price)
SET @hms_col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @hms_db
    AND TABLE_NAME = 'tbl_inventory_item'
    AND COLUMN_NAME = 'service_catalog_id'
);
SET @hms_sql := IF(
  @hms_col_exists = 0,
  'ALTER TABLE tbl_inventory_item ADD COLUMN service_catalog_id INT NULL DEFAULT NULL AFTER reorder_level, ADD KEY idx_invitem_svc (service_catalog_id), ADD CONSTRAINT fk_invitem_svc FOREIGN KEY (service_catalog_id) REFERENCES tbl_service_catalog (id) ON DELETE SET NULL',
  'SELECT ''037: service_catalog_id already present — skipped'' AS migration_note'
);
PREPARE hms_stmt FROM @hms_sql;
EXECUTE hms_stmt;
DEALLOCATE PREPARE hms_stmt;

-- tbl_prescription_line.pharmacy_catalog_id → formulary / default pricing
SET @hms_col_exists2 := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @hms_db
    AND TABLE_NAME = 'tbl_prescription_line'
    AND COLUMN_NAME = 'pharmacy_catalog_id'
);
SET @hms_sql2 := IF(
  @hms_col_exists2 = 0,
  'ALTER TABLE tbl_prescription_line ADD COLUMN pharmacy_catalog_id INT NULL DEFAULT NULL AFTER inventory_item_id, ADD KEY idx_rxl_pharm_cat (pharmacy_catalog_id), ADD CONSTRAINT fk_rxl_pharm_cat FOREIGN KEY (pharmacy_catalog_id) REFERENCES tbl_service_catalog (id) ON DELETE SET NULL',
  'SELECT ''037: pharmacy_catalog_id already present — skipped'' AS migration_note'
);
PREPARE hms_stmt2 FROM @hms_sql2;
EXECUTE hms_stmt2;
DEALLOCATE PREPARE hms_stmt2;
