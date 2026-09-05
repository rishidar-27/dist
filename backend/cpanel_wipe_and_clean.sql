-- ====================================================================
-- GOGANGS STUDIO — COMPLETE DATABASE CLEANUP & FRESH RESET SCRIPT
-- Target Database: profilei_website
-- ====================================================================

USE `profilei_website`;

SET FOREIGN_KEY_CHECKS = 0;

-- --------------------------------------------------------------------
-- STEP 1: DROP ALL 15 UNWANTED / LEGACY TABLES FROM OTHER APPS
-- --------------------------------------------------------------------
DROP TABLE IF EXISTS `clients`;
DROP TABLE IF EXISTS `deliverables`;
DROP TABLE IF EXISTS `direct_messages`;
DROP TABLE IF EXISTS `download_permissions`;
DROP TABLE IF EXISTS `emails`;
DROP TABLE IF EXISTS `email_templates`;
DROP TABLE IF EXISTS `live_status`;
DROP TABLE IF EXISTS `outreachers`;
DROP TABLE IF EXISTS `projects`;
DROP TABLE IF EXISTS `project_files`;
DROP TABLE IF EXISTS `project_messages`;
DROP TABLE IF EXISTS `project_revisions`;
DROP TABLE IF EXISTS `project_updates`;
DROP TABLE IF EXISTS `revision_replies`;
DROP TABLE IF EXISTS `sent_emails`;

-- --------------------------------------------------------------------
-- STEP 2: DROP & RECREATE THE 5 OFFICIAL GOGANGS STUDIO TABLES FRESH
-- --------------------------------------------------------------------

-- Table 1: FREELANCERS (Creator Accounts & Portfolios)
DROP TABLE IF EXISTS `freelancers`;
CREATE TABLE `freelancers` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `member_id` VARCHAR(50) DEFAULT NULL,
  `email` VARCHAR(255) NOT NULL,
  `username` VARCHAR(255) DEFAULT NULL,
  `name` VARCHAR(255) DEFAULT NULL,
  `phone` VARCHAR(255) DEFAULT NULL,
  `skills` TEXT DEFAULT NULL,
  `location` VARCHAR(255) DEFAULT NULL,
  `primary_language` VARCHAR(100) DEFAULT NULL,
  `portfolio_data` LONGTEXT DEFAULT NULL,
  `has_completed_onboarding` TINYINT(1) DEFAULT 0,
  `user_code` VARCHAR(50) DEFAULT NULL,
  `approval_status` VARCHAR(50) DEFAULT 'pending',
  `approved_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email_unique` (`email`),
  KEY `idx_username` (`username`),
  KEY `idx_member_id` (`member_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table 2: PORTFOLIO_VIDEOS (Video Metadata & Streaming Storage)
DROP TABLE IF EXISTS `portfolio_videos`;
CREATE TABLE `portfolio_videos` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `video_id` VARCHAR(100) NOT NULL,
  `email` VARCHAR(255) NOT NULL,
  `filename` VARCHAR(255) NOT NULL,
  `file_type` VARCHAR(100) DEFAULT 'video/mp4',
  `video_data` LONGTEXT NOT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `video_id_unique` (`video_id`),
  KEY `idx_video_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table 3: DELETED_ACCOUNTS (Real-time Instant Account Wipe Tracking)
DROP TABLE IF EXISTS `deleted_accounts`;
CREATE TABLE `deleted_accounts` (
  `email` VARCHAR(255) NOT NULL,
  `member_id` VARCHAR(50) DEFAULT NULL,
  `username` VARCHAR(255) DEFAULT NULL,
  `deleted_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`email`),
  KEY `idx_del_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table 4: CLIENT_BUCKETS (Curated Video Showcase Buckets & Short URLs)
DROP TABLE IF EXISTS `client_buckets`;
CREATE TABLE `client_buckets` (
  `id` VARCHAR(100) NOT NULL,
  `slug` VARCHAR(100) NOT NULL,
  `client_name` VARCHAR(255) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `video_ids` LONGTEXT NOT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table 5: APP_SETTINGS (System Limits & Registration Toggles)
DROP TABLE IF EXISTS `app_settings`;
CREATE TABLE `app_settings` (
  `setting_key` VARCHAR(100) NOT NULL,
  `setting_value` TEXT NOT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------
-- STEP 3: SEED INITIAL CLEAN SYSTEM DATA
-- --------------------------------------------------------------------

-- Default platform settings
INSERT INTO `app_settings` (`setting_key`, `setting_value`) VALUES 
  ('max_allowed_users', '50'),
  ('registration_open', '1');

-- Pre-seed Admin Account (rishidar27@gmail.com) as GGVE0001
INSERT INTO `freelancers` (
  `member_id`,
  `email`,
  `username`,
  `name`,
  `portfolio_data`,
  `has_completed_onboarding`,
  `user_code`,
  `approval_status`,
  `approved_at`
) VALUES (
  'GGVE0001',
  'rishidar27@gmail.com',
  'rishidar',
  'Hari Rishidar',
  '{\"fullName\":\"Hari Rishidar\",\"email\":\"rishidar27@gmail.com\",\"username\":\"rishidar\",\"title\":\"Founder & Creative Director\",\"userCode\":\"GGVE0001\",\"approvalStatus\":\"approved\",\"hasCompletedOnboarding\":true,\"videos\":[]}',
  1,
  'GGVE0001',
  'approved',
  NOW()
);

SET FOREIGN_KEY_CHECKS = 1;
