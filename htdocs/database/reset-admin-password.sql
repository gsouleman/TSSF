-- Reset admin password (plaintext; app upgrades to bcrypt on next login).
-- Run in phpMyAdmin or: mysql -u USER -p DB_NAME < reset-admin-password.sql

UPDATE tbl_employee
SET password = 'Hellt0cell'
WHERE username = 'admin' AND (role = '1' OR role = 1)
LIMIT 1;
