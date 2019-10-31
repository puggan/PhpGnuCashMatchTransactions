<?php
declare(strict_types=1);

namespace Puggan\GnuCashMatcher\Models;

/**
 * Class Account
 * @package Models
 */
class Account implements Interfaces\Account
{
    /**
     * @param \Puggan\GnuCashMatcher\DB $db
     * @param string $guid
     *
     * @return self|null
     */
    public static function find(\Puggan\GnuCashMatcher\DB $db, string $guid): ?self
    {
        $query = 'SELECT * FROM accounts WHERE guid = ' . $db->quote($guid);

        /** @var self $account */
        $account = $db->object($query, null, self::class);
        return $account;
    }

    /**
     * @param \Puggan\GnuCashMatcher\DB $db
     * @param string|null $index
     * @return array
     */
    public static function all(\Puggan\GnuCashMatcher\DB $db, $index): array
    {
        /** @var self[] $accounts */
        $accounts = $db->objects('SELECT * FROM accounts', $index, self::class);
        return $accounts;
    }

    /**
     * @param \Puggan\GnuCashMatcher\DB $db
     * @param int $minLength
     * @return self[]
     */
    public static function listCodes(\Puggan\GnuCashMatcher\DB $db, int $minLength = 4): array
    {
        /** @var self[] $accounts */
        $accounts = $db->objects('SELECT * FROM accounts WHERE LENGTH(code) >= ' . $minLength, 'code', self::class);
        return $accounts;
    }

    /**
     * @param \Puggan\GnuCashMatcher\DB $db
     * @param int $minLength
     * @return string[]
     */
    public static function codeNames(\Puggan\GnuCashMatcher\DB $db, int $minLength = 4): array
    {
        /** @var self[] $accounts */
        $accounts = $db->read('SELECT * FROM accounts WHERE LENGTH(code) >= ' . $minLength, 'code', 'name');
        return $accounts;
    }
}
