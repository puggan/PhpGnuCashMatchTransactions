<?php
declare(strict_types=1);

namespace Puggan\GnuCashMatcher\Models;
/**
 * Class Split
 * @package Models
 */
class Split implements Interfaces\Split
{
    /**
     * @param \Puggan\GnuCashMatcher\DB $db
     * @param string $guid
     *
     * @return self|null
     */
    public static function find(\Puggan\GnuCashMatcher\DB $db, string $guid): ?self
    {
        $primaryKey = $db->quote($guid);
        $query = <<<SQL_BLOCK
SELECT
       splits.*,
       value_num/value_denom as value,
       quantity_num/quantity_denom as quantity
FROM splits
WHERE guid = {$primaryKey}
SQL_BLOCK;

        /** @var self $split */
        $split = $db->object($query, null, self::class);
        return $split;
    }
}
