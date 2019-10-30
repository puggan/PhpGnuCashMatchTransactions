<?php

namespace Models\Combined;

use Models\Interfaces\Account;
use Models\Interfaces\BankTransaction;

/**
 * Class table_db_result_row_text_match
 * @property string connections
 * @property int|float amount_from
 * @property int|float amount_to
 * @property string date_from
 * @property string date_to
 */
class BankTransactionMatchingAcconts implements Account
{
    /**
     * @param \db $db
     * @param BankTransaction $bankTransaction
     * @return self[]
     */
    public function list(\db $db, BankTransaction $bankTransaction): array
    {
        $query = <<<SQL_BLOCK
SELECT
	accounts.*,
	COUNT(t2.amount) AS 'connections',
	MIN(ABS(t2.amount)) AS 'amount_from',
	MAX(ABS(t2.amount)) AS 'amount_to',
	MIN(DATE(transactions.post_date)) AS 'date_from',
	MAX(DATE(transactions.post_date)) AS 'date_to'
FROM bank_transactions AS t1
	INNER JOIN bank_transactions AS t2 ON (
		IF(t2.vtext RLIKE '/[0-9][0-9]-[0-9][0-9]-[0-9][0-9]$', SUBSTRING(t2.vtext, 1, LENGTH(t2.vtext) - 9), t2.vtext)
		=
		IF(t1.vtext RLIKE '/[0-9][0-9]-[0-9][0-9]-[0-9][0-9]$', SUBSTRING(t1.vtext, 1, LENGTH(t1.vtext) - 9), t1.vtext)
	)
	INNER JOIN splits ON (splits.guid = t2.bank_tid)
	INNER JOIN transactions ON (transactions.guid = splits.tx_guid)
	INNER JOIN splits AS s2 ON (s2.tx_guid = transactions.guid AND s2.guid <> splits.guid)
	INNER JOIN accounts ON (accounts.guid = s2.account_guid)
WHERE t1.bank_t_row = {$bankTransaction->bank_t_row}
GROUP BY accounts.guid
ORDER BY
	IF({$bankTransaction->amount} BETWEEN MIN(t2.amount) AND MAX(t2.amount), 0, 1),
	COUNT(t2.amount) DESC,
	MAX(DATE(transactions.post_date)) DESC
SQL_BLOCK;

        /** @var self[] $list */
        $list = $db->objects($query, 'code', self::class);
        return $list;
    }
}
