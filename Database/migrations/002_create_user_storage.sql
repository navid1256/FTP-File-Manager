-- TEMPLATE: use php tools/storage-schema.php to render USER_ID_TYPE for the target DB.
-- This is additive: it does not alter users or import the database dump.
CREATE TABLE `user_storage` (
  `user_id` {{USER_ID_TYPE}} NOT NULL,
  `folder_path` varchar(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `quota_bytes` bigint unsigned NOT NULL DEFAULT 2147483648,
  `used_bytes` bigint unsigned NOT NULL DEFAULT 0,
  `reserved_bytes` bigint unsigned NOT NULL DEFAULT 0,
  `status` enum('pending','provisioning','ready','failed') NOT NULL DEFAULT 'pending',
  `provision_token` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `attempt_token` char(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `lease_expires_at` datetime DEFAULT NULL,
  `last_error` varchar(50) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `user_storage_folder_unique` (`folder_path`),
  UNIQUE KEY `user_storage_token_unique` (`provision_token`),
  KEY `user_storage_status_index` (`status`),
  CONSTRAINT `user_storage_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
