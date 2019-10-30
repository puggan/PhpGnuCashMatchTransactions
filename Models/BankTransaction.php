<?php

namespace Models;


use Models\Combined\BankTransactionMatchingAcconts;
use Models\Combined\BankTransactionMatchingSplits;

/**
 * Class table_bank_transactions
 */
class BankTransaction implements Interfaces\BankTransaction
{
    /**
     * @param \db $db
     * @param int $row_nr
     *
     * @return self|null
     */
    public static function find($db, $row_nr): ?self
    {
        $row_nr = (int) $row_nr;
        $query = "SELECT * FROM bank_transactions WHERE bank_t_row = {$row_nr}";
        /** @var self $transaction */
        $transaction = $db->object($query, null, self::class);
        return $transaction;
    }

    /**
     * @param \db $db
     *
     * @return BankTransactionMatchingSplits[]
     */
    public function list_matching_txs($db)
    {
        return BankTransactionMatchingSplits::list($db, $this);
    }

    /**
     * @param \db $db
     *
     * @return BankTransactionMatchingAcconts[]
     */
    public function list_matching_rows($db)
    {
        return BankTransactionMatchingAcconts::list($db, $this);
    }

    /**
     * @param \db $db
     */
    public function save_cache($db): void
    {
        $data = (array) $this;
        ksort($data);
        $data['matches']['tx'] = $this->list_matching_txs($db);
        $data['matches']['rows'] = $this->list_matching_rows($db);

        if (preg_match('#/(\d\d-\d\d-\d\d)$#', $data['vtext'], $m)) {
            $data['vtext'] = trim(substr($data['vtext'], 0, -9));
            $data['bdate'] = 20 . $m[1];
        }

        $data = json_encode($data);
        $md5 = md5($data);
        $query = "SELECT md5 FROM bank_transactions_cache WHERE bank_t_row = {$this->bank_t_row}";
        $old_md5 = $db->get($query);
        if(hash_equals($old_md5, $md5)) {
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
