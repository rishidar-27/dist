-- ====================================================================
-- GoGangs Studio Database Cleanup & Table Deletion Script
-- Paste and run this in MySQL Workbench or cPanel phpMyAdmin
-- ====================================================================

-- STEP 1: SELECT YOUR DATABASE (Change `website` to your database name if different)
USE `website`;

SET FOREIGN_KEY_CHECKS = 0;

-- STEP 2: DROP ALL 30 UNUSED / UNWANTED LEGACY TABLES
DROP TABLE IF EXISTS `clones`;
DROP TABLE IF EXISTS `contracts`;
DROP TABLE IF EXISTS `deliverables`;
DROP TABLE IF EXISTS `download_permissions`;
DROP TABLE IF EXISTS `entity_notes`;
DROP TABLE IF EXISTS `friends`;
DROP TABLE IF EXISTS `gangs`;
DROP TABLE IF EXISTS `invoices`;
DROP TABLE IF EXISTS `leads`;
DROP TABLE IF EXISTS `member_reviews`;
DROP TABLE IF EXISTS `messages`;
DROP TABLE IF EXISTS `numbers`;
DROP TABLE IF EXISTS `orders`;
DROP TABLE IF EXISTS `outreachers`;
DROP TABLE IF EXISTS `partners`;
DROP TABLE IF EXISTS `portfolios`;
DROP TABLE IF EXISTS `project_logs`;
DROP TABLE IF EXISTS `project_messages`;
DROP TABLE IF EXISTS `project_revisions`;
DROP TABLE IF EXISTS `project_support_tickets`;
DROP TABLE IF EXISTS `project_update_comments`;
DROP TABLE IF EXISTS `project_updates`;
DROP TABLE IF EXISTS `projects_history`;
DROP TABLE IF EXISTS `revision_replies`;
DROP TABLE IF EXISTS `sent_emails`;
DROP TABLE IF EXISTS `service_requests`;
DROP TABLE IF EXISTS `services`;
DROP TABLE IF EXISTS `squad_members`;
DROP TABLE IF EXISTS `squad_reviews`;
DROP TABLE IF EXISTS `squads`;
DROP TABLE IF EXISTS `verified`;

-- STEP 3: ENSURE ONLY THE 6 OFFICIAL PRODUCTION TABLES EXIST

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

CREATE TABLE IF NOT EXISTS `clients` (
  `id` int NOT NULL AUTO_INCREMENT,
  `member_id` varchar(50) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `client_email_idx` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
