-- ============================================================
-- GL Trial Balance Fix — run this in phpMyAdmin SQL tab
-- Solidarity of Hearts Hospital
-- ============================================================

-- STEP 1: Ensure tbl_facility has a row for id=1
INSERT INTO tbl_facility (id, code, name, status)
VALUES (1, 'MAIN', 'TSSF Solidarity of Hearts Hospital SOA', 1)
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 1;

-- STEP 2: Create GL journal tables if they don't exist
CREATE TABLE IF NOT EXISTS tbl_fin_journal_header (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    facility_id INT NOT NULL DEFAULT 1,
    entry_date  DATE NOT NULL,
    reference   VARCHAR(64)  NOT NULL DEFAULT '',
    narration   VARCHAR(512) NOT NULL DEFAULT '',
    source_type VARCHAR(64)  NOT NULL DEFAULT '',
    source_id   INT NOT NULL DEFAULT 0,
    created_by  INT NOT NULL DEFAULT 0,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_fid_date (facility_id, entry_date),
    UNIQUE KEY uq_fin_jrnl_src (facility_id, source_type, source_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tbl_fin_journal_line (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    journal_id    INT UNSIGNED NOT NULL,
    account_code  VARCHAR(32)  NOT NULL DEFAULT '',
    account_label VARCHAR(160) NOT NULL DEFAULT '',
    debit         DECIMAL(18,2) NOT NULL DEFAULT 0,
    credit        DECIMAL(18,2) NOT NULL DEFAULT 0,
    INDEX idx_jid  (journal_id),
    INDEX idx_code (account_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- STEP 3: Add missing columns to journal line if needed
ALTER TABLE tbl_fin_journal_line
    ADD COLUMN IF NOT EXISTS account_code VARCHAR(32) NOT NULL DEFAULT '' AFTER journal_id;

ALTER TABLE tbl_fin_journal_line
    ADD COLUMN IF NOT EXISTS account_label VARCHAR(160) NOT NULL DEFAULT '' AFTER account_code;

-- STEP 4: Seed GL from tbl_transaction (patient cash/revenue entries)
-- This inserts one balanced journal per transaction (DR cash · CR revenue).
-- Duplicates are skipped by the UNIQUE key on (facility_id, source_type, source_id).
INSERT IGNORE INTO tbl_fin_journal_header (facility_id, entry_date, reference, narration, source_type, source_id, created_by)
SELECT
    1,
    COALESCE(DATE(t.created_at), CURDATE()),
    CONCAT('TXN-', t.id),
    CONCAT('Patient transaction #', t.id),
    'transaction',
    t.id,
    0
FROM tbl_transaction t
WHERE t.amount > 0;

-- Lines: DR 571000 (Cash) · CR 706000 (Revenue) for every seeded header
INSERT IGNORE INTO tbl_fin_journal_line (journal_id, account_code, account_label, debit, credit)
SELECT
    h.id,
    '571000',
    'Cash — patient collection',
    ROUND(t.amount, 2),
    0
FROM tbl_fin_journal_header h
INNER JOIN tbl_transaction t
    ON t.id = h.source_id
    AND h.source_type = 'transaction'
WHERE h.facility_id = 1
  AND NOT EXISTS (
      SELECT 1 FROM tbl_fin_journal_line jl WHERE jl.journal_id = h.id AND jl.account_code = '571000'
  );

INSERT IGNORE INTO tbl_fin_journal_line (journal_id, account_code, account_label, debit, credit)
SELECT
    h.id,
    '706000',
    'Healthcare services revenue',
    0,
    ROUND(t.amount, 2)
FROM tbl_fin_journal_header h
INNER JOIN tbl_transaction t
    ON t.id = h.source_id
    AND h.source_type = 'transaction'
WHERE h.facility_id = 1
  AND NOT EXISTS (
      SELECT 1 FROM tbl_fin_journal_line jl WHERE jl.journal_id = h.id AND jl.account_code = '706000'
  );

-- STEP 5: Seed GL from tbl_expense (expense entries)
INSERT IGNORE INTO tbl_fin_journal_header (facility_id, entry_date, reference, narration, source_type, source_id, created_by)
SELECT
    1,
    COALESCE(e.expense_date, DATE(e.created_at), CURDATE()),
    CONCAT('EXP-', e.id),
    CONCAT('Expense: ', COALESCE(e.category, ''), ' — ', COALESCE(LEFT(e.description, 100), '')),
    'expense',
    e.id,
    0
FROM tbl_expense e
WHERE e.amount_xaf > 0
  AND e.facility_id = 1;

-- Lines: DR 601000 (OpEx) · CR 571000 (Cash)
INSERT IGNORE INTO tbl_fin_journal_line (journal_id, account_code, account_label, debit, credit)
SELECT
    h.id,
    '601000',
    CONCAT('Operating expenses — ', COALESCE(e.category, 'General')),
    ROUND(e.amount_xaf, 2),
    0
FROM tbl_fin_journal_header h
INNER JOIN tbl_expense e
    ON e.id = h.source_id
    AND h.source_type = 'expense'
WHERE h.facility_id = 1
  AND NOT EXISTS (
      SELECT 1 FROM tbl_fin_journal_line jl WHERE jl.journal_id = h.id AND jl.account_code = '601000'
  );

INSERT IGNORE INTO tbl_fin_journal_line (journal_id, account_code, account_label, debit, credit)
SELECT
    h.id,
    '571000',
    'Cash — operating payments',
    0,
    ROUND(e.amount_xaf, 2)
FROM tbl_fin_journal_header h
INNER JOIN tbl_expense e
    ON e.id = h.source_id
    AND h.source_type = 'expense'
WHERE h.facility_id = 1
  AND NOT EXISTS (
      SELECT 1 FROM tbl_fin_journal_line jl2 WHERE jl2.journal_id = h.id AND jl2.account_code = '601000' AND jl2.debit = 0
  );

-- STEP 6: Verification counts
SELECT 'tbl_facility rows' AS tbl, COUNT(*) AS cnt FROM tbl_facility
UNION ALL
SELECT 'tbl_fin_journal_header (site #1)',  COUNT(*) FROM tbl_fin_journal_header WHERE facility_id = 1
UNION ALL
SELECT 'tbl_fin_journal_line (site #1)', COUNT(*) FROM tbl_fin_journal_line jl INNER JOIN tbl_fin_journal_header h ON h.id = jl.journal_id WHERE h.facility_id = 1
UNION ALL
SELECT 'tbl_transaction', COUNT(*) FROM tbl_transaction
UNION ALL
SELECT 'tbl_expense (site #1)', COUNT(*) FROM tbl_expense WHERE facility_id = 1;
