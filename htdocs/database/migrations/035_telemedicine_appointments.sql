ALTER TABLE `tbl_appointment`
ADD COLUMN `is_telemedicine` tinyint(1) NOT NULL DEFAULT 0,
ADD COLUMN `meeting_link` varchar(500) DEFAULT NULL;
