<?php
declare(strict_types=1);

namespace Models;
/**
 * Class Split
 * @package Models
 */
class Split implements Interfaces\Split
{
    /**
     * @param \db $db
     * @param string $guid
     *
     * @return self|null
     */
    public static function find(\db $db, string $guid): ?self
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
