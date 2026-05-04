-- ---------------------------------------------------------------------------
-- HMS — OHADA reporting helpers, Cameroon tax settings, saved declarations.
-- Run after 019 (tbl_fin_journal_*). Safe to re-run (IF NOT EXISTS / IGNORE).
-- ---------------------------------------------------------------------------

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS tbl_fin_tax_setting (
  facility_id INT NOT NULL,
  setting_key VARCHAR(64) NOT NULL,
  setting_value VARCHAR(512) NOT NULL DEFAULT '',
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (facility_id, setting_key),
  CONSTRAINT fk_fin_tax_set_fac FOREIGN KEY (facility_id) REFERENCES tbl_facility (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS tbl_fin_tax_declaration (
  id INT NOT NULL AUTO_INCREMENT,
  facility_id INT NOT NULL,
  period_year SMALLINT NOT NULL,
  period_month TINYINT NOT NULL DEFAULT 0 COMMENT '1-12 monthly; 0 = annual slot',
  declaration_kind VARCHAR(40) NOT NULL DEFAULT 'TVA_MONTHLY' COMMENT 'TVA_MONTHLY|TVA_ANNUAL|IS_ESTIMATE|WITHHOLDING_SUMMARY',
  title VARCHAR(200) NOT NULL DEFAULT '',
  payload_json JSON NULL COMMENT 'Structured figures & line items for the declaration',
  notes TEXT NULL,
  created_by INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_fin_tax_decl_fac (facility_id, period_year, period_month),
  CONSTRAINT fk_fin_tax_decl_fac FOREIGN KEY (facility_id) REFERENCES tbl_facility (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO tbl_acl_permission (code, label, gap_area) VALUES
('financials.read', 'View financials, OHADA reports & tax statements', 5),
('financials.write', 'Post manual journals & save tax working files', 5);

INSERT IGNORE INTO tbl_acl_role_permission (role, permission_id)
SELECT '1', id FROM tbl_acl_permission WHERE code IN ('financials.read', 'financials.write');

INSERT IGNORE INTO tbl_acl_role_permission (role, permission_id)
SELECT '4', id FROM tbl_acl_permission WHERE code IN ('financials.read', 'financials.write');
