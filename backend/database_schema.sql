-- GoGangs Studio Production MySQL Database Schema for cPanel / phpMyAdmin
-- Database: studio (or your cPanel database name)

SET FOREIGN_KEY_CHECKS = 0;

-- 1. Table structure for table `freelancers`
CREATE TABLE IF NOT EXISTS `freelancers` (
  `id` int NOT NULL AUTO_INCREMENT,
  `member_id` varchar(50) DEFAULT NULL,
  `email` varchar(255) NOT NULL,
  `username` varchar(255) DEFAULT NULL,
  `name` varchar(255) DEFAULT NULL,
  `phone` varchar(255) DEFAULT NULL,
  `skills` text DEFAULT NULL,
  `portfolio_data` longtext DEFAULT NULL,
  `has_completed_onboarding` tinyint(1) DEFAULT '0',
  `user_code` varchar(50) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email_unique` (`email`),
  KEY `idx_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Table structure for table `projects`
CREATE TABLE IF NOT EXISTS `projects` (
  `id` int NOT NULL AUTO_INCREMENT,
  `project_id` varchar(50) NOT NULL,
  `name` varchar(255) NOT NULL,
  `client_name` varchar(255) DEFAULT NULL,
  `freelancer_name` varchar(255) DEFAULT NULL,
  `service_type` varchar(255) DEFAULT NULL,
  `budget` decimal(10,2) DEFAULT NULL,
  `start_date` date DEFAULT NULL,
  `delivery_date` datetime DEFAULT NULL,
  `revision_limit` int DEFAULT '3',
  `revision_count` int DEFAULT '0',
  `drive_link` text DEFAULT NULL,
  `status` varchar(50) DEFAULT 'Active',
  `enabled` tinyint(1) DEFAULT '1',
  `client_email` varchar(255) DEFAULT NULL,
  `freelancer_email` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `project_id_unique` (`project_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Table structure for table `live_status`
CREATE TABLE IF NOT EXISTS `live_status` (
  `id` int NOT NULL AUTO_INCREMENT,
  `project_id` varchar(50) NOT NULL,
  `current_stage` varchar(30) NOT NULL DEFAULT 'Project Received',
  `current_task` varchar(30) DEFAULT NULL,
  `progress_percent` int NOT NULL DEFAULT '0',
  `editor_name` varchar(255) DEFAULT NULL,
  `last_activity_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `live_project_id_unique` (`project_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Table structure for table `clients`
CREATE TABLE IF NOT EXISTS `clients` (
  `id` int NOT NULL AUTO_INCREMENT,
  `member_id` varchar(50) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `client_email_idx` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Table structure for table `direct_messages`
CREATE TABLE IF NOT EXISTS `direct_messages` (
  `id` int NOT NULL AUTO_INCREMENT,
  `sender_email` varchar(255) NOT NULL,
  `sender_role` varchar(50) NOT NULL,
  `sender_name` varchar(255) NOT NULL,
  `recipient_email` varchar(255) NOT NULL,
  `project_id` varchar(100) DEFAULT NULL,
  `message` text NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Table structure for table `project_files`
CREATE TABLE IF NOT EXISTS `project_files` (
  `id` int NOT NULL AUTO_INCREMENT,
  `project_id` varchar(50) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_url` text NOT NULL,
  `file_size_mb` decimal(10,2) DEFAULT '0.00',
  `file_type` varchar(50) DEFAULT 'deliverable',
  `uploaded_by` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `project_files_project_idx` (`project_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
