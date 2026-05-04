-- 010_transactions_table.sql
-- Transactions table for the Appointments > Transactions module.

CREATE TABLE IF NOT EXISTS `tbl_transaction` (
    `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `facility_id`       INT NOT NULL DEFAULT 1,
    `patient_id`        INT NOT NULL,
    `description`       VARCHAR(500) NOT NULL DEFAULT '',
    `amount`            DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `payment_method`    VARCHAR(50)  NOT NULL DEFAULT 'Cash',
    `status`            VARCHAR(20)  NOT NULL DEFAULT 'pending',
    `transaction_date`  DATE         NOT NULL,
    `charge_id`         INT UNSIGNED DEFAULT NULL,
    `appointment_id`    INT          DEFAULT NULL,
    `created_by`        INT          NOT NULL DEFAULT 0,
    `created_at`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        DATETIME     DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_txn_facility`   (`facility_id`),
    KEY `idx_txn_patient`    (`patient_id`),
    KEY `idx_txn_date`       (`transaction_date`),
    KEY `idx_txn_status`     (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
