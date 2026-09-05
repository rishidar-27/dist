-- ====================================================================
-- GOGANGS STUDIO — PRODUCTION DATABASE SETUP / UPDATE SCRIPT
-- Paste and execute this in cPanel phpMyAdmin -> SQL tab
-- Target Database: profilei_website (or your cPanel database name)
-- ====================================================================

-- STEP 1: Select Database (replace `profilei_website` if your DB name is different)
-- USE `profilei_website`;

SET FOREIGN_KEY_CHECKS = 0;

-- --------------------------------------------------------------------
-- 1. FREELANCERS (Creator Accounts & Portfolio Profiles)
-- --------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `freelancers` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `member_id` VARCHAR(50) DEFAULT NULL,
  `email` VARCHAR(255) NOT NULL,
  `username` VARCHAR(255) DEFAULT NULL,
  `name` VARCHAR(255) DEFAULT NULL,
  `phone` VARCHAR(255) DEFAULT NULL,
  `skills` TEXT DEFAULT NULL,
  `portfolio_data` LONGTEXT DEFAULT NULL,
  `has_completed_onboarding` TINYINT(1) DEFAULT 0,
  `user_code` VARCHAR(50) DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email_unique` (`email`),
  KEY `idx_username` (`username`),
  KEY `idx_member_id` (`member_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add any missing columns in case table was created previously
ALTER TABLE `freelancers` ADD COLUMN IF NOT EXISTS `member_id` VARCHAR(50) DEFAULT NULL;
ALTER TABLE `freelancers` ADD COLUMN IF NOT EXISTS `username` VARCHAR(255) DEFAULT NULL;
ALTER TABLE `freelancers` ADD COLUMN IF NOT EXISTS `name` VARCHAR(255) DEFAULT NULL;
ALTER TABLE `freelancers` ADD COLUMN IF NOT EXISTS `phone` VARCHAR(255) DEFAULT NULL;
ALTER TABLE `freelancers` ADD COLUMN IF NOT EXISTS `skills` TEXT DEFAULT NULL;
ALTER TABLE `freelancers` ADD COLUMN IF NOT EXISTS `portfolio_data` LONGTEXT DEFAULT NULL;
ALTER TABLE `freelancers` ADD COLUMN IF NOT EXISTS `has_completed_onboarding` TINYINT(1) DEFAULT 0;
ALTER TABLE `freelancers` ADD COLUMN IF NOT EXISTS `user_code` VARCHAR(50) DEFAULT NULL;

-- --------------------------------------------------------------------
-- 2. APP SETTINGS (Registration Gates & Global Quotas)
-- --------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `app_settings` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `setting_key` VARCHAR(100) NOT NULL,
  `setting_value` TEXT NOT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Initialize default settings
INSERT INTO `app_settings` (`setting_key`, `setting_value`)
VALUES 
  ('max_allowed_users', '50'),
  ('registration_open', '1')
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);

-- --------------------------------------------------------------------
-- 3. PORTFOLIO VIDEOS (Video Registry & Local Storage Fallback)
-- --------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `portfolio_videos` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `video_id` VARCHAR(100) NOT NULL,
  `video_data` LONGTEXT DEFAULT NULL,
  `file_type` VARCHAR(50) DEFAULT 'video/mp4',
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_video_id` (`video_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------
-- 4. PROJECTS (Client Projects & Deliverables)
-- --------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `projects` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `project_id` VARCHAR(50) NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `client_name` VARCHAR(255) DEFAULT NULL,
  `freelancer_name` VARCHAR(255) DEFAULT NULL,
  `service_type` VARCHAR(255) DEFAULT NULL,
  `budget` DECIMAL(10,2) DEFAULT NULL,
  `start_date` DATE DEFAULT NULL,
  `delivery_date` DATETIME DEFAULT NULL,
  `revision_limit` INT DEFAULT 3,
  `revision_count` INT DEFAULT 0,
  `drive_link` TEXT DEFAULT NULL,
  `status` VARCHAR(50) DEFAULT 'Active',
  `enabled` TINYINT(1) DEFAULT 1,
  `client_email` VARCHAR(255) DEFAULT NULL,
  `freelancer_email` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `project_id_unique` (`project_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------
-- 5. LIVE STATUS (Real-Time Timeline & Task Tracker)
-- --------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `live_status` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `project_id` VARCHAR(50) NOT NULL,
  `current_stage` VARCHAR(30) NOT NULL DEFAULT 'Project Received',
  `current_task` VARCHAR(30) DEFAULT NULL,
  `progress_percent` INT NOT NULL DEFAULT 0,
  `editor_name` VARCHAR(255) DEFAULT NULL,
  `last_activity_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `live_project_id_unique` (`project_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------
-- 6. CLIENTS & MESSAGING
-- --------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `clients` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `member_id` VARCHAR(50) DEFAULT NULL,
  `name` VARCHAR(255) NOT NULL,
  `email` VARCHAR(255) NOT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `client_email_idx` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `direct_messages` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `sender_email` VARCHAR(255) NOT NULL,
  `sender_role` VARCHAR(50) NOT NULL DEFAULT 'client',
  `sender_name` VARCHAR(255) NOT NULL,
  `recipient_email` VARCHAR(255) NOT NULL DEFAULT 'hello@gogangs.com',
  `project_id` VARCHAR(100) DEFAULT NULL,
  `message` TEXT NOT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------
-- 7. LOCK ADMIN ACCOUNT TO GGVE0001
-- --------------------------------------------------------------------
UPDATE `freelancers` 
SET `member_id` = 'GGVE0001', `user_code` = 'GGVE0001' 
WHERE LOWER(`email`) = 'rishidar27@gmail.com';

SET FOREIGN_KEY_CHECKS = 1;
