-- ---------------------------------------------------------------------------
-- HMS — Permission: approve consultation billing exception (no prior payment)
-- Run after 001 (tbl_acl_permission). Idempotent INSERT IGNORE.
-- ---------------------------------------------------------------------------

SET NAMES utf8mb4;

INSERT IGNORE INTO tbl_acl_permission (code, label, gap_area) VALUES
('consult.billing_override', 'Approve billing exception (consult / tests without prior cashier payment)', 1);

INSERT IGNORE INTO tbl_acl_role_permission (role, permission_id)
SELECT '1', id FROM tbl_acl_permission WHERE code = 'consult.billing_override';
