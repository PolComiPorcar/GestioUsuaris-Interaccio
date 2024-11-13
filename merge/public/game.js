let idGame, idPlayer;
let points = [0, 0, 0];
let winner = null;
let def = null;

const textState = document.getElementById('state');
const score1 = document.getElementById('score1');
const score2 = document.getElementById('score2');
const divGame = document.getElementById('game');
const inputGuess = document.getElementById('input-guess');
const revealedWord = document.getElementById('word-container');
const definition = document.getElementById('definition');
const letterTemplate = revealedWord.querySelector('#letter-template');

// Conectar al servidor del juego
function unirseAlJoc() {
    fetch('game.php?action=join')
        .then(response => response.json())
        .then(data => {
            idGame = data.game_id;
            idPlayer = data.player_id;
            console.log(data.word);
            console.log(data.definition);
            
            comprovarEstatDelJoc();
        });
}


function comprovarEstatDelJoc() {
    fetch(`game.php?action=status&game_id=${idGame}`)
        .then(response => response.json())
        .then(joc => {
            if (joc.error) {
                textState.innerText = joc.error;
                return;
            }

            points = joc.points;
            winner = joc.winner;
            def = joc.definition;
            definition.innerText = def;

            score1.innerText = points[0];
            score2.innerText = points[1];


            if (winner) {
                if (winner === idPlayer) {
                    textState.innerText = 'Has guanyat!';
                } else {
                    textState.innerText = 'Has perdut!';
                }
                return;
            }

            //Verificar Conexio 2 players
            if (joc.players.player1 === idPlayer) {
                if (joc.players.player2) {
                    textState.innerText = 'Joc en curs...';
                    divGame.style.display = 'block';  
                } else {
                    textState.innerText = 'Ets el Jugador 1. Esperant al Jugador 2...';
                    divGame.style.display = 'none';  
                }
            } else if (joc.players.player2 === idPlayer) {
                textState.innerText = 'Joc en curs...';
                divGame.style.display = 'block';
            } else {
                textState.innerText = 'Espectador...';
                divGame.style.display = 'none';
            }

            createAndUpdateWordContainer(joc.word_revealed);

            setTimeout(comprovarEstatDelJoc, 500);
        });
}


function createAndUpdateWordContainer(word_revealed) {
    // Limpiar el contenedor de letras antes de agregar las nuevas
    revealedWord.innerHTML = ''; // Limpia el contenido actual

    // Crear y agregar cada letra a 'revealedWord'
    for (let i = 0; i < word_revealed.length; i++) {
        const letter = letterTemplate.content.cloneNode(true);
        const letterTile = letter.querySelector('.letter-tile');
        if (letterTile) {
            letterTile.innerText = word_revealed[i] || ''; // Asegúrate de que no sea undefined
            revealedWord.appendChild(letter);
        }
    }
}


function enviarParaula(event) {
    event.preventDefault(); 
    const guess = inputGuess.value;
    fetch(`game.php?action=guess&game_id=${idGame}&guess=${guess}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                textState.innerText = '¡Has adivinado correctamente!';
            } else {
                createAndUpdateWordContainer(data.word_revealed);
            }
            inputGuess.value = '';
        });
}

// Iniciar el juego
unirseAlJoc();