<?php

class Auth {

    // *** app credentials ***
    private $sAppPassword = array(
        'guest' => '$2y$10$jax5/KKZPcFknUqY4pp5n.MlKTKLsw98abMulfzXMRP5ukr3RHAzu', // password_hash("guest", PASSWORD_DEFAULT))
    );

    // *** Database credentials ***
    private $sUsername = 'gnucash';
    private $sPassword = 'gnucash';
    private $sDatabase = 'gnucash';
    private $sDatabaseServer = 'localhost';
    // ***
}
