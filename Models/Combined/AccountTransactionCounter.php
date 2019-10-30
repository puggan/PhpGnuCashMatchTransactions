<?php
declare(strict_types=1);

namespace Models\Combined;

use Models\Interfaces\Account;

/**
 * Class MissingTransactionsAccounts
 * @package Models\Combined
 * @property string account Depricated, use (accounts.)code
 * @property string account_guid Depricated, use (accounts.)guid
 * @property string|null f_date from/min date
 * @property string|null t_date to/max date
 * @property int erows Depricated, count of transactions
 * @property int count count of transactions
 */
class AccountTransactionCounter implements Account
{
    /**
     * @param \db $db
     * @param string $accountCode
     * @param string $startDate
     * @return self|null
     */
    public static function listMissing(\db $db, string $accountCode, string $startDate = '2016-09-01'): ?self
    {
        $startDateSql = $db->quote($startDate);
        $accountCodeSql = $db->quote($accountCode);
        $query = <<<SQL_BLOCK
SELECT
    accounts.*,
	accounts.guid AS account_guid
	bank_transactions.account,
	MIN(IF(bank_tid IS NULL, NULL, bank_transactions.bdate)) AS f_date,
	MAX(IF(bank_tid IS NULL, NULL, bank_transactions.bdate)) AS t_date,
	COUNT(DISTINCT IF(bank_tid IS NULL, bank_transactions.bank_t_row, NULL)) AS erows,
	COUNT(DISTINCT IF(bank_tid IS NULL, bank_transactions.bank_t_row, NULL)) AS count,
FROM bank_transactions
	INNER JOIN accounts ON (accounts.code = bank_transactions.account)
WHERE bdate >= {$startDateSql}
	AND accounts.code = {$accountCodeSql}
SQL_BLOCK;

        /** @var self $account */
        $account = $db->object($query, false, self::class);
        return $account;
    }
}
