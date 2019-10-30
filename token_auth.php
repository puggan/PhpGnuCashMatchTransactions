<?php

	require_once(__DIR__ . "/auth.php");

	$auth_error = NULL;
	if(!cookie_token_auth() && $_POST) {
		if(empty($_POST['device'])) {
			$auth_error = 'Missing device';
		}
		else if(empty($_POST['user'])) {
			$auth_error = 'Missing user';
		}
		else if(empty($_POST['password'])) {
			$auth_error = 'Missing password';
		}
		else {
			$auth = new Auth();
			if(!$auth->verify($_POST['user'], $_POST['password']))
			{
				$auth_error = 'Bad password';
			}
			else {
				$token = base64_encode(random_bytes(20));
				cookie_add_auth($token, $_POST['device'], $_POST['user'], 1);
				setcookie('auth_token', $token, strtotime('+1 year'));
			}
		}
	}
	if(!cookie_token_auth()) {
		if($auth_error) {
			$auth_error = '<div class="error">' . htmlentities($auth_error) . '</div>';
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
		{$auth_error}
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

		header("HTTP/1.1 403 Login");
		die(PHP_EOL);
	}

	function cookie_token_auth()
	{
		if(php_sapi_name() === 'cli') return true;

		if(empty($_COOKIE['auth_token'])) return false;

		$db = Auth::new_db();
		if(!$db) return false;

		return (bool) $db->get("SELECT auth_level FROM cookies WHERE token = " . $db->quote($_COOKIE['auth_token']));
	}

	function cookie_add_auth($token, $device, $user, $level)
	{
		$db = Auth::new_db();
		$sl = [
			'auth_level = ' . (int) $level,
			'device = ' . $db->quote($device),
			'token = ' . $db->quote($token),
			'user = ' . $db->quote($user),
		];
		return $db->insert("INSERT INTO cookies SET " . implode(', ', $sl));
	}
