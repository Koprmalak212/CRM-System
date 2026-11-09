
/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `activity_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `activity_logs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `level` enum('info','warning','error','critical') NOT NULL,
  `actor` varchar(190) NOT NULL,
  `action` varchar(160) NOT NULL,
  `target` varchar(120) NOT NULL DEFAULT '',
  `ip` varchar(45) NOT NULL,
  `created_at` datetime NOT NULL,
  `is_demo` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_created` (`created_at`),
  KEY `idx_level` (`level`)
) ENGINE=InnoDB AUTO_INCREMENT=4480 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `admins`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `admins` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(60) NOT NULL,
  `email` varchar(190) NOT NULL,
  `name` varchar(120) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `must_change_password` tinyint(1) NOT NULL DEFAULT 1,
  `role` enum('admin','user') NOT NULL DEFAULT 'user',
  `perm_integrations` enum('none','view','manage') NOT NULL DEFAULT 'none',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `avatar` varchar(120) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `api_keys`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `api_keys` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(80) NOT NULL,
  `key_prefix` varchar(12) NOT NULL,
  `key_hash` char(64) NOT NULL,
  `key_enc` text DEFAULT NULL,
  `scope` varchar(30) NOT NULL DEFAULT 'health:read',
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_used_at` datetime DEFAULT NULL,
  `revoked_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `key_hash` (`key_hash`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `app_meta`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `app_meta` (
  `k` varchar(40) NOT NULL,
  `v` varchar(255) NOT NULL,
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `backups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `backups` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `type` enum('full','incremental','database') NOT NULL,
  `status` enum('completed','running','failed') NOT NULL,
  `size_mb` int(10) unsigned NOT NULL,
  `started_at` datetime NOT NULL,
  `duration_s` int(10) unsigned NOT NULL,
  `location` varchar(60) NOT NULL,
  `is_demo` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_started` (`started_at`)
) ENGINE=InnoDB AUTO_INCREMENT=229 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `connections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `connections` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `ip` varchar(45) NOT NULL,
  `device` varchar(120) NOT NULL,
  `location` varchar(80) NOT NULL,
  `protocol` enum('HTTPS','WebSocket','SSH') NOT NULL,
  `status` enum('active','idle','flagged') NOT NULL,
  `started_at` datetime NOT NULL,
  `last_activity` datetime NOT NULL,
  `bytes_in` bigint(20) unsigned NOT NULL DEFAULT 0,
  `bytes_out` bigint(20) unsigned NOT NULL DEFAULT 0,
  `is_demo` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `fk_conn_user` (`user_id`),
  CONSTRAINT `fk_conn_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=273 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `endpoint_checks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `endpoint_checks` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `endpoint_id` int(10) unsigned NOT NULL,
  `checked_at` datetime NOT NULL,
  `state` enum('up','slow','down') NOT NULL,
  `status_code` smallint(5) unsigned DEFAULT NULL,
  `latency_ms` int(10) unsigned DEFAULT NULL,
  `error` varchar(160) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `idx_ep_time` (`endpoint_id`,`checked_at`),
  CONSTRAINT `fk_check_ep` FOREIGN KEY (`endpoint_id`) REFERENCES `monitored_endpoints` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `invoice_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `invoice_items` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `invoice_id` int(10) unsigned NOT NULL,
  `description` varchar(200) NOT NULL,
  `qty` decimal(8,2) NOT NULL,
  `unit_cents` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_item_invoice` (`invoice_id`),
  CONSTRAINT `fk_item_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=726 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `invoices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `invoices` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `number` varchar(20) NOT NULL,
  `customer` varchar(160) NOT NULL,
  `email` varchar(190) NOT NULL,
  `customer_address` varchar(255) NOT NULL DEFAULT '',
  `supply_date` date DEFAULT NULL,
  `issue_date` date NOT NULL,
  `due_date` date NOT NULL,
  `vat_rate` tinyint(3) unsigned NOT NULL DEFAULT 21,
  `subtotal_cents` int(10) unsigned NOT NULL,
  `vat_cents` int(10) unsigned NOT NULL,
  `total_cents` int(10) unsigned NOT NULL,
  `notes` varchar(255) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL,
  `is_demo` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `number` (`number`),
  KEY `idx_issue` (`issue_date`),
  KEY `idx_due` (`due_date`)
) ENGINE=InnoDB AUTO_INCREMENT=387 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `kuma_heartbeats`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `kuma_heartbeats` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `monitor_key` char(16) NOT NULL,
  `monitor_name` varchar(160) NOT NULL,
  `status` tinyint(4) NOT NULL,
  `response_ms` int(10) unsigned DEFAULT NULL,
  `important` tinyint(1) NOT NULL DEFAULT 0,
  `at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_key_at` (`monitor_key`,`at`),
  KEY `idx_important` (`important`,`at`)
) ENGINE=InnoDB AUTO_INCREMENT=6005 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `kuma_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `kuma_settings` (
  `id` tinyint(3) unsigned NOT NULL,
  `kuma_url` varchar(300) NOT NULL DEFAULT '',
  `api_key_enc` text DEFAULT NULL,
  `key_last4` char(4) DEFAULT NULL,
  `poll_interval_s` smallint(5) unsigned NOT NULL DEFAULT 30,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `login_attempts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `login_attempts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `ip` varchar(45) NOT NULL,
  `username` varchar(64) NOT NULL,
  `attempted_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ip_time` (`ip`,`attempted_at`),
  KEY `idx_ip_user_time` (`ip`,`username`,`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `monitored_endpoints`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `monitored_endpoints` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(80) NOT NULL,
  `url` varchar(500) NOT NULL,
  `method` enum('GET','HEAD') NOT NULL DEFAULT 'GET',
  `expected_status` smallint(5) unsigned NOT NULL DEFAULT 200,
  `timeout_s` tinyint(3) unsigned NOT NULL DEFAULT 5,
  `slow_ms` int(10) unsigned NOT NULL DEFAULT 1500,
  `interval_min` smallint(5) unsigned NOT NULL DEFAULT 5,
  `auth_header` varchar(60) NOT NULL DEFAULT '',
  `auth_secret_enc` text DEFAULT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `last_state` enum('up','slow','down','unknown') NOT NULL DEFAULT 'unknown',
  `last_status_code` smallint(5) unsigned DEFAULT NULL,
  `last_latency_ms` int(10) unsigned DEFAULT NULL,
  `last_error` varchar(160) NOT NULL DEFAULT '',
  `last_checked_at` datetime DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `is_demo` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `invoice_id` int(10) unsigned NOT NULL,
  `amount_cents` int(10) unsigned NOT NULL,
  `method` enum('iDEAL','Bank transfer','Credit card','Direct debit') NOT NULL,
  `paid_at` date NOT NULL,
  `reference` varchar(60) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL,
  `is_demo` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `fk_pay_invoice` (`invoice_id`),
  CONSTRAINT `fk_pay_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=228 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `rate_limits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `rate_limits` (
  `ip` varchar(45) NOT NULL,
  `bucket` varchar(20) NOT NULL,
  `window_start` int(10) unsigned NOT NULL,
  `hits` int(10) unsigned NOT NULL DEFAULT 1,
  PRIMARY KEY (`ip`,`bucket`,`window_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `reports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `reports` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(160) NOT NULL,
  `type` varchar(60) NOT NULL,
  `status` enum('ready','running','failed','scheduled') NOT NULL,
  `size_kb` int(10) unsigned NOT NULL,
  `author` varchar(80) NOT NULL,
  `generated_at` datetime NOT NULL,
  `is_demo` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=166 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `service_checks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `service_checks` (
  `service_id` int(10) unsigned NOT NULL,
  `slot` tinyint(3) unsigned NOT NULL,
  `status` enum('ok','degraded','outage') NOT NULL,
  PRIMARY KEY (`service_id`,`slot`),
  CONSTRAINT `fk_check_service` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `services`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `services` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(80) NOT NULL,
  `sort_order` tinyint(3) unsigned NOT NULL,
  `is_demo` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=65 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `test_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `test_log` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `ref` varchar(20) DEFAULT NULL,
  `tested_on` date NOT NULL,
  `area` varchar(40) NOT NULL,
  `test` varchar(200) NOT NULL,
  `result` enum('pass','fixed','open','info') NOT NULL,
  `details` text NOT NULL,
  `fix` text NOT NULL,
  `tester` varchar(80) NOT NULL,
  `source` enum('baseline','manual') NOT NULL DEFAULT 'manual',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `ref` (`ref`),
  KEY `idx_area` (`area`),
  KEY `idx_result` (`result`)
) ENGINE=InnoDB AUTO_INCREMENT=454 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `email` varchar(190) NOT NULL,
  `role` enum('admin','editor','support','viewer') NOT NULL DEFAULT 'viewer',
  `status` enum('active','pending','suspended') NOT NULL DEFAULT 'active',
  `country` varchar(60) NOT NULL,
  `logins` int(10) unsigned NOT NULL DEFAULT 0,
  `last_seen` datetime NOT NULL,
  `created_at` datetime NOT NULL,
  `is_demo` tinyint(1) NOT NULL DEFAULT 0,
  `company` varchar(120) NOT NULL DEFAULT '',
  `job_title` varchar(80) NOT NULL DEFAULT '',
  `phone` varchar(40) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  KEY `idx_status` (`status`),
  KEY `idx_role` (`role`),
  KEY `idx_company` (`company`)
) ENGINE=InnoDB AUTO_INCREMENT=579 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

