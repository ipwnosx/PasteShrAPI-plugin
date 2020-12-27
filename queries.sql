ALTER TABLE `users` ADD `api_token` VARCHAR(80) NULL DEFAULT NULL AFTER `gp`;
ALTER TABLE `users` ADD UNIQUE(`api_token`);