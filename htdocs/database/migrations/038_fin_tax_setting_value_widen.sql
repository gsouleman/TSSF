-- ---------------------------------------------------------------------------
-- HMS — Widen tbl_fin_tax_setting.setting_value for longer Cameroon tax notes.
-- Run after 029_ohada_reporting_tax.sql. Re-running is harmless.
-- ---------------------------------------------------------------------------

SET NAMES utf8mb4;

ALTER TABLE tbl_fin_tax_setting
  MODIFY COLUMN setting_value VARCHAR(4000) NOT NULL DEFAULT '';
