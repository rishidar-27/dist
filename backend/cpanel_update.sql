-- ====================================================================
-- GOGANGS STUDIO — PRODUCTION DATABASE UPDATE SCRIPT
-- Paste and run this script in cPanel phpMyAdmin -> SQL tab
-- Target Database: profilei_website
-- ====================================================================

-- 1. FREELANCERS TABLE (Creator Accounts & Profiles)
CREATE TABLE IF NOT EXISTS `freelancers` (
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

-- 2. ADD MISSING COLUMNS SAFELY (In case table already exists)
ALTER TABLE `freelancers` ADD COLUMN IF NOT EXISTS `member_id` VARCHAR(50) DEFAULT NULL;
ALTER TABLE `freelancers` ADD COLUMN IF NOT EXISTS `username` VARCHAR(255) DEFAULT NULL;
ALTER TABLE `freelancers` ADD COLUMN IF NOT EXISTS `name` VARCHAR(255) DEFAULT NULL;
ALTER TABLE `freelancers` ADD COLUMN IF NOT EXISTS `phone` VARCHAR(255) DEFAULT NULL;
ALTER TABLE `freelancers` ADD COLUMN IF NOT EXISTS `skills` TEXT DEFAULT NULL;
ALTER TABLE `freelancers` ADD COLUMN IF NOT EXISTS `location` VARCHAR(255) DEFAULT NULL;
ALTER TABLE `freelancers` ADD COLUMN IF NOT EXISTS `primary_language` VARCHAR(100) DEFAULT NULL;
ALTER TABLE `freelancers` ADD COLUMN IF NOT EXISTS `portfolio_data` LONGTEXT DEFAULT NULL;
ALTER TABLE `freelancers` ADD COLUMN IF NOT EXISTS `has_completed_onboarding` TINYINT(1) DEFAULT 0;
ALTER TABLE `freelancers` ADD COLUMN IF NOT EXISTS `user_code` VARCHAR(50) DEFAULT NULL;
ALTER TABLE `freelancers` ADD COLUMN IF NOT EXISTS `approval_status` VARCHAR(50) DEFAULT 'pending';
ALTER TABLE `freelancers` ADD COLUMN IF NOT EXISTS `approved_at` DATETIME DEFAULT NULL;

-- 3. DELETED ACCOUNTS TOMBSTONE TABLE (Real-Time Admin Account Deletion)
CREATE TABLE IF NOT EXISTS `deleted_accounts` (
  `email` VARCHAR(255) NOT NULL,
  `member_id` VARCHAR(50) DEFAULT NULL,
  `username` VARCHAR(255) DEFAULT NULL,
  `deleted_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`email`),
  KEY `idx_del_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. APP SETTINGS TABLE (Platform Gates & Global Quotas)
CREATE TABLE IF NOT EXISTS `app_settings` (
  `setting_key` VARCHAR(100) NOT NULL,
  `setting_value` TEXT NOT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `app_settings` (`setting_key`, `setting_value`) VALUES 
  ('max_allowed_users', '50'),
  ('registration_open', '1');

-- 5. CLIENT BUCKETS TABLE (Marketing Curated Bucket Showcase & Short URLs)
CREATE TABLE IF NOT EXISTS `client_buckets` (
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

-- 6. PORTFOLIO VIDEOS TABLE (MySQL Raw Video Storage Backup)
CREATE TABLE IF NOT EXISTS `portfolio_videos` (
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

-- 7. DATA INTEGRITY SAFEGUARDS:
-- Ensure Admin account is always approved
UPDATE `freelancers` 
SET `approval_status` = 'approved', `member_id` = 'GGVE0001' 
WHERE LOWER(`email`) = 'rishidar27@gmail.com';

-- Ensure creators who already have names/data keep has_completed_onboarding = 1
UPDATE `freelancers`
SET `has_completed_onboarding` = 1
WHERE (`name` IS NOT NULL AND `name` != '' AND LOWER(`name`) != 'freelancer')
   OR `portfolio_data` LIKE '%"videos":[%'
   OR `has_completed_onboarding` = 1;
