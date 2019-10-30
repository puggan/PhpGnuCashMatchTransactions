<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/GnuCash.php';

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
    protected $sAppPassword;

    // *** Database credentials ***
    protected $sUsername;
    protected $sPassword;
    protected $sDatabase;
    protected $sDatabaseServer;
    protected $sPort;

    public function __construct()
    {
        global $secrets;
        $this->sAppPassword = $secrets->passwords;
        $this->sUsername = $secrets->db->username;
        $this->sPassword = $secrets->db->password;
        $this->sDatabase = $secrets->db->database;
        $this->sDatabaseServer = $secrets->db->server;
        $this->sPort = $secrets->db->port;
    }
    // ***

    /**
     * @return \Puggan\GnuCashMatcher\DB
     */
    public function db(): \Puggan\GnuCashMatcher\DB
    {
        return new \Puggan\GnuCashMatcher\DB($this->sDatabase, $this->sUsername, $this->sPassword, $this->sDatabaseServer, $this->sPort);
    }

    /**
     * @return \Puggan\GnuCashMatcher\DB
     */
    public static function new_db(): \Puggan\GnuCashMatcher\DB
    {
        return (new self())->db();
    }

    /**
     * @return GnuCash
     */
    public function gnucash(): \GnuCash
    {
        return new GnuCash($this->sDatabaseServer, $this->sDatabase, $this->sUsername, $this->sPassword);
    }

    /**
     * @return GnuCash
     */
    public static function new_gnucash(): \GnuCash
    {
        return (new self())->gnucash();
    }

    /**
     * @param string $user
     * @param string $password
     *
     * @return bool
     */
    public function verify($user, $password): bool
    {
        return isset($this->sAppPassword[$user]) && password_verify($password, $this->sAppPassword[$user]);
    }
}
