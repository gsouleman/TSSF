-- ---------------------------------------------------------------------------
-- HMS — Repair tbl_fin_journal_line when journal_id referenced tbl_fin_journal
-- instead of tbl_fin_journal_header (wrong DDL / legacy install).
-- The application calls hms_fin_journal_line_fk_ensure() automatically when posting.
-- To fix manually, get the constraint name from: SHOW CREATE TABLE tbl_fin_journal_line;
-- then uncomment and run (replace fk_fin_jl_journal if your name differs):
-- ---------------------------------------------------------------------------
--
-- ALTER TABLE tbl_fin_journal_line DROP FOREIGN KEY fk_fin_jl_journal;
-- ALTER TABLE tbl_fin_journal_line ADD CONSTRAINT fk_fin_jl_j FOREIGN KEY (journal_id)
--   REFERENCES tbl_fin_journal_header (id) ON DELETE CASCADE;
--
-- ---------------------------------------------------------------------------

SET NAMES utf8mb4;
