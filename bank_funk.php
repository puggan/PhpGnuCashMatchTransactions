<?php /** @noinspection PhpIllegalPsrClassPathInspection */
/** @noinspection PhpIllegalPsrClassPathInspection */
/** @noinspection PhpClassNamingConventionInspection */
/** @noinspection AutoloadingIssuesInspection */
/** @noinspection AutoloadingIssuesInspection */
declare(strict_types=1);

use Models\Account;
use Models\BankTransaction;

require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/GnuCash.php';
require_once __DIR__ . '/Models/BankTransaction.php';

/**
 * Class Bank_interface
 *
 * @property db $db
 * @property GnuCash gc
 */
class Bank_interface
{
    private $db;
    private $gc;

    /**
     * Bank_interface constructor.
     */
    public function __construct()
    {
        $this->db = Auth::new_db();
        $this->gc = Auth::new_gnucash();
    }

    /**
     * @return db
     */
    public function db(): \db
    {
        return $this->db;
    }

    /**
     * @param int $row_nr
     * @param string $guid
     *
     * @return bool
     * @throws Exception
     */
    public function connect_bank_row($row_nr, $guid): bool
    {
        $bank_row = $this->get_bank_row($row_nr);
        if (!$bank_row) {
            throw new \RuntimeException('row dosn\'t exisists');
        }
        if ($bank_row->bank_tid) {
            throw new \RuntimeException('row allredy connected');
        }

        $guid = $this->db->quote($guid);
        $row_nr = (int) $row_nr;
        $query = "UPDATE bank_transactions SET bank_tid = {$guid} WHERE bank_tid IS NULL AND bank_t_row = {$row_nr}";
        return $this->db->write($query);
    }

    /**
     * @param int $row_nr
     *
     * @return BankTransaction
     */
    public function get_bank_row($row_nr): BankTransaction
    {
        $row_nr = (int) $row_nr;
        $query = "SELECT * FROM bank_transactions WHERE bank_t_row = {$row_nr}";
        /** @var BankTransaction $transaction */
        $transaction = $this->db->object($query, null, BankTransaction::class);
        return $transaction;
    }

    /**
     * @param int $row_nr
     * @param string $date
     * @param int $amount
     * @param string $from
     * @param string $to
     * @param string $text
     *
     * @return bool
     * @throws Exception
     * @noinspection PhpTooManyParametersInspection TODO move to constructor
     */
    public function add_from_bank_row($row_nr, $date, $amount, $from, $to, $text): bool
    {
        if (!$row_nr) {
            throw new \RuntimeException('invalid parameters row_nr to add_from_bank_row()');
        }
        if (!$date) {
            throw new \RuntimeException('invalid parameters date to add_from_bank_row()');
        }
        if (!$amount) {
            throw new \RuntimeException('invalid parameters amount to add_from_bank_row()');
        }
        if (!$from) {
            throw new \RuntimeException('invalid parameters to add_from_bank_row()');
        }
        if (!$to) {
            throw new \RuntimeException('invalid parameters to add_from_bank_row()');
        }
        if (!$text) {
            throw new \RuntimeException('invalid parameters to add_from_bank_row()');
        }

        if (!$this->gc->GUIDExists($from)) {
            throw new \RuntimeException('from account dosn\'t exists');
        }
        if (!$this->gc->GUIDExists($to)) {
            throw new \RuntimeException('to account dosn\'t exists');
        }

        $bank_row = $this->get_bank_row($row_nr);
        if (!$bank_row) {
            throw new \RuntimeException('row dosn\'t exisists');
        }
        if ($bank_row->bank_tid) {
            throw new \RuntimeException('row allready connected');
        }

        $error = $this->gc->createTransaction($to, $from, $amount, $text, $date, '');
        if ($error) {
            throw new \RuntimeException($error);
        }

        if (!$this->gc->lastTxGUID) {
            throw new \RuntimeException('No new guid generated');
        }

        $splits = $this->tx_to_account_splits($this->gc->lastTxGUID);

        if (empty($splits[$bank_row->account])) {
            throw new \RuntimeException('account not found on tx');
        }

        return $this->connect_bank_row($row_nr, $splits[$bank_row->account]);
    }

    /**
     * @param string $tx
     *
     * @return string[] account.code -> split.guid
     * @throws Exception
     */
    public function tx_to_account_splits($tx): array
    {
        $tx = $this->db->quote($tx);
        $query = "SELECT accounts.code, splits.guid FROM splits INNER JOIN accounts ON (accounts.guid = splits.account_guid) WHERE tx_guid = {$tx}";
        $splits = $this->db->read($query);
        $split_by_account = array_column($splits, 'guid', 'code');
        if (count($split_by_account) !== count($splits)) {
            throw new \RuntimeException('TX splits are not account uniqe');
        }
        return $split_by_account;
    }

    /**
     * @return string[] code -> name
     */
    public function accounts(): array
    {
        return Account::codeNames($this->db);
    }

    /**
     * @param int $account_code
     *
     * @return \PhpDoc\bank_transactions_cache[]
     * @throws \RuntimeException
     */
    public function account_cache($account_code): array
    {
        $list = [];
        $account_code = (int) $account_code;
        $query = <<<SQL_BLOCK
SELECT bank_transactions.bank_t_row, bank_transactions_cache.md5, bank_transactions_cache.data
FROM bank_transactions LEFT JOIN bank_transactions_cache USING (bank_t_row)
WHERE account = {$account_code} AND bank_tid IS NULL AND bdate >= '2016-09-01'
ORDER BY bdate DESC, bank_t_row DESC
SQL_BLOCK;
        foreach ($this->db->g_objects($query) as $row) {
            if (!$row->data) {
                $bank_row = $this->get_bank_row($row->bank_t_row);
                $bank_row->save_cache($this->db);
                $row = $this->db->object($query . ' AND bank_t_row = ' . $row->bank_t_row);
            }
            $list[] = json_decode($row->data, true, 512, JSON_THROW_ON_ERROR);
        }
        return $list;
    }
}
