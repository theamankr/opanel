ALTER TABLE `web_database_user` ADD `database_password_sha2` varchar(70) DEFAULT NULL AFTER `database_password`;
ALTER TABLE `web_database_user` ADD `database_password_postgres` varchar(255) DEFAULT NULL AFTER `database_password_mongo`;
ALTER TABLE `client` ADD `limit_database_postgresql` INT NOT NULL DEFAULT '-1' AFTER `limit_database`;
ALTER TABLE `client_template` ADD `limit_database_postgresql` INT NOT NULL DEFAULT '-1' AFTER `limit_database`;