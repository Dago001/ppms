-- NIS-PPMS Disciplinary & Promotion Schema Migration
ALTER TABLE `tbl_employment` 
ADD COLUMN IF NOT EXISTS `disciplinary_status` VARCHAR(50) NOT NULL DEFAULT 'Clean',
ADD COLUMN IF NOT EXISTS `disciplinary_remarks` TEXT NULL,
ADD COLUMN IF NOT EXISTS `promotion_eligibility` VARCHAR(50) NOT NULL DEFAULT 'Eligible',
ADD COLUMN IF NOT EXISTS `promotion_remarks` VARCHAR(255) NULL;
