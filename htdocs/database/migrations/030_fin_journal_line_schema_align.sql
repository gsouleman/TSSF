-- ---------------------------------------------------------------------------
-- HMS — Align tbl_fin_journal_line with 019 when a pre-existing table blocked
-- CREATE TABLE IF NOT EXISTS. Fixes receipt/GL sync: Unknown column 'account_code'.
-- Run after 019. Idempotent on MariaDB (ADD COLUMN IF NOT EXISTS).
-- ---------------------------------------------------------------------------

SET NAMES utf8mb4;

ALTER TABLE tbl_fin_journal_line ADD COLUMN IF NOT EXISTS account_code VARCHAR(32) NOT NULL DEFAULT '';
ALTER TABLE tbl_fin_journal_line ADD COLUMN IF NOT EXISTS account_label VARCHAR(160) NOT NULL DEFAULT '';
