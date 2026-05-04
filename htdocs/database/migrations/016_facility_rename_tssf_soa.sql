-- Rename default facility display name (was "Main Campus" in older seeds).
-- Safe to run multiple times.

SET NAMES utf8mb4;

UPDATE tbl_facility
SET name = 'TSSF Solidarity of Hearts Hospital SOA'
WHERE id = 1 AND code = 'MAIN';
