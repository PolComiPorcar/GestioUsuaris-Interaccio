sqlite3 games.db "CREATE TABLE games (game_id TEXT PRIMARY KEY, player1 TEXT, player2 TEXT, points_player1 INTEGER DEFAULT 0, points_player2 INTEGER DEFAULT 0, word TEXT, word_revealed TEXT, next_word_time INTEGER DEFAULT NULL, winner TEXT);"
sqlite3 games.db "CREATE TABLE conflicts (game_id TEXT, playerID TEXT, guessTime DATETIME(3));"
