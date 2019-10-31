<?php
declare(strict_types=1);

namespace Puggan\GnuCashMatcher\Models;

use Puggan\GnuCashMatcher\Models\Combined\BankTransactionMatchingAcconts;
use Puggan\GnuCashMatcher\Models\Combined\BankTransactionMatchingSplits;

/**
 * Class table_bank_transactions
 */
class BankTransaction implements Interfaces\BankTransaction
{
    /**
     * @param \Puggan\GnuCashMatcher\DB $db
     * @param int $rowNr
     *
     * @return self|null
     */
    public static function find($db, $rowNr): ?self
    {
        $rowNr = (int) $rowNr;
        $query = "SELECT * FROM bank_transactions WHERE bank_t_row = {$rowNr}";
        /** @var self $transaction */
        $transaction = $db->object($query, null, self::class);
        return $transaction;
    }

    /**
     * @param \Puggan\GnuCashMatcher\DB $db
     *
     * @return BankTransactionMatchingSplits[]
     */
    public function matchingTxs($db)
    {
        return BankTransactionMatchingSplits::list($db, $this);
    }

    /**
     * @param \Puggan\GnuCashMatcher\DB $db
     *
     * @return BankTransactionMatchingAcconts[]
     */
    public function matchingRows($db)
    {
        return BankTransactionMatchingAcconts::list($db, $this);
    }

    /**
     * @param \Puggan\GnuCashMatcher\DB $db
     */
    public function saveCache($db): void
    {
        $data = (array) $this;
        ksort($data);
        $data['matches']['tx'] = $this->matchingTxs($db);
        $data['matches']['rows'] = $this->matchingRows($db);

        if (preg_match('#/(\d\d-\d\d-\d\d)$#', $data['vtext'], $matches)) {
            $data['vtext'] = trim(substr($data['vtext'], 0, -9));
            $data['bdate'] = 20 . $matches[1];
        }

        $data = json_encode($data);
        $hash = md5($data);
        $query = "SELECT md5 FROM bank_transactions_cache WHERE bank_t_row = {$this->bank_t_row}";
        $oldHash = $db->get($query);
        if(hash_equals($oldHash, $hash)) {
            $query = "UPDATE bank_transactions_cache SET verified_at = NOW(), revalidate = 0 WHERE bank_t_row = {$this->bank_t_row}";
        } else {
            $data = $db->quote($data);
            $hash = $db->quote($hash);
            $query = <<<SQL_BLOCK
REPLACE INTO bank_transactions_cache
SET bank_t_row = {$this->bank_t_row},
	updated_at = NOW(),
	verified_at = NOW(),
	revalidate = 0,
	md5 = {$hash},
	data = {$data}
SQL_BLOCK;
        }
        $db->write($query);
    }

    public function add(\Puggan\GnuCashMatcher\DB $db)
    {
        $query = <<<SQL_BLOCK
INSERT INTO bank_transactions SET
    bdate = {$db->quote($this->bdate)},
    vdate = {$db->quote($this->vdate)},
    vnr = {$db->quote($this->vnr)},
    vtext = {$db->quote($this->vtext)},
    amount = {$db->quote($this->amount)},
    saldo = {$db->quote($this->saldo)},
    account = {$db->quote($this->account)}
SQL_BLOCK;
        return $db->insert($query);
    }
}
