<?php
define('API_URL', 'https://clientes.api.greenborn.com.ar/public-random-word');


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

function removeAccents($text) {
    $accents = array(
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
        'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U',
        'ü' => 'u', 'Ü' => 'U'
    );
    return strtr($text, $accents);
}


function conflictResolver($db , $game_id ,$conflictRecords){
    $oldestGuessTime = null;

    foreach ($conflictRecords as $record) {
        $currentGuessTime = $record['guessTime'];

        if ($oldestGuessTime === null || $currentGuessTime < $oldestGuessTime) {
            $oldestGuessTime = $currentGuessTime;
        }
    }

    $oldestDateTime = DateTime::createFromFormat('Y-m-d H:i:s.u', $oldestGuessTime);
    $now = DateTime::createFromFormat('U.u', microtime(true));
    $interval = $now->diff($oldestDateTime);
    $millisecondsDifference = (($interval->days * 24 * 60 * 60) + $interval->h * 3600 + $interval->i * 60 + $interval->s) * 1000 + (int)($interval->f * 1000);

    if ($millisecondsDifference >= 5000) {
          
        $randomIndex = array_rand($conflictRecords); // Get a random index
        $winner = $conflictRecords[$randomIndex]['playerID'];

        //Borramos los conflictos de la BD porque ya lo hemos resuelto
        $deleteStmt = $db->prepare('DELETE FROM conflicts WHERE game_id = :game_id');
        $deleteStmt->bindValue(':game_id', $game_id);
        $deleteStmt->execute();

        return $winner;

    } else {
        return; //Si no ha pasado el intervalo no resolvemos nada
    }
 
}

session_start();

// Conectar a la base de datos SQLite
try {
    $db = new PDO('sqlite:../private/games.db');
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
        $game_id = null;
        $word = null;

        // Intentar unirse a un juego existente donde haya menos de 3 jugadores
        $stmt = $db->prepare('SELECT game_id FROM games WHERE (player2 IS NULL) LIMIT 1');
        $stmt->execute();
        $existing_game = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing_game) {
            // Unirse a un juego existente
            $game_id = $existing_game['game_id'];
            $stmt = $db->prepare('UPDATE games SET player2 = :player_id WHERE game_id = :game_id');
            $stmt->bindValue(':player_id', $player_id);
            $stmt->bindValue(':game_id', $game_id);
            $stmt->execute();
        } else {
            // Crear un nuevo juego
            $game_id = uniqid();
            $stmt = $db->prepare('INSERT INTO games (game_id, player1, word, word_revealed) VALUES (:game_id, :player_id, :word, :word_revealed)');

            $word =  removeAccents( fetchRandomWord()[0]);
            $stmt->bindValue(':game_id', $game_id);
            $stmt->bindValue(':player_id', $player_id);
            $stmt->bindValue(':word', $word);
            $stmt->bindValue(':word_revealed', str_repeat('_', strlen($word)));
            $stmt->execute();
        }

        echo json_encode(['game_id' => $game_id, 'player_id' => $player_id , 'word' => $word]); //TODO : REMOVE WORD
        break;

    case 'status':

        //Get game info
        $game_id = $_GET['game_id'];
       

        //get conflict info
        $stmt = $db->prepare('SELECT * FROM conflicts WHERE game_id = :game_id ORDER BY guessTime ASC');
        $stmt->bindValue(':game_id', $game_id);  
        $stmt->execute();

        $conflictRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if(count($conflictRecords) > 0){
            $winner = conflictResolver($db , $game_id , $conflictRecords);

            if($winner){
                $stmt = $db->prepare(<<<SQL
                    UPDATE games 
                    SET 
                        winner = :winner,
                        points_player1 = points_player1 + CASE WHEN player1 = :winner THEN 10 ELSE 0 END,
                        points_player2 = points_player2 + CASE WHEN player2 = :winner THEN 10 ELSE 0 END 
                    WHERE game_id = :game_id
                    SQL);

                
                $stmt->bindValue(':winner', $winner);
                $stmt->bindValue(':game_id', $game_id);              
                $stmt->execute();
            }
        }

        //Finalmente buscamos la info del juego actualizada
        $stmt = $db->prepare('SELECT * FROM games WHERE game_id = :game_id');
        $stmt->bindValue(':game_id', $game_id);
        $stmt->execute();
        $game = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$game) {
            echo json_encode(['error' => 'Juego no encontrado']);
        } else {
            echo json_encode([
                'players' => [
                    'player1' => $game['player1'],
                    'player2' => $game['player2']
                ],
                'points' => [$game['points_player1'], $game['points_player2']],
                'word_revealed' => $game['word_revealed'],
                'winner' => $game['winner']
            ]);
        }
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

        if(strlen($guess) < strlen($word)){
            $guess = str_pad($guess, strlen($word), '_');
        }
        else if(strlen($guess) > strlen($word)){
            $guess = substr($guess, 0, strlen($word));
        }

        $word_revealed = $game['word_revealed'];
        $new_revealed = '';

        if ($guess === $word) { //Loggeamos conflicto porque podria haberlo
          
            $stmt = $db->prepare('INSERT INTO conflicts (game_id, playerID, guessTime) VALUES (:game_id, :playerID, :guessTime)');
            $stmt->bindValue(':playerID', $player_id); 
            $stmt->bindValue(':game_id', $game_id);

            $now = DateTime::createFromFormat('U.u', microtime(true)); 
            $formattedTime = $now->format('Y-m-d H:i:s.u');
            $stmt->bindValue(':guessTime', $formattedTime);

            $stmt->execute();

            $new_revealed = $word;
        }
        else 
        {
            
            //Actualizamos palabra revelada
            for ($i = 0; $i < strlen($word); $i++) {
                if ($guess[$i] === $word[$i]) {
                    $new_revealed .= $word[$i];
                } else {
                    $new_revealed .= $word_revealed[$i];
                }
            }
        }

        //Actualizamos la palabra relevada en la BD i en el cliente independientmeent de si se ha resuelto el conflicto o no, 
        //Solo para que los clientes la tengan de manera visual 

        $stmt = $db->prepare('UPDATE games SET word_revealed = :word_revealed WHERE game_id = :game_id');
        $stmt->bindValue(':word_revealed', $new_revealed);
        $stmt->bindValue(':game_id', $game_id);
        $stmt->execute();

        echo json_encode(['word_revealed' => $new_revealed]);
        break;
 }