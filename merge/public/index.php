<?php

use Dotenv\Dotenv;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;


require '../../PHPMailer/src/Exception.php';
require '../../PHPMailer/src/PHPMailer.php';
require '../../PHPMailer/src/SMTP.php';
require '../../vendor/autoload.php';

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

$is_logged_in = false;

$AUTH_PAGES = ["register", "login", "game", "resetpwd"];

if (isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true) 
    $is_logged_in = true;


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
$configuration['{FEEDBACK}'] = "sessio: " . implode(",", $_SESSION) . $is_logged_in;

if ($is_logged_in === false) {
    if (isset($_GET['page'])) {
        $page = $_GET['page'];

        foreach ($AUTH_PAGES as $p) {
            ;
        }
    }
}

if (isset($_GET['page'])) {
    if ($_GET['page'] == 'register') {
        if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
            $template = 'register';
            $configuration['{REGISTER_USERNAME}'] = '';
            $configuration['{LOGIN_LOGOUT_TEXT}'] = 'Ja tinc un compte';
        }
        $configuration['{FEEDBACK}'] = 'Ja has iniciat la sessió com <b>' . htmlentities($_SESSION['user_name']) . '</b>.';
    } else if ($_GET['page'] == 'login') {
        if (!isset($_SESSION['user_name'])) {
            $template = 'login';
            $configuration['{LOGIN_USERNAME}'] = '';
        } else if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
            $template = 'authentication';
        } else {
            $configuration['{FEEDBACK}'] = 'Ja has iniciat la sessió com <b>' . htmlentities($_SESSION['user_name']) . '</b>.';
            $configuration['{LOGIN_LOGOUT_TEXT}'] = 'Tancar sessió';
            $configuration['{LOGIN_LOGOUT_URL}'] = '/?page=logout';
        }
    } else if ($_GET['page'] == 'lostpwd') {
        $template = 'lostpwd';
    } else if ($_GET['page'] == 'resetpwd') {
        $template = 'resetpwd';
    } else if ($_GET['page'] == 'authentication') {
        $template = 'authentication';
    } else if ($_GET['page'] == 'game') {
        $template = 'game';
    }
    else if ($_GET['page'] == 'logout') {
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
            if (checkPwnedPassword($password)) {
                $configuration['{FEEDBACK}'] = '<mark>ERROR: Aquesta contrasenya ha estat compromesa en filtracions anteriors. Si us plau, tria una altra.</mark>';
            }
            else{
                // Mirar que l'usuari no existeixi a la base de dades
                $sql_check_user = 'SELECT * FROM users WHERE user_name = :user_name';
                $query_check_user = $db->prepare($sql_check_user);
                $query_check_user->bindValue(':user_name', $username);

                if ($query_check_user->execute() && !$query_check_user->fetchObject()) {

                    $pattern = '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{8,}$/';
                    if (!preg_match($pattern, $password)) {
                        $configuration['{FEEDBACK}'] = '<mark>ERROR: La contrasenya no compleix els requisits (almenys 8 caràcters, una majúscula, una minúscula i un número)</mark>';
                    }
                    else{       
                        $hashed_pwd = password_hash($password, PASSWORD_BCRYPT);
        
                        $sql = 'INSERT INTO users (user_name,user_email, user_password) VALUES (:user_name,:user_email, :user_password)';
                        $query = $db->prepare($sql);
                        $query->bindValue(':user_name', $username);
                        $query->bindValue(':user_password', $hashed_pwd);
                        $query->bindValue(':user_email', $parameters['user_email']);
        
                        if ($query->execute()) {
                            $configuration['{FEEDBACK}'] = 'Creat el compte <b>' . htmlentities($username) . '</b>';
                            $configuration['{LOGIN_LOGOUT_TEXT}'] = 'Tancar sessió';
                        }
                    }
                } 
                else {
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

            // Configurar y enviar correo con PHPMailer
            $mail = new PHPMailer(true);
            try {
                // Configuración del servidor SMTP
                $mail->isSMTP();
                $mail->Host       = 'smtp.gmail.com'; // Cambia por tu servidor SMTP
                $mail->SMTPAuth   = true;
                $mail->Username   = 'u1979261@campus.udg.edu'; // Cambia por tu correo
                $mail->Password   = $email_pasword;    // Cambia por tu contraseña de correo
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


$html = file_get_contents('html/' . $template . '.html', true);
$html = str_replace(array_keys($configuration), array_values($configuration), $html);
echo $html;
