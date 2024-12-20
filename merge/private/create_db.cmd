sqlite3 main.db "CREATE TABLE IF NOT EXISTS `users` (`user_id` INTEGER PRIMARY KEY, `user_name` varchar(63),`user_email` varchar(63), `user_password` varchar(255),`reset_token` varchar(255));"
sqlite3 main.db "CREATE UNIQUE INDEX `user_name_UNIQUE` ON `users` (`user_name` ASC);"
sqlite3 main.db "CREATE UNIQUE INDEX `user_email_UNIQUE` ON `users` (`user_email` ASC);"
sqlite3 main.db "CREATE TABLE games (game_id TEXT PRIMARY KEY, player1 TEXT, player2 TEXT, points_player1 INTEGER DEFAULT 0, points_player2 INTEGER DEFAULT 0, word TEXT, word_revealed TEXT,word_definition TEXT, next_word_time INTEGER DEFAULT NULL, winner TEXT);"
sqlite3 main.db "ALTER TABLE games ADD COLUMN last_reveal_time TEXT DEFAULT NULL;"
sqlite3 main.db "CREATE TABLE conflicts (game_id TEXT, playerID TEXT, guessed_word TEXT , guessTime TEXT);"
sqlite3 main.db "ALTER TABLE games ADD COLUMN p1_latency REAL DEFAULT 0.0;"
sqlite3 main.db "ALTER TABLE games ADD COLUMN p2_latency REAL DEFAULT 0.0;"
sqlite3 main.db "ALTER TABLE games ADD COLUMN p1_name TEXT"
sqlite3 main.db "ALTER TABLE games ADD COLUMN p2_name TEXT"
sqlite3 main.db "ALTER TABLE conflicts ADD COLUMN word_guessed TEXT DEFAULT '';"
sqlite3 main.db "ALTER TABLE conflicts ADD COLUMN points TEXT DEFAULT '';"
