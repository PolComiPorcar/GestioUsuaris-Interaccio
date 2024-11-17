<?php
define('API_URL', 'https://random-word-api.vercel.app/api?words=1');

function fetchRandomWord(){
    // Initialize cURL session
    $curl = curl_init();

    // Set cURL options
    curl_setopt($curl, CURLOPT_URL, API_URL); // Set the URL
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); // Return the transfer as a string
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);


    // Execute cURL request
    $response = curl_exec($curl);
    $data = null;

    // Check for errors
    if (curl_errno($curl)) {
        echo 'Error:' . curl_error($curl);
    } else {
        // Decode JSON response if it's in JSON format
        $data = json_decode($response, true);
    }

    // Close cURL session
    curl_close($curl);
    return $data;
}
function fetchDictionariWord($word){
    // Initialize cURL session
    $curl = curl_init();
    $api_url = 'https://api.dictionaryapi.dev/api/v2/entries/en/' . $word;
    // Set cURL options
    curl_setopt($curl, CURLOPT_URL, $api_url); // Set the URL
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); // Return the transfer as a string
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);


    // Execute cURL request
    $response = curl_exec($curl);
    $data = null;

    // Check for errors
    if (curl_errno($curl)) {
        echo 'Error:' . curl_error($curl);
    } else {
        // Decode JSON response if it's in JSON format
        $data = json_decode($response, true);
        if (isset($data[0]['meanings'])) { 
            foreach ($data as $entry) {
                foreach ($entry['meanings'] as $meaning) {
                    if (isset($meaning['definitions'][0]['definition'])) {
                        $data = $meaning['definitions'][0]['definition'];
                        break 2;
                    }
                }
            }
        } else {
            $data = null;
        }
    }

    // Close cURL session
    curl_close($curl);
    return $data;
}

function removeAccents($text) {
    $accents = array(
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
        'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U',
        'ü' => 'u', 'Ü' => 'U'
    );
    return strtr($text, $accents);
}

function conflictResolverResolution($conflictRecords , $player1_id , $player2_id){
    $oldestGuess = $conflictRecords[0];
    $result = ['winner' => null, 'p1_points' => 0, 'p2_points' => 0];

    if (count($conflictRecords) === 1) {
        // Case 1: Only one guess
        if ($oldestGuess['word_guessed'] === 'final') {
            $result['winner'] = $oldestGuess['playerID'];
        }
        if ($oldestGuess['playerID'] == $player1_id) {
            $result['p1_points'] = $oldestGuess['points'];
        } else {
            $result['p2_points'] = $oldestGuess['points'];
        }
    } else {
        $allGuessesAreFinal = array_reduce($conflictRecords, function($carry, $record) {
            return $carry && ($record['word_guessed'] === 'final');
        }, true);

        if ($allGuessesAreFinal) {
            // Case 5: Multiple "final" guesses
            $result['winner'] = $oldestGuess['playerID'];
            if ($oldestGuess['playerID'] == $player1_id) {
                $result['p1_points'] = $oldestGuess['points'];
            } else {
                $result['p2_points'] = $oldestGuess['points'];
            }
        } else {
            foreach ($conflictRecords as $record) {
                if ($record['word_guessed'] === 'final') {
                    // Case 3 & Case 4: A single "final" guess
                    $result['winner'] = $record['playerID'];
                    
                    // Points for the winner
                    if ($record['playerID'] == $player1_id) {
                        $result['p1_points'] = $record['points'];
                    } else {
                        $result['p2_points'] = $record['points'];
                    }

                    // The oldest guesser still gets their points if they are NOT the winner
                    if ($oldestGuess['playerID'] !== $record['playerID']) {
                        if ($oldestGuess['playerID'] == $player1_id) {
                            $result['p1_points'] += $oldestGuess['points'];
                        } else {
                            $result['p2_points'] += $oldestGuess['points'];
                        }
                    }
                    break; 
                }
            }

            if (!$result['winner']) {      
                foreach ($conflictRecords as $record) {
                    $score = $record['points'];

                    if ($record === $oldestGuess) {
                        // Case 2.1: Oldest guess gets all its points
                        if ($record['playerID'] == $player1_id) {
                            $result['p1_points'] += $score;
                        } else {
                            $result['p2_points'] += $score;
                        }
                    } else {
                        // Case 2.2: Other guessers get points only if their score is higher
                        if ($score > $oldestGuess['points']) {
                            $difference = $score - $oldestGuess['points'];
                            if ($record['playerID'] == $player1_id) {
                                $result['p1_points'] += $difference;
                            } else {
                                $result['p2_points'] += $difference;
                            }
                        }
                    }
                }
            }
        }
    }
    return $result;
}

function conflictResolver($db, $game_id, $conflictRecords, $latencyP1, $latencyP2, $player1_id, $player2_id){
    $oldestGuessTime = null;

    foreach ($conflictRecords as $record) {
        $currentGuessTime = DateTime::createFromFormat('Y-m-d H:i:s.u', $record['guessTime']);
        $latencyAdjustedTime = clone $currentGuessTime;

        if ($record['playerID'] == $player1_id) {
            // Adjust latency for player 1 (milliseconds to seconds and microseconds)
            $seconds = floor($latencyP1 / 1000);
            $microseconds = ($latencyP1 - ($seconds * 1000)) * 1000;
            $latencyAdjustedTime->modify("+$seconds seconds");
            $latencyAdjustedTime->setTime(
                (int) $latencyAdjustedTime->format('H'),
                (int) $latencyAdjustedTime->format('i'),
                (int) $latencyAdjustedTime->format('s'),
                (int) $microseconds
            );
        } else {
            // Adjust latency for player 2 (milliseconds to seconds and microseconds)
            $seconds = floor($latencyP2 / 1000);
            $microseconds = ($latencyP2 - ($seconds * 1000)) * 1000;
            $latencyAdjustedTime->modify("+$seconds seconds");
            $latencyAdjustedTime->setTime(
                (int) $latencyAdjustedTime->format('H'),
                (int) $latencyAdjustedTime->format('i'),
                (int) $latencyAdjustedTime->format('s'),
                (int) $microseconds
            );
        }

        if ($oldestGuessTime === null || $latencyAdjustedTime < $oldestGuessTime) {
            $oldestGuessTime = $latencyAdjustedTime;
        }
    }

    // Get the current time in milliseconds
    $nowInMilliseconds = round(microtime(true) * 1000);

    // Calculate the difference between current time and the oldest guess time in milliseconds
    $interval = $nowInMilliseconds - (int)$oldestGuessTime->format('U.u') * 1000;

    if ($interval < 5000) {
        return; 
    }

    usort($conflictRecords, function($a, $b) use ($latencyP1, $latencyP2, $player1_id, $player2_id) {
        // Parse guess times
        $timeA = DateTime::createFromFormat('Y-m-d H:i:s.u', $a['guessTime']);
        $timeB = DateTime::createFromFormat('Y-m-d H:i:s.u', $b['guessTime']);
        
        // Calculate latency for each player
        $latencyA = ($a['playerID'] == $player1_id) ? $latencyP1 : $latencyP2;
        $latencyB = ($b['playerID'] == $player1_id) ? $latencyP1 : $latencyP2;

        // Adjust time for player A
        $secondsA = floor($latencyA / 1000);
        $microsecondsA = ($latencyA - ($secondsA * 1000)) * 1000;
        $timeA->modify("+$secondsA seconds");
        $timeA->setTime(
            (int) $timeA->format('H'),
            (int) $timeA->format('i'),
            (int) $timeA->format('s'),
            (int) $microsecondsA
    );

    // Adjust time for player B
    $secondsB = floor($latencyB / 1000);
    $microsecondsB = ($latencyB - ($secondsB * 1000)) * 1000;
    $timeB->modify("+$secondsB seconds");
    $timeB->setTime(
        (int) $timeB->format('H'),
        (int) $timeB->format('i'),
        (int) $timeB->format('s'),
        (int) $microsecondsB
    );

    // Compare the adjusted times
    return $timeA <=> $timeB;
});


    $result = conflictResolverResolution($conflictRecords , $player1_id , $player2_id );

    //Borramos los conflictos de la BD porque ya lo hemos resuelto
    $deleteStmt = $db->prepare('DELETE FROM conflicts WHERE game_id = :game_id');
    $deleteStmt->bindValue(':game_id', $game_id);
    $deleteStmt->execute();

    return $result;
}
session_start();

// Conectar a la base de datos SQLite
try {
    $db = new PDO('sqlite:../private/main.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode(['error' => 'Error de conexión: ' . $e->getMessage()]);
    exit();
}

$action = isset($_GET['action']) ? $_GET['action'] : '';

switch ($action) {
    case 'join':
        if (!isset($_SESSION['player_id'])) {
            $_SESSION['player_id'] = uniqid();
        }

        $player_id = $_SESSION['player_id'];
        $username = $_SESSION['user_name'];
        $latency = $_GET['latency'];
        $game_id = null;
        $word = null;
        $definition = null;

        // Intentar unirse a un juego existente donde haya menos de 3 jugadores
        $stmt = $db->prepare('SELECT game_id FROM games WHERE (player2 IS NULL) LIMIT 1');
        $stmt->execute();
        $existing_game = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing_game) {
            // Unirse a un juego existente
            $game_id = $existing_game['game_id'];
            $stmt = $db->prepare('UPDATE games SET player2 = :player_id, p2_name = :p2_name ,p2_latency = :latency WHERE game_id = :game_id');
            $stmt->bindValue(':p2_name', $username);
            $stmt->bindValue(':player_id', $player_id);
            $stmt->bindValue(':game_id', $game_id);
            $stmt->bindValue(':latency', $latency);
            $stmt->execute();
        } else {
            // Crear un nuevo juego
            $game_id = uniqid();
            $stmt = $db->prepare('INSERT INTO games (game_id, player1, p1_name, p1_latency, word, word_revealed, word_definition, last_reveal_time) VALUES (:game_id, :player_id, :p1_name, :p1_latency,:word, :word_revealed, :word_definition, :last_reveal_time)');

            do {
                $word = removeAccents(fetchRandomWord()[0]);
                $definition = fetchDictionariWord($word);
            } while (!$definition);
            $lastRevealTime = (new DateTime())->format('Y-m-d H:i:s');

            $stmt->bindValue(':game_id', $game_id);
            $stmt->bindValue(':player_id', $player_id);
            $stmt->bindValue(':p1_name', $username);
            $stmt->bindValue(':p1_latency', $latency);
            $stmt->bindValue(':word', $word);
            $stmt->bindValue(':word_definition', $definition);
            $stmt->bindValue(':word_revealed', str_repeat('_', strlen($word)));
            $stmt->bindValue(':last_reveal_time', $lastRevealTime);
            $stmt->execute();
        }

        echo json_encode(['game_id' => $game_id, 'player_id' => $player_id, 'username' => $username, 'word' => $word, 'definition' => $definition]); //TODO : REMOVE WORD
        break;

    case 'status':

        //Get game info
        $game_id = $_GET['game_id'];
        $player_id = $_SESSION['player_id'];
        $latency = $_GET['latency'];

        //actualizamos las latencias respectivas para que se tengan en cuenta en la resolucion de conflictos
        $stmt = $db->prepare(<<<SQL
            UPDATE games SET
            p1_latency = CASE WHEN player1 = :player_id THEN :latency ELSE p1_latency END,
            p2_latency = CASE WHEN player2 = :player_id THEN :latency ELSE p2_latency END
            WHERE game_id = :game_id
        SQL);

        $stmt->bindValue(':player_id', $player_id);
        $stmt->bindValue(':latency', $latency);
        $stmt->bindValue(':game_id', $game_id);
        $stmt->execute();

        $stmt = $db->prepare('SELECT * FROM games WHERE game_id = :game_id');
        $stmt->bindValue(':game_id', $game_id);
        $stmt->execute();
        $game = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$game) {
            echo json_encode(['error' => 'Juego no encontrado']);
            break;
        }

        if (!empty($game["player1"]) && !empty($game["player2"]) && $game["player1"] !== '' && $game["player2"] !== '') {
            //get conflict info
            $stmt = $db->prepare('SELECT * FROM conflicts WHERE game_id = :game_id ORDER BY guessTime ASC');
            $stmt->bindValue(':game_id', $game_id);
            $stmt->execute();

            $conflictRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (count($conflictRecords) > 0) {
                $result = conflictResolver($db, $game_id, $conflictRecords, $game["p1_latency"], $game["p2_latency"], $game["player1"], $game["player2"]);
            }

            if (!empty($result)) {
                $correctGuesser = $result['winner'];
                $pointsPlayer1 = $game['points_player1'];
                $pointsPlayer2 = $game['points_player2'];
                $newPointsPlayer1 = $pointsPlayer1 + $result['p1_points'];
                $newPointsPlayer2 = $pointsPlayer2 + $result['p2_points'];

                // Incrementa puntos según corresponda
                $stmt = $db->prepare(<<<SQL
                    UPDATE games 
                    SET 
                        points_player1 = :newPointsPlayer1,
                        points_player2 = :newPointsPlayer2
                    WHERE game_id = :game_id
                SQL);

                $stmt->bindValue(':newPointsPlayer1', $newPointsPlayer1);
                $stmt->bindValue(':newPointsPlayer2', $newPointsPlayer2);
                $stmt->bindValue(':game_id', $game_id);
                $stmt->execute();

                // Verifica si alguien alcanzó los 50 puntos después de actualizar
                if ($newPointsPlayer1 >= 50) {
                    $stmt = $db->prepare("UPDATE games SET winner = player1 WHERE game_id = :game_id");
                    $stmt->bindValue(':game_id', $game_id);
                    $stmt->execute();
                } else if ($newPointsPlayer2 >= 50) {
                    $stmt = $db->prepare("UPDATE games SET winner = player2 WHERE game_id = :game_id");
                    $stmt->bindValue(':game_id', $game_id);
                    $stmt->execute();
                } else {
                    //Si se ha acertado la palabra generamos otra
                    if ($correctGuesser !== null) {
                        do {
                            $new_word = removeAccents(fetchRandomWord()[0]);
                            $new_definition = fetchDictionariWord($new_word);
                        } while (!$new_definition);

                        $stmt = $db->prepare('UPDATE games SET word = :new_word, word_definition = :new_definition, word_revealed = :new_revealed WHERE game_id = :game_id');
                        $stmt->bindValue(':new_word', $new_word);
                        $stmt->bindValue(':new_definition', $new_definition);
                        $stmt->bindValue(':new_revealed', str_repeat('_', strlen($new_word)));
                        $stmt->bindValue(':game_id', $game_id);
                        $stmt->execute();
                    }
                }
            }

            //Busquem la info actualitzada de game despres de resoldre conflictes
            $stmt = $db->prepare('SELECT * FROM games WHERE game_id = :game_id');
            $stmt->bindValue(':game_id', $game_id);
            $stmt->execute();
            $game = $stmt->fetch(PDO::FETCH_ASSOC);

            $lastRevealTime = new DateTime($game['last_reveal_time']);
            $now = new DateTime();
            $interval = $now->getTimestamp() - $lastRevealTime->getTimestamp();

            if ($interval >= 15) {
                $word = $game['word'];
                $word_revealed = $game['word_revealed'];

                // Revelar la siguiente letra no descubierta
                for ($i = 0; $i < strlen($word); $i++) {
                    if ($word_revealed[$i] === '_') {
                        $word_revealed[$i] = $word[$i];
                        break;
                    }
                }

                // Actualizar el juego en la base de datos con la nueva palabra revelada y el tiempo de revelación
                $stmt = $db->prepare('UPDATE games SET word_revealed = :word_revealed, last_reveal_time = :last_reveal_time WHERE game_id = :game_id');
                $stmt->bindValue(':word_revealed', $word_revealed);
                $stmt->bindValue(':last_reveal_time', $now->format('Y-m-d H:i:s'));
                $stmt->bindValue(':game_id', $game_id);
                $stmt->execute();

                $game['word_revealed'] = $word_revealed;
            }
        }

        echo json_encode([
            'players' => [
                'player1' => [
                    'name' => $game['p1_name'],
                    'id' => $game['player1']
                ],
                'player2' => [
                    'name' => $game['p2_name'],
                    'id' => $game['player2']
                ]
            ],
            'points' => [$game['points_player1'], $game['points_player2']],
            'word_revealed' => $game['word_revealed'],
            'definition' => $game['word_definition'],
            'winner' => $game['winner']
        ]);


        break;

    case 'guess':
        $game_id = $_GET['game_id'];
        $player_id = $_SESSION['player_id'];

        $stmt = $db->prepare('SELECT * FROM games WHERE game_id = :game_id');
        $stmt->bindValue(':game_id', $game_id);
        $stmt->execute();
        $game = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$game || $game['winner']) {
            echo json_encode(['error' => 'Juego no encontrado o ya terminado']);
            break;
        }

        $word = $game['word'];
        $guess = $_GET['guess'];

        if (strlen($guess) < strlen($word)) {
            $guess = str_pad($guess, strlen($word), '_');
        } else if (strlen($guess) > strlen($word)) {
            $guess = substr($guess, 0, strlen($word));
        }

        $word_revealed = $game['word_revealed'];
        $new_revealed = '';
        $points_to_add = 0;
        $isFullGuess = $guess === $word;

        if (!$isFullGuess) {
            for ($i = 0; $i < strlen($word); $i++) {

                if ($guess[$i] === $word[$i] && $word_revealed[$i] === '_') {
                    $new_revealed .= $word[$i];
                    $points_to_add += 1;
                } else {
                    $new_revealed .= $word_revealed[$i];
                }
            }
        } else {
            $new_revealed = $word;
            $points_to_add = 10;
        }

        // we only want to log a conflict if there is a correct guess
        if ($points_to_add > 0) {
            $stmt = $db->prepare('INSERT INTO conflicts (game_id, playerID, guessTime, word_guessed , points) VALUES (:game_id, :playerID, :guessTime , :word_guessed, :points)');
            $stmt->bindValue(':playerID', $player_id);
            $stmt->bindValue(':game_id', $game_id);
            $stmt->bindValue(':word_guessed', $isFullGuess ? "final" : $new_revealed);
            $stmt->bindValue(':points', $points_to_add);
            $now = DateTime::createFromFormat('U.u', microtime(true));
            $formattedTime = $now->format('Y-m-d H:i:s.u');
            $stmt->bindValue(':guessTime', $formattedTime);

            $stmt->execute();
        }

        $stmt = $db->prepare('UPDATE games SET word_revealed = :word_revealed WHERE game_id = :game_id');

        //Actualizamos la palabra relevada en la BD i en el cliente independientmeent de si se ha resuelto el conflicto o no, 
        //Solo para que los clientes la tengan de manera visual 

        $stmt->bindValue(':word_revealed', $new_revealed);
        $stmt->bindValue(':game_id', $game_id);
        $stmt->execute();

        echo json_encode(['word_revealed' => $new_revealed]);
        break;
    case "delete":
        $game_id = $_GET['game_id'];
        $stmt = $db->prepare('DELETE FROM games WHERE game_id = :game_id');
        $stmt->bindValue(':game_id', $game_id);
        $stmt->execute();
        break;
    case "restart":

        $default_points = 0;
        $default_word = '';
        $default_word_revealed = '';
        $default_word_definition = '';
        $default_next_word_time = 0;
        $default_winner = null; // null might be used for the winner if there's no winner yet
        $default_last_reveal_time = null;

        // Prepare the SQL query to reset the game fields
        $query = "UPDATE games SET 
            points_player1 = :points_player1, 
            points_player2 = :points_player2, 
            word = :word, 
            word_revealed = :word_revealed, 
            word_definition = :word_definition, 
            next_word_time = :next_word_time, 
            winner = :winner, 
            last_reveal_time = :last_reveal_time 
            WHERE game_id = :game_id";

        // Prepare the statement
        $stmt = $db->prepare($query);

        // Bind the parameters
        $stmt->bindValue(':points_player1', $default_points, SQLITE3_INTEGER);
        $stmt->bindValue(':points_player2', $default_points, SQLITE3_INTEGER);
        $stmt->bindValue(':word', $default_word, SQLITE3_TEXT);
        $stmt->bindValue(':word_revealed', $default_word_revealed, SQLITE3_TEXT);
        $stmt->bindValue(':word_definition', $default_word_definition, SQLITE3_TEXT);
        $stmt->bindValue(':next_word_time', $default_next_word_time, SQLITE3_INTEGER);
        $stmt->bindValue(':winner', $default_winner, SQLITE3_TEXT); // Use SQLite3_TEXT for null or string
        $stmt->bindValue(':last_reveal_time', $default_last_reveal_time, SQLITE3_INTEGER);
        $stmt->bindValue(':game_id', $game_id, SQLITE3_INTEGER);
        $stmt->execute();   
        break;
}
?>