<?php
declare(strict_types=1);

require_once __DIR__ . '/../Interfaces/BankTransaction.php';
require_once __DIR__ . '/../Interfaces/Split.php';

namespace Puggan\GnuCashMatcher\Models\Combined;

use Puggan\GnuCashMatcher\Models\Interfaces\BankTransaction;
use Puggan\GnuCashMatcher\Models\Interfaces\Split;

/**
 * Class table_db_result_row_value_date_description_guid
 * @property string row
 * @property string date
 * @property string description
 * @property string other_account
 */
class BankTransactionMatchingSplits implements Split
{
    /**
     * @param \Puggan\GnuCashMatcher\DB $db
     * @param BankTransaction $bankTransaction
     * @return self[]
     */
    public function list(\Puggan\GnuCashMatcher\DB $db, BankTransaction $bankTransaction): array
    {
        $query = <<<SQL_BLOCK
SELECT
    splits.*,
    splits.value_num/splits.value_denom as value,
    splits.quantity_num/splits.quantity_denom as quantity,
	bank_transactions.bank_t_row AS row,
	transactions.post_date AS date,
	transactions.description,
	COALESCE(account2.name, '') AS other_account
FROM bank_transactions
	INNER JOIN accounts ON (accounts.code = bank_transactions.account)
	INNER JOIN splits ON (splits.account_guid = accounts.guid)
	INNER JOIN transactions ON (transactions.guid = splits.tx_guid)
	LEFT JOIN bank_transactions AS used ON (used.bank_tid = splits.guid)
	LEFT JOIN splits AS split2 ON (split2.tx_guid = splits.tx_guid AND split2.value_num = -splits.value_num)
	LEFT JOIN accounts AS account2 ON (account2.guid = split2.account_guid)
WHERE bank_transactions.bank_t_row = {$bankTransaction->bank_t_row}
	AND used.bank_t_row IS NULL
	AND transactions.post_date BETWEEN bank_transactions.bdate - INTERVAL 1 WEEK AND bank_transactions.bdate + INTERVAL 1 WEEK
	AND splits.value_num - bank_transactions.amount * splits.value_denom BETWEEN -100 AND 100
GROUP BY
    splits.guid,
    splits.value_denom,
    splits.value_num,
    transactions.post_date,
    bank_transactions.amount,
    bank_transactions.bdate         
ORDER BY ABS(splits.value_num - bank_transactions.amount * splits.value_denom),
	ABS(UNIX_TIMESTAMP(transactions.post_date) - UNIX_TIMESTAMP(bank_transactions.bdate))
SQL_BLOCK;

        // TODO move to models
        /** @var self[] $list */
        $list = $db->objects($query, 'guid', self::class);
        return $list;
    }
}
