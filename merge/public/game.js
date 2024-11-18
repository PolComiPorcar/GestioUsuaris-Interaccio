let idGame, idPlayer, player_self, player2;
let points = [0, 0, 0];
let winner = null;
let def = null;

const textState = document.getElementById('state');
const player1text = document.getElementById('player1text');
const player2text = document.getElementById('player2text');
const score1 = document.getElementById('score1');
const score2 = document.getElementById('score2');
const divGame = document.getElementById('game');
const inputGuess = document.getElementById('input-guess');
const revealedWord = document.getElementById('word-container');
const definition = document.getElementById('definition');
const letterTemplate = revealedWord.querySelector('#letter-template');
const restartButton = document.getElementById('restart-button');

window.addEventListener('beforeunload', function (e) {
  fetch('game.php?action=delete&game_id=' + idGame);
});

function goHome() {
  fetch('game.php?action=delete&game_id=' + idGame).then(() => (window.location.href = '/'));
}

// Conectar al servidor del juego
function unirseAlJoc() {
  ping().then((latency) => {
    fetch(`game.php?action=join&latency=${latency}`)
      .then((response) => response.json())
      .then((data) => {
        idGame = data.game_id;
        idPlayer = data.player_id;
        player_self = data.username;
        console.log(data.word);
        console.log(data.definition);

        comprovarEstatDelJoc();
      });
  });
}

async function comprovarEstatDelJoc() {
  const latency = await ping();
  fetch(`game.php?action=status&game_id=${idGame}&latency=${latency}&player_id=${idPlayer}`)
    .then((response) => response.json())
    .then((joc) => {
      if (joc.error) {
        alert(joc.error);
        window.location.href = '/';
        return;
      }

      points = joc.points;
      winner = joc.winner;
      def = joc.definition;
      definition.innerText = def;

      player1text.innerText = joc.players.player1.name;
      player2text.innerText = joc.players.player2.name;
      score1.innerText = points[0];
      score2.innerText = points[1];

      if (winner) {
        if (winner === idPlayer) {
          textState.innerText = 'Has guanyat!';
        } else {
          textState.innerText = 'Has perdut!';
        }

        restartButton.style.display = 'block';
      } else {
        if ([joc.players.player1.id, joc.players.player2.id].includes(idPlayer)) {
          textState.innerText = `Ets el Jugador ${player_self}  `;

          if (joc.players.player2.id) {
            textState.innerText += ' Joc en curs...';
            divGame.style.display = 'block';
          } else {
            textState.innerText += ' Esperant al Jugador 2...';
            divGame.style.display = 'none';
          }
        } else {
          textState.innerText = ' Espectador...';
          divGame.style.display = 'none';
        }
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
    .then((response) => response.json())
    .then((data) => {
      createAndUpdateWordContainer(data.word_revealed);
      inputGuess.value = '';
    });
}

function ping() {
  const timestamp = performance.now();
  return fetch('/game.php?action=ping')
    .then(() => {
      latency = performance.now() - timestamp;
      document.getElementById('latency').textContent = latency.toFixed(2);
      return latency;
    })
    .catch((error) => console.error('Error sending ping:', error));
}

function restartGame() {
  fetch(`game.php?action=restart&game_id=${idGame}`)
    .then((response) => response.json())
    .then((data) => {
      restartButton.style.display = 'none';
      console.log(data.word);
      console.log(data.definition);
    });
}

// Iniciar el juego

unirseAlJoc();
