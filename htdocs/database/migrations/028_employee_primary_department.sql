-- HMS — Primary clinical department on tbl_employee (matches tbl_department.department_name)
--
-- Used for: doctor visit/appt picklists by department, staff “Department” on employee forms.
--
-- If phpMyAdmin returns:  #1060 - Duplicate column name 'primary_department'
-- → The column is already there. Do not run this file again; your database is up to date.
--
-- Plain ALTER only (shared hosts friendly).

SET NAMES utf8mb4;

ALTER TABLE tbl_employee
    ADD COLUMN primary_department VARCHAR(120) NULL DEFAULT NULL
    COMMENT 'tbl_department.department_name for role=2; visit/appt doctor picklists';
