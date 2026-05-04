-- HMS — Cameroon payroll tax helpers: settings, monthly payroll lines, DIPE export history.
-- Run after 001 (tbl_facility, tbl_employee).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS tbl_hms_payroll_settings (
  id INT NOT NULL AUTO_INCREMENT,
  facility_id INT NOT NULL,
  tax_year SMALLINT NOT NULL,
  employer_cnps_number VARCHAR(32) NOT NULL DEFAULT '',
  employer_niu VARCHAR(32) NOT NULL DEFAULT '',
  cnps_regime TINYINT NOT NULL DEFAULT 1 COMMENT '1=general,2=agriculture,3=public',
  employer_address VARCHAR(500) NOT NULL DEFAULT '',
  employer_phone VARCHAR(64) NOT NULL DEFAULT '',
  employer_email VARCHAR(128) NOT NULL DEFAULT '',
  cnps_employee_rate DECIMAL(8,3) NOT NULL DEFAULT 2.800,
  cimr_employee_rate DECIMAL(8,3) NOT NULL DEFAULT 2.400,
  crtv_rate DECIMAL(8,3) NOT NULL DEFAULT 0.200,
  council_tax_rate DECIMAL(8,3) NOT NULL DEFAULT 0.800,
  development_tax_rate DECIMAL(8,3) NOT NULL DEFAULT 0.500,
  cnhc_rate DECIMAL(8,3) NOT NULL DEFAULT 0.500,
  tax_brackets TEXT NULL COMMENT 'JSON array of IRPP bracket rates',
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_hms_payroll_settings_fac_year (facility_id, tax_year),
  CONSTRAINT fk_hms_payroll_settings_fac FOREIGN KEY (facility_id) REFERENCES tbl_facility (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS tbl_hms_payroll_record (
  id INT NOT NULL AUTO_INCREMENT,
  facility_id INT NOT NULL,
  employee_id INT NOT NULL,
  year SMALLINT NOT NULL,
  month TINYINT NOT NULL COMMENT '1-12',
  gross_salary DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  cnps_employee DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  cimr_employee DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  crtv_deduction DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  council_tax_deduction DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  development_tax_deduction DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  cnhc_deduction DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  income_tax DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  net_salary DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_hms_payroll_rec_fac_emp_ym (facility_id, employee_id, year, month),
  KEY idx_hms_payroll_rec_fac_ym (facility_id, year, month),
  CONSTRAINT fk_hms_payroll_rec_fac FOREIGN KEY (facility_id) REFERENCES tbl_facility (id) ON DELETE CASCADE,
  CONSTRAINT fk_hms_payroll_rec_emp FOREIGN KEY (employee_id) REFERENCES tbl_employee (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS tbl_hms_dipe_history (
  id INT NOT NULL AUTO_INCREMENT,
  facility_id INT NOT NULL,
  month TINYINT NOT NULL,
  year SMALLINT NOT NULL,
  filename VARCHAR(255) NOT NULL,
  file_path VARCHAR(512) NOT NULL DEFAULT '' COMMENT 'Relative to HMS web root',
  generated_by INT NOT NULL DEFAULT 0 COMMENT 'tbl_employee.id',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_hms_dipe_fac_ym (facility_id, year, month),
  CONSTRAINT fk_hms_dipe_hist_fac FOREIGN KEY (facility_id) REFERENCES tbl_facility (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
