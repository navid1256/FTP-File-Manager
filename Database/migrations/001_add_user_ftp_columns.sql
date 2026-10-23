ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `ftp_host` varchar(255) DEFAULT NULL AFTER `password`,
  ADD COLUMN IF NOT EXISTS `ftp_port` int(10) UNSIGNED NOT NULL DEFAULT 21 AFTER `ftp_host`,
  ADD COLUMN IF NOT EXISTS `ftp_user` varchar(255) DEFAULT NULL AFTER `ftp_port`,
  ADD COLUMN IF NOT EXISTS `ftp_pass` varchar(255) DEFAULT NULL AFTER `ftp_user`,
  ADD COLUMN IF NOT EXISTS `ftp_root` varchar(512) NOT NULL DEFAULT '/' AFTER `ftp_pass`;
