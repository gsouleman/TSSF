-- ---------------------------------------------------------------------------
-- HMS — Inventory: categories, name catalog, stock movements, category_id
-- Run after 001 (tbl_inventory_item, tbl_facility).
-- Idempotent: adds tables/columns only when missing.
-- ---------------------------------------------------------------------------

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS tbl_inventory_category (
  id INT NOT NULL AUTO_INCREMENT,
  facility_id INT NOT NULL,
  name VARCHAR(128) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_inv_cat_fac_name (facility_id, name),
  CONSTRAINT fk_invc_fac FOREIGN KEY (facility_id) REFERENCES tbl_facility (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS tbl_inventory_name_catalog (
  id INT NOT NULL AUTO_INCREMENT,
  facility_id INT NOT NULL,
  name VARCHAR(250) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_invnm_fac_n (facility_id, name(191)),
  CONSTRAINT fk_invnm_fac FOREIGN KEY (facility_id) REFERENCES tbl_facility (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS tbl_inventory_stock_movement (
  id INT NOT NULL AUTO_INCREMENT,
  facility_id INT NOT NULL,
  inventory_item_id INT NOT NULL,
  qty_delta INT NOT NULL COMMENT 'Negative = out, positive = in',
  quantity_after INT NOT NULL DEFAULT 0,
  movement_type VARCHAR(32) NOT NULL DEFAULT 'adjustment' COMMENT 'purchase|adjustment|dispense|return|transfer',
  note VARCHAR(255) NULL,
  ref_table VARCHAR(48) NULL,
  ref_id INT NULL,
  created_by INT NULL COMMENT 'tbl_employee.id',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_mov_item (inventory_item_id, created_at),
  KEY idx_mov_fac (facility_id, created_at),
  CONSTRAINT fk_mov_fac FOREIGN KEY (facility_id) REFERENCES tbl_facility (id) ON DELETE CASCADE,
  CONSTRAINT fk_mov_item FOREIGN KEY (inventory_item_id) REFERENCES tbl_inventory_item (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SET @hms_db := DATABASE();
SET @hms_col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @hms_db
    AND TABLE_NAME = 'tbl_inventory_item'
    AND COLUMN_NAME = 'category_id'
);
SET @hms_sql := IF(
  @hms_col_exists = 0,
  'ALTER TABLE tbl_inventory_item ADD COLUMN category_id INT NULL DEFAULT NULL AFTER category, ADD KEY idx_invitem_cat (category_id), ADD CONSTRAINT fk_invitem_cat FOREIGN KEY (category_id) REFERENCES tbl_inventory_category (id) ON DELETE SET NULL',
  'SELECT ''027: category_id already present — skipped'' AS migration_note'
);
PREPARE hms_stmt FROM @hms_sql;
EXECUTE hms_stmt;
DEALLOCATE PREPARE hms_stmt;

-- Seed categories from existing item.category strings
INSERT IGNORE INTO tbl_inventory_category (facility_id, name)
SELECT DISTINCT facility_id, TRIM(category) FROM tbl_inventory_item WHERE TRIM(category) <> '';

-- Link items to category rows (match by legacy string)
UPDATE tbl_inventory_item i
INNER JOIN tbl_inventory_category c
  ON c.facility_id = i.facility_id AND c.name = TRIM(i.category)
SET i.category_id = c.id
WHERE i.category_id IS NULL;

-- Seed name catalog from existing product names
INSERT IGNORE INTO tbl_inventory_name_catalog (facility_id, name)
SELECT DISTINCT facility_id, TRIM(name) FROM tbl_inventory_item WHERE TRIM(name) <> '';
