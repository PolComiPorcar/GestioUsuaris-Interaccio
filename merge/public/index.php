<?php
require '../../PHPMailer/src/Exception.php';
require '../../PHPMailer/src/PHPMailer.php';
require '../../PHPMailer/src/SMTP.php';
require '../../vendor/autoload.php';

use Dotenv\Dotenv;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Cargar el archivo .env
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();
$recaptcha_secret_key = $_ENV['RECAPTCHA_SECRET_KEY'];
$email_pasword = $_ENV['EMAIL_PASSWORD'];

function checkPwnedPassword($password) {
    $sha1_password = strtoupper(sha1($password));
    $prefix = substr($sha1_password, 0, 5); 

    //GET A API Pwned Passwords
    $url = "https://api.pwnedpasswords.com/range/$prefix";
    $response = file_get_contents($url);

    // Buscar si el hash completo está en la respuesta
    $hash_suffix = substr($sha1_password, 5);
    foreach (explode("\n", $response) as $line) {
        list($hash, $count) = explode(":", trim($line));
        if ($hash === $hash_suffix) {
            return true; 
        }
    }
    return false; 
}

function destroySession() {
    // Borrar los datos de sesión
    session_unset();
    session_destroy();

    // Borrar la cookie de sesión
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
}

session_start();

// defaults
$template = 'home';
$db_connection = 'sqlite:..\private\main.db';

$is_logged_in = 'false';

$AUTH_ONLY_PAGES = ["game"]; // Només pots entrar a aquestes pàgines si has iniciat sessió
$GUEST_ONLY_PAGES = ["login", "register", "lostpwd", "authentication"]; // Només pots entrar a aquestes pàgines si NO has iniciat sessió

if (isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true) 
    $is_logged_in = 'true';


$configuration = array(
    '{FEEDBACK}' => '',
    '{LOGIN_LOGOUT_TEXT}' => 'Identificar-me',
    '{LOGIN_LOGOUT_URL}'  => '/?page=login',
    '{METHOD}'            => 'POST', // es veuen els paràmetres a l'URL i a la consola (???)
    '{REGISTER_URL}'      => '/?page=register',
    '{SITE_NAME}'         => 'La meva pàgina',
    '{LOST_PWD}'          => '/?page=lostpwd',
    '{RESET_PWD}'         => '/?page=resetpwd',
    '{AUTH_CODE}'         => '/?page=authentication',
    '{IS_LOGGED_IN}'      => $is_logged_in,
);
// parameter processing
$parameters = $_POST;
//$configuration['{FEEDBACK}'] = "sessio: " . implode(",", $_SESSION) . $is_logged_in;

if (isset($_GET['page'])) {
    $is_allowed = true;

    if ($is_logged_in === 'false') { // Si ets un guest
        foreach ($AUTH_ONLY_PAGES as $p) {
            if ($_GET['page'] == $p) $is_allowed = false;
        }
    }
    else { // Si has iniciat sessió
        foreach ($GUEST_ONLY_PAGES as $p) {
            if ($_GET['page'] == $p) $is_allowed = false;
        }
    }

    if (!$is_allowed) {
        header('Location: /');
        exit;
    }

    // A partir d'aquí, l'usuari està en una pàgina en la que pot estar
    if ($_GET['page'] == 'register') {
        $template = 'register';
    } else if ($_GET['page'] == 'lostpwd') {
        $template = 'lostpwd';
    } else if ($_GET['page'] == 'resetpwd') {
        $template = 'resetpwd';
    } else if ($_GET['page'] == 'authentication') {
        $template = 'authentication';
    } else if ($_GET['page'] == 'verify') {
        $template = 'verify';
    } else if ($_GET['page'] == 'game') {
        $template = 'game';
    } else if ($_GET['page'] == 'login') {
        if (!isset($_SESSION['user_name'])) {
            $template = 'login';
        } else if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
            $template = 'authentication';
        }
    } else if ($_GET['page'] == 'logout') {
        destroySession();
        header('Location: /');
        exit;
    }
}
else if (isset($parameters['register'])) {
    if (!isset($parameters['g-recaptcha-response']) || empty($parameters['g-recaptcha-response'])) {
        $configuration['{FEEDBACK}'] = '<mark>ERROR: Has de completar el CAPTCHA</mark>';
    } else {
        $recaptcha_response = $parameters['g-recaptcha-response'];
        $recaptcha_url = 'https://www.google.com/recaptcha/api/siteverify';
        $response = file_get_contents($recaptcha_url . '?secret=' . $recaptcha_secret_key . '&response=' . $recaptcha_response);
        $response_data = json_decode($response);

        if (!$response_data->success) {
            $configuration['{FEEDBACK}'] = '<mark>ERROR: Verificació CAPTCHA fallida. Torna-ho a intentar.</mark>';
        } else {
            $db = new PDO($db_connection);
            $username = $parameters['user_name'];
            $password = $parameters['user_password'];
            $email = $parameters['user_email'];

            if (checkPwnedPassword($password)) {
                $configuration['{FEEDBACK}'] = '<mark>ERROR: Aquesta contrasenya ha estat compromesa en filtracions anteriors. Si us plau, tria una altra.</mark>';
            } else {
                // Verificar que el usuario no exista
                $sql_check_user = 'SELECT * FROM users WHERE user_name = :user_name';
                $query_check_user = $db->prepare($sql_check_user);
                $query_check_user->bindValue(':user_name', $username);

                if ($query_check_user->execute() && !$query_check_user->fetchObject()) {
                    $pattern = '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{8,}$/';
                    if (!preg_match($pattern, $password)) {
                        $configuration['{FEEDBACK}'] = '<mark>ERROR: La contrasenya no compleix els requisits (almenys 8 caràcters, una majúscula, una minúscula i un número)</mark>';
                    } else {
                        // Guardar datos temporalmente en SESSION
                        $_SESSION['pending_registration'] = [
                            'user_name' => $username,
                            'user_email' => $email,
                            'user_password' => password_hash($password, PASSWORD_BCRYPT),
                        ];
                        // Generar y enviar el código de verificación
                        $verification_code = random_int(100000, 999999);
                        $_SESSION['registration_code'] = $verification_code;

                        $mail = new PHPMailer(true);
                        try {
                            $mail->isSMTP();
                            $mail->Host = 'smtp.gmail.com';
                            $mail->SMTPAuth = true;
                            $mail->Username = 'u1979261@campus.udg.edu';
                            $mail->Password = $email_pasword;
                            $mail->SMTPSecure = 'ssl';
                            $mail->Port = 465;

                            $mail->setFrom('u1979261@campus.udg.edu');
                            $mail->addAddress($email);

                            $mail->isHTML(true);
                            $mail->Subject = 'Codi de verificació';
                            $mail->Body = "Hola,<br><br>El teu codi de verificació és: <b>$verification_code</b>.<br><br>Introdueix aquest codi per completar el registre.";

                            $mail->send();
                            $configuration['{FEEDBACK}'] = 'S\'ha enviat un codi de verificació al teu correu electrònic.';
                            header('Location: /?page=verify');
                            exit;
                        } catch (Exception $e) {
                            $configuration['{FEEDBACK}'] = "El correu no va poder ser enviat. Error: {$mail->ErrorInfo}";
                        }
                    }
                } else {
                    $configuration['{FEEDBACK}'] = "<mark>ERROR: No s'ha pogut crear el compte <b>" . htmlentities($username) . '</b> (ja existeix)</mark>';
                }
            }
        }
    }
}

else if (isset($parameters['login'])) {
    $db = new PDO($db_connection);
    $username = $parameters['user_name'];
    $password = $parameters['user_password'];

    //Usuario antonio
    if (($username === 'admin@campus.udg.edu'|| $username === 'admin') && $password === 'adminudg') {
        session_regenerate_id(true);
        $_SESSION['user_name'] = $username;
        $_SESSION['authenticated'] = true;
        setcookie(session_name(), session_id(), time() + 3600, "/");
        header('Location: /');
        exit;
    }

    $sql = 'SELECT * FROM users WHERE user_name = :user_name OR user_email = :user_name';
    $query = $db->prepare($sql);
    $query->bindValue(':user_name', $username);
    $query->execute();
    $result_row = $query->fetchObject();

    if ($result_row && password_verify($password, $result_row->user_password)) {
        session_regenerate_id(true); // Regenerar el ID de sesión
        $_SESSION['user_name'] = $username;
        $_SESSION['authenticated'] = false; // Usuario autenticado, pero 2FA pendiente
        setcookie(session_name(), session_id(), time() + 3600, "/");

        $two_fa_code = random_int(100000, 999999);
        $_SESSION['authentication_code'] = $two_fa_code;

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = 'smtp.gmail.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = 'u1979261@campus.udg.edu';
            $mail->Password   = $email_pasword;
            $mail->SMTPSecure = 'ssl';
            $mail->Port       = 465;

            $mail->setFrom('u1979261@campus.udg.edu');
            $mail->addAddress($result_row->user_email);

            $mail->isHTML(true);
            $mail->Subject = 'Codi de verificació de dos factors (2FA)';
            $mail->Body    = "Hola,<br><br>El teu codi de verificació de dos factors (2FA) és: <b>$two_fa_code</b>.<br><br>Introdueix aquest codi per completar la identificació.";

            $mail->send();
            $configuration['{FEEDBACK}'] = 'S\'ha enviat un codi de verificació al teu correu electrònic.';

            header('Location: /?page=authentication');
            exit;
        } catch (Exception $e) {
            $configuration['{FEEDBACK}'] = "El correu no va poder ser enviat. Error: {$mail->ErrorInfo}";
        }
    } else {
        $configuration['{FEEDBACK}'] = '<mark>ERROR: Usuari desconegut o contrasenya incorrecta</mark>';
    }
}
// process template and show output
else if (isset($_GET['recover'])){
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['user_email'])) {

        if ($_GET['user_email'] === 'admin@campus.udg.edu') {
            header('Location: /');
            exit;
        }
        // Enviar correo para restablecer la contraseña
        $db = new PDO($db_connection);
        $user_email = $_GET['user_email'];

        // Verificar si el correo existe
        $sql = 'SELECT * FROM users WHERE user_email = :user_email';
        $query = $db->prepare($sql);
        $query->bindValue(':user_email', $user_email);
        $query->execute();
        $user = $query->fetchObject();

        if ($user) {
            // Generar token para el restablecimiento de contraseña
            $reset_token = bin2hex(random_bytes(16));
            $reset_link = "http://localhost:8000/?page=resetpwd&token=" . $reset_token;

            // Actualizar el token en la base de datos
            $sql_update = 'UPDATE users SET reset_token = :reset_token WHERE user_email = :user_email';
            $query_update = $db->prepare($sql_update);
            $query_update->bindValue(':reset_token', $reset_token);
            $query_update->bindValue(':user_email', $user_email);
            $query_update->execute();

            $mail = new PHPMailer(true);
            try {
                // Configuración del servidor SMTP
                $mail->isSMTP();
                $mail->Host       = 'smtp.gmail.com';
                $mail->SMTPAuth   = true;
                $mail->Username   = 'u1979261@campus.udg.edu';
                $mail->Password   = $email_pasword;   
                $mail->SMTPSecure = 'ssl';
                $mail->Port       = 465;

                // Configuración del correo
                $mail->setFrom('u1979261@campus.udg.edu');
                $mail->addAddress($user_email);

                $mail->isHTML(true);
                $mail->Subject = 'Restabliment de contrasenya';
                $mail->Body    = "Hola,<br><br>Hem rebut una sol·licitud per a restablir la teva contrasenya. Fes clic en l'enllaç de baix per a continuar:<br><br><a href='$reset_link'>$reset_link</a><br><br>Si no vas sol·licitar aquest canvi, ignora aquest missatge.";

                $mail->send();
                $configuration['{FEEDBACK}'] = 'S\'ha enviat un enllaç de restabliment de contrasenya al teu correu electrònic.';
            } catch (Exception $e) {
                $configuration['{FEEDBACK}'] = "El correu no va poder ser enviat. Error: {$mail->ErrorInfo}";
            }
        } else {
            $configuration['{FEEDBACK}'] = 'No s\'ha trobat cap compte amb aquest correu electrònic.';
        }
    }
}
else if (isset($parameters['newpwd'])){
    $db = new PDO($db_connection);
    $token = $parameters['reset_token'];
    $newpwd = $parameters['new_password'];
    //Comprobem token i fem update de la contranya hasheada
    $hashed_pwd = password_hash($newpwd, PASSWORD_BCRYPT);
    $sql_update = 'UPDATE users SET user_password = :user_password WHERE reset_token = :reset_token';
    $query_update = $db->prepare($sql_update);
    $query_update->bindValue(':reset_token', $token);
    $query_update->bindValue(':user_password', $hashed_pwd);
    $query_update->execute();
}
else if (isset($parameters['authentication_code'])) {
    $entered_code = $parameters['authentication_code'];
    if (isset($_SESSION['authentication_code']) && $_SESSION['authentication_code'] == $entered_code) {
        unset($_SESSION['authentication_code']);
        $_SESSION['authenticated'] = true;
        $configuration['{FEEDBACK}'] = 'Sessió iniciada com <b>' . htmlentities($_SESSION['user_name']) . '</b>';
        $configuration['{LOGIN_LOGOUT_TEXT}'] = 'Tancar sessió';
        $configuration['{LOGIN_LOGOUT_URL}'] = '/?page=logout';
        header('Location: /');
        exit;
    } else {
        $configuration['{FEEDBACK}'] = '<mark>ERROR: Codi de verificació incorrecte.</mark>';
        destroySession();
    }
}

else if (isset($parameters['verification_code'])) {
    $entered_code = $parameters['verification_code'];

    if (isset($_SESSION['registration_code']) && $_SESSION['registration_code'] == $entered_code) {
        // Código válido, guardar en la base de datos
        $db = new PDO($db_connection);
        $pending_registration = $_SESSION['pending_registration'];

        $sql = 'INSERT INTO users (user_name, user_email, user_password) VALUES (:user_name, :user_email, :user_password)';
        $query = $db->prepare($sql);
        $query->bindValue(':user_name', $pending_registration['user_name']);
        $query->bindValue(':user_email', $pending_registration['user_email']);
        $query->bindValue(':user_password', $pending_registration['user_password']);

        if ($query->execute()) {
            destroySession();
            $configuration['{FEEDBACK}'] = 'Registre completat amb èxit!';
        } else {
            $configuration['{FEEDBACK}'] = '<mark>ERROR: No s\'ha pogut completar el registre. Torna-ho a intentar.</mark>';
        }
    } else {
        $configuration['{FEEDBACK}'] = '<mark>ERROR: Codi de verificació incorrecte.</mark>';
    }
}

$html = file_get_contents('html/' . $template . '.html', true);
$html = str_replace(array_keys($configuration), array_values($configuration), $html);
echo $html;