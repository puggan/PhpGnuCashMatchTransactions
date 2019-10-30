<?php
declare(strict_types=1);

namespace Models;

/**
 * Class Account
 * @package Models
 */
class Account implements Interfaces\Account
{
    /**
     * @param \db $db
     * @param string $guid
     *
     * @return self|null
     */
    public static function find(\db $db, string $guid): ?self
    {
        $query = 'SELECT * FROM accounts WHERE guid = ' . $db->quote($guid);

        /** @var self $account */
        $account = $db->object($query, null, self::class);
        return $account;
    }

    /**
     * @param \db $db
     * @param string|null $index
     * @return array
     */
    public static function all(\db $db, $index): array
    {
        /** @var self[] $accounts */
        $accounts = $db->objects('SELECT * FROM accounts', $index, self::class);
        return $accounts;
    }

    /**
     * @param \db $db
     * @param int $minLength
     * @return self[]
     */
    public static function listCodes(\db $db, int $minLength = 4): array
    {
        /** @var self[] $accounts */
        $accounts = $db->objects('SELECT * FROM accounts WHERE LENGTH(code) >= ' . $minLength, 'code', self::class);
        return $accounts;
    }

    /**
     * @param \db $db
     * @param int $minLength
     * @return string[]
     */
    public static function codeNames(\db $db, int $minLength = 4): array
    {
        /** @var self[] $accounts */
        $accounts = $db->read('SELECT * FROM accounts WHERE LENGTH(code) >= ' . $minLength, 'code', 'name');
        return $accounts;
    }
}
