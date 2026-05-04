-- =============================================================================
-- Migration 013: Remove encounters, clinical orders, and order results
-- Run once on deployed databases after backing up data you may still need.
-- Drops: tbl_order_result, tbl_clinical_order, tbl_encounter; removes
-- encounter_id from related tables; drops prescription_line.clinical_order_id.
-- Uses information_schema so re-run is mostly harmless (skips missing objects).
-- =============================================================================

SET NAMES utf8mb4;

SET @db = DATABASE();

-- ---------------------------------------------------------------------------
-- 1) Prescription line → clinical order link
-- ---------------------------------------------------------------------------
SET @x = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
          WHERE CONSTRAINT_SCHEMA = @db AND TABLE_NAME = 'tbl_prescription_line' AND CONSTRAINT_NAME = 'fk_rxl_ord');
SET @sql = IF(@x > 0, 'ALTER TABLE tbl_prescription_line DROP FOREIGN KEY fk_rxl_ord', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @x = (SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'tbl_prescription_line' AND COLUMN_NAME = 'clinical_order_id');
SET @sql = IF(@x > 0, 'ALTER TABLE tbl_prescription_line DROP COLUMN clinical_order_id', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------------
-- 2) Order results and clinical orders (child of encounter in older model)
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS tbl_order_result;
DROP TABLE IF EXISTS tbl_clinical_order;

-- ---------------------------------------------------------------------------
-- 3) Drop encounter_id FK + column on tables that referenced tbl_encounter
-- ---------------------------------------------------------------------------
-- tbl_consultation
SET @x = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
          WHERE CONSTRAINT_SCHEMA = @db AND TABLE_NAME = 'tbl_consultation' AND CONSTRAINT_NAME = 'fk_cons_enc');
SET @sql = IF(@x > 0, 'ALTER TABLE tbl_consultation DROP FOREIGN KEY fk_cons_enc', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @x = (SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'tbl_consultation' AND COLUMN_NAME = 'encounter_id');
SET @sql = IF(@x > 0, 'ALTER TABLE tbl_consultation DROP COLUMN encounter_id', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- tbl_prescription
SET @x = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
          WHERE CONSTRAINT_SCHEMA = @db AND TABLE_NAME = 'tbl_prescription' AND CONSTRAINT_NAME = 'fk_rx_enc');
SET @sql = IF(@x > 0, 'ALTER TABLE tbl_prescription DROP FOREIGN KEY fk_rx_enc', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @x = (SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'tbl_prescription' AND COLUMN_NAME = 'encounter_id');
SET @sql = IF(@x > 0, 'ALTER TABLE tbl_prescription DROP COLUMN encounter_id', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- tbl_opd_visit (004_opd_queue_admission.sql)
SET @x = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
          WHERE CONSTRAINT_SCHEMA = @db AND TABLE_NAME = 'tbl_opd_visit' AND CONSTRAINT_NAME = 'fk_opd_enc');
SET @sql = IF(@x > 0, 'ALTER TABLE tbl_opd_visit DROP FOREIGN KEY fk_opd_enc', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @x = (SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'tbl_opd_visit' AND COLUMN_NAME = 'encounter_id');
SET @sql = IF(@x > 0, 'ALTER TABLE tbl_opd_visit DROP COLUMN encounter_id', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- tbl_problem
SET @x = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
          WHERE CONSTRAINT_SCHEMA = @db AND TABLE_NAME = 'tbl_problem' AND CONSTRAINT_NAME = 'fk_prob_enc');
SET @sql = IF(@x > 0, 'ALTER TABLE tbl_problem DROP FOREIGN KEY fk_prob_enc', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @x = (SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'tbl_problem' AND COLUMN_NAME = 'encounter_id');
SET @sql = IF(@x > 0, 'ALTER TABLE tbl_problem DROP COLUMN encounter_id', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- tbl_vital_sign
SET @x = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
          WHERE CONSTRAINT_SCHEMA = @db AND TABLE_NAME = 'tbl_vital_sign' AND CONSTRAINT_NAME = 'fk_vs_enc');
SET @sql = IF(@x > 0, 'ALTER TABLE tbl_vital_sign DROP FOREIGN KEY fk_vs_enc', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @x = (SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'tbl_vital_sign' AND COLUMN_NAME = 'encounter_id');
SET @sql = IF(@x > 0, 'ALTER TABLE tbl_vital_sign DROP COLUMN encounter_id', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- tbl_admission
SET @x = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
          WHERE CONSTRAINT_SCHEMA = @db AND TABLE_NAME = 'tbl_admission' AND CONSTRAINT_NAME = 'fk_adm_enc');
SET @sql = IF(@x > 0, 'ALTER TABLE tbl_admission DROP FOREIGN KEY fk_adm_enc', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @x = (SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'tbl_admission' AND COLUMN_NAME = 'encounter_id');
SET @sql = IF(@x > 0, 'ALTER TABLE tbl_admission DROP COLUMN encounter_id', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- tbl_charge
SET @x = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
          WHERE CONSTRAINT_SCHEMA = @db AND TABLE_NAME = 'tbl_charge' AND CONSTRAINT_NAME = 'fk_ch_enc');
SET @sql = IF(@x > 0, 'ALTER TABLE tbl_charge DROP FOREIGN KEY fk_ch_enc', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @x = (SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'tbl_charge' AND COLUMN_NAME = 'encounter_id');
SET @sql = IF(@x > 0, 'ALTER TABLE tbl_charge DROP COLUMN encounter_id', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------------
-- 4) Encounter master table
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS tbl_encounter;
