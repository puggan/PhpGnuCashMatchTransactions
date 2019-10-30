<?php

namespace Models;

use db;
use table_db_result_row_text_match;
use table_db_result_row_value_date_description_guid;

/**
 * Class table_bank_transactions
 *
 * @property string bdate
 * @property string vdate
 * @property int vnr
 * @property string vtext
 * @property float amount
 * @property float saldo
 * @property string account
 * @property string bank_tid
 * @property int bank_t_row
 */
class Transaction
{
    /**
     * @param db $db
     * @param int $row_nr
     *
     * @return mixed
     */
    public static function find($db, $row_nr)
    {
        $row_nr = (int) $row_nr;
        $query = "SELECT * FROM bank_transactions WHERE bank_t_row = {$row_nr}";
        return $db->object($query, null, self::class);
    }

    /**
     * @param db $db
     *
     * @return table_db_result_row_value_date_description_guid[]
     */
    public function list_matching_txs($db)
    {
        $query = <<<SQL_BLOCK
SELECT
	bank_transactions.bank_t_row AS row,
	splits.value_num / splits.value_denom AS value,
	transactions.post_date AS date,
	transactions.description,
	splits.guid,
	COALESCE(account2.name, '') AS other_account
FROM bank_transactions
	INNER JOIN accounts ON (accounts.code = bank_transactions.account)
	INNER JOIN splits ON (splits.account_guid = accounts.guid)
	INNER JOIN transactions ON (transactions.guid = splits.tx_guid)
	LEFT JOIN bank_transactions AS used ON (used.bank_tid = splits.guid)
	LEFT JOIN splits AS split2 ON (split2.tx_guid = splits.tx_guid AND split2.value_num = -splits.value_num)
	LEFT JOIN accounts AS account2 ON (account2.guid = split2.account_guid)
WHERE bank_transactions.bank_t_row = {$this->bank_t_row}
	AND used.bank_t_row IS NULL
	AND transactions.post_date BETWEEN bank_transactions.bdate - INTERVAL 1 WEEK AND bank_transactions.bdate + INTERVAL 1 WEEK
	AND splits.value_num - bank_transactions.amount * splits.value_denom BETWEEN -100 AND 100
GROUP BY splits.guid
ORDER BY ABS(splits.value_num - bank_transactions.amount * splits.value_denom),
	ABS(UNIX_TIMESTAMP(transactions.post_date) - UNIX_TIMESTAMP(bank_transactions.bdate))
SQL_BLOCK;

        return $db->objects($query, 'guid', table_db_result_row_value_date_description_guid::class);
    }

    /**
     * @param db $db
     *
     * @return table_db_result_row_text_match[]
     */
    public function list_matching_rows($db)
    {
        $query = <<<SQL_BLOCK
SELECT
	accounts.code,
	accounts.name,
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
WHERE t1.bank_t_row = {$this->bank_t_row}
GROUP BY accounts.guid
ORDER BY
	IF({$this->amount} BETWEEN MIN(t2.amount) AND MAX(t2.amount), 0, 1),
	COUNT(t2.amount) DESC,
	MAX(DATE(transactions.post_date)) DESC
SQL_BLOCK;

        return $db->objects($query, 'code', table_db_result_row_text_match::class);
    }

    /**
     * @param db $db
     */
    public function save_cache($db)
    {
        $data = (array) $this;
        ksort($data);
        $data['matches']['tx'] = $this->list_matching_txs($db);
        $data['matches']['rows'] = $this->list_matching_rows($db);

        if (preg_match("#/([0-9][0-9]-[0-9][0-9]-[0-9][0-9])$#", $data['vtext'], $m)) {
            $data['vtext'] = trim(substr($data['vtext'], 0, -9));
            $data['bdate'] = 20 . $m[1];
        }

        $data = json_encode($data);
        $md5 = md5($data);
        $query = "SELECT md5 FROM bank_transactions_cache WHERE bank_t_row = {$this->bank_t_row}";
        $old_md5 = $db->get($query);
        if ($old_md5 == $md5) {
            $query = "UPDATE bank_transactions_cache SET verified_at = NOW(), revalidate = 0 WHERE bank_t_row = {$this->bank_t_row}";
        } else {
            $data = $db->quote($data);
            $md5 = $db->quote($md5);
            $query = <<<SQL_BLOCK
REPLACE INTO bank_transactions_cache
SET bank_t_row = {$this->bank_t_row},
	updated_at = NOW(),
	verified_at = NOW(),
	revalidate = 0,
	md5 = {$md5},
	data = {$data}
SQL_BLOCK;
        }
        $db->write($query);
    }
}
