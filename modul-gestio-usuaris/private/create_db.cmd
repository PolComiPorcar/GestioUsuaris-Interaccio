sqlite3 users.db "CREATE TABLE IF NOT EXISTS `users` (`user_id` INTEGER PRIMARY KEY, `user_name` varchar(63),`user_email` varchar(63), `user_password` varchar(255),`reset_token` varchar(255));"
sqlite3 users.db "CREATE UNIQUE INDEX `user_name_UNIQUE` ON `users` (`user_name` ASC);"
sqlite3 users.db "CREATE UNIQUE INDEX `user_email_UNIQUE` ON `users` (`user_email` ASC);"
