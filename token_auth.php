<?php
declare(strict_types=1);
/** @noinspection PhpUnhandledExceptionInspection */

use Puggan\GnuCashMatcher\Auth;

require_once __DIR__ . '/vendor/autoload.php';

$authError = null;
if (!empty($_POST) && !cookieTokenAuth()) {
    if (empty($_POST['device'])) {
        $authError = 'Missing device';
    } elseif (empty($_POST['user'])) {
        $authError = 'Missing user';
    } elseif (empty($_POST['password'])) {
        $authError = 'Missing password';
    } else {
        $auth = new Auth();
        if (!$auth->verify($_POST['user'], $_POST['password'])) {
            $authError = 'Bad password';
        } else {
            try {
                $token = base64_encode(random_bytes(20));
            } catch (\Exception $exception) {
                throw new \RuntimeException($exception->getMessage(), $exception->getCode(), $exception);
            }
            cookie_add_auth($token, $_POST['device'], $_POST['user'], 1);
            setcookie('auth_token', $token, strtotime('+1 year'), '/', '', true, true);
        }
    }
}
if (!cookieTokenAuth()) {
    if ($authError) {
        $authError = '<div class="error">' . htmlentities($authError) . '</div>';
    }
    echo <<<HTML_BLOCK
<html>
	<head>
		<title>403 - Login</title>
		<style>
			label {
				display: block;
				margin-bottom: 1em;
			}
		</style>
	</head>
	<body>
		<h1>403 - Login</h1>
		{$authError}
		<form method='post'>
			<label>
				<span>Device</span><br/>
				<input name="device" />
			</label>
			<label>
				<span>User</span><br/>
				<input name="user" />
			</label>
			<label>
				<span>Password</span><br/>
				<input name="password" type="password" />
			</label>
			<label>
				<input value="Login" type="submit" />
			</label>
		</form>
	</body>
</html>
HTML_BLOCK;

    header('HTTP/1.1 403 Login');
    die(PHP_EOL);
}

/**
 * @return bool
 */
function cookieTokenAuth()
{
    if (PHP_SAPI === 'cli') {
        return true;
    }

    if (empty($_COOKIE['auth_token'])) {
        return false;
    }

    $database = Auth::newDatabase();
    if (!$database) {
        return false;
    }

    return (bool) $database->get('SELECT auth_level FROM cookies WHERE token = ' . $database->quote($_COOKIE['auth_token']));
}

/**
 * @param $token
 * @param $device
 * @param $user
 * @param $level
 * @return false|int|string
 */
function cookie_add_auth($token, $device, $user, $level)
{
    $database = Auth::newDatabase();
    $values = [
        'auth_level = ' . (int) $level,
        'device = ' . $database->quote($device),
        'token = ' . $database->quote($token),
        'user = ' . $database->quote($user),
    ];
    return $database->insert('INSERT INTO cookies SET ' . implode(', ', $values));
}
