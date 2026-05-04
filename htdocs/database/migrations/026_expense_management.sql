-- ---------------------------------------------------------------------------
-- HMS — Operational expenses (facility-level)
-- Run after 001_multi_site_platform.sql (tbl_facility, tbl_acl_*).
-- ---------------------------------------------------------------------------

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS tbl_expense (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  facility_id INT NOT NULL,
  expense_date DATE NOT NULL,
  category VARCHAR(120) NOT NULL DEFAULT '',
  description VARCHAR(512) NOT NULL DEFAULT '',
  amount_xaf INT UNSIGNED NOT NULL DEFAULT 0,
  payment_method VARCHAR(64) NULL COMMENT 'cash, bank, mobile_money, etc.',
  reference VARCHAR(120) NULL,
  vendor VARCHAR(200) NULL,
  notes TEXT NULL,
  created_by INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_exp_fac_date (facility_id, expense_date),
  CONSTRAINT fk_exp_fac FOREIGN KEY (facility_id) REFERENCES tbl_facility (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO tbl_acl_permission (code, label, gap_area) VALUES
('expenses.read', 'View expense register', 5),
('expenses.write', 'Record and edit expenses', 5);

INSERT IGNORE INTO tbl_acl_role_permission (role, permission_id)
SELECT '1', id FROM tbl_acl_permission WHERE code IN ('expenses.read', 'expenses.write');

INSERT IGNORE INTO tbl_acl_role_permission (role, permission_id)
SELECT '4', id FROM tbl_acl_permission WHERE code IN ('expenses.read', 'expenses.write');
