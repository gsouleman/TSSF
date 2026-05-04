-- ---------------------------------------------------------------------------
-- HMS — Optional journal line shape: account_id → tbl_fin_account blocks 019-style
-- OHADA posting (account_code only). The app runs hms_fin_journal_line_account_id_ensure()
-- to DROP fk_fin_jl_account (name may differ) and make account_id nullable.
-- Manual repair example (verify constraint name with SHOW CREATE TABLE tbl_fin_journal_line;):
-- ---------------------------------------------------------------------------
--
-- ALTER TABLE tbl_fin_journal_line DROP FOREIGN KEY fk_fin_jl_account;
-- ALTER TABLE tbl_fin_journal_line MODIFY COLUMN account_id INT UNSIGNED NULL DEFAULT NULL;
--
-- ---------------------------------------------------------------------------

SET NAMES utf8mb4;
