<?php
declare(strict_types=1);

namespace Models;

/**
 * Class Transactions
 * @package Models
 */
class Transaction implements Interfaces\Transactions
{
    /**
     * @param \db $db
     * @param string $guid
     *
     * @return self|null
     */
    public static function find(\db $db, string $guid): ?self
    {
        $query = 'SELECT * FROM transactions WHERE guid = ' . $db->quote($guid);

        /** @var self $split */
        $split = $db->object($query, null, self::class);
        return $split;
    }
}
