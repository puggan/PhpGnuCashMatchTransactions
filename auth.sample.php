<?php

	// Remove this line
	namespace Demo;

	require_once(__DIR__ . "/db.php");

	/**
	 * Class Auth
	 * @property string[] $sAppPassword
	 * @property string $sUsername
	 * @property string $sPassword
	 * @property string $sDatabase
	 * @property string $sDatabaseServer
	 * @property null|int $sPort
	 */
	class Auth
	{

		// *** app credentials ***
		private $sAppPassword = array(
			'guest' => '$2y$10$jax5/KKZPcFknUqY4pp5n.MlKTKLsw98abMulfzXMRP5ukr3RHAzu', // password_hash("guest", PASSWORD_DEFAULT))
		);

		// *** Database credentials ***
		protected $sUsername = 'gnucash';
		protected $sPassword = 'gnucash';
		protected $sDatabase = 'gnucash';
		protected $sDatabaseServer = 'localhost';
		protected $sPort = NULL;

		// ***

		public function db()
		{
			return new db($this->sDatabase, $this->sUsername, $this->sPassword, $this->sDatabaseServer, $this->sPort);
		}

		public static function new_db()
		{
			return (new Auth())->db();
		}
	}
