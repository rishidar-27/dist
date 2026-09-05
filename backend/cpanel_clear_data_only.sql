-- ====================================================================
-- GOGANGS STUDIO — CLEAR DATA & REMOVE UNUSED TABLES ONLY
-- Target Database: profilei_website
-- ====================================================================

USE `profilei_website`;

SET FOREIGN_KEY_CHECKS = 0;

-- --------------------------------------------------------------------
-- STEP 1: DROP ONLY THE UNUSED / UNWANTED TABLES (From Other Apps)
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
-- STEP 2: CLEAR DATA ONLY FROM STUDIO TABLES (Tables are KEPT intact)
-- --------------------------------------------------------------------
TRUNCATE TABLE `freelancers`;
TRUNCATE TABLE `portfolio_videos`;
TRUNCATE TABLE `deleted_accounts`;
TRUNCATE TABLE `client_buckets`;

-- Reset app settings to default
TRUNCATE TABLE `app_settings`;
INSERT INTO `app_settings` (`setting_key`, `setting_value`) VALUES 
  ('max_allowed_users', '50'),
  ('registration_open', '1');

-- --------------------------------------------------------------------
-- STEP 3: RE-INSERT ONLY ADMIN ACCOUNT (rishidar27@gmail.com)
-- --------------------------------------------------------------------
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
