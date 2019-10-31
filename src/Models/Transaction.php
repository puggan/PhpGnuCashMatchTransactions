<?php
declare(strict_types=1);

require_once __DIR__ . '/Interfaces/Split.php';

namespace Puggan\GnuCashMatcher\Models;

use Puggan\GnuCashMatcher\DB;

/**
 * Class Transactions
 * @package Models
 */
class Transaction implements Interfaces\Transactions
{
    /**
     * @param DB $db
     * @param string $guid
     *
     * @return self|null
     */
    public static function find(DB $db, string $guid): ?self
    {
        $query = 'SELECT * FROM transactions WHERE guid = ' . $db->quote($guid);

        /** @var self $split */
        $split = $db->object($query, null, self::class);
        return $split;
    }
}
