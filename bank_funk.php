<?php /** @noinspection PhpIllegalPsrClassPathInspection */
/** @noinspection PhpIllegalPsrClassPathInspection */
/** @noinspection PhpClassNamingConventionInspection */
/** @noinspection AutoloadingIssuesInspection */
/** @noinspection AutoloadingIssuesInspection */
declare(strict_types=1);

use Puggan\GnuCashMatcher\Auth;
use Puggan\GnuCashMatcher\GnuCash;
use Puggan\GnuCashMatcher\Models\Account;
use Puggan\GnuCashMatcher\Models\BankTransaction;

require_once __DIR__ . '/vendor/autoload.php';

/**
 * Class Bank_interface
 *
 * @property \Puggan\GnuCashMatcher\DB $database
 * @property GnuCash $gnuCash
 */
class Bank_interface
{
    private $database;
    private $gnuCash;

    /**
     * Bank_interface constructor.
     */
    public function __construct()
    {
        $this->database = Auth::newDatabase();
        $this->gnuCash = Auth::newGnuCash();
    }

    /**
     * @return \Puggan\GnuCashMatcher\DB
     */
    public function database(): \Puggan\GnuCashMatcher\DB
    {
        return $this->database;
    }

    /**
     * @param int $rowNr
     * @param string $guid
     *
     * @return bool
     * @throws Exception
     */
    public function connectBankRow($rowNr, $guid): bool
    {
        $bankRow = $this->bankRow($rowNr);
        if (!$bankRow) {
            throw new \RuntimeException('row dosn\'t exisists');
        }
        if ($bankRow->bank_tid) {
            throw new \RuntimeException('row allredy connected');
        }

        $guid = $this->database->quote($guid);
        $rowNr = (int) $rowNr;
        $query = "UPDATE bank_transactions SET bank_tid = {$guid} WHERE bank_tid IS NULL AND bank_t_row = {$rowNr}";
        return $this->database->write($query);
    }

    /**
     * @param int $rowNr
     *
     * @return BankTransaction
     */
    public function bankRow($rowNr): BankTransaction
    {
        $rowNr = (int) $rowNr;
        $query = "SELECT * FROM bank_transactions WHERE bank_t_row = {$rowNr}";
        /** @var BankTransaction $transaction */
        $transaction = $this->database->object($query, null, BankTransaction::class);
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
    public function addFromBankRow($row_nr, $date, $amount, $from, $to, $text): bool
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

        if (!$this->gnuCash->GUIDExists($from)) {
            throw new \RuntimeException('from account dosn\'t exists');
        }
        if (!$this->gnuCash->GUIDExists($to)) {
            throw new \RuntimeException('to account dosn\'t exists');
        }

        $bankRow = $this->bankRow($row_nr);
        if (!$bankRow) {
            throw new \RuntimeException('row dosn\'t exisists');
        }
        if ($bankRow->bank_tid) {
            throw new \RuntimeException('row allready connected');
        }

        $error = $this->gnuCash->createTransaction($to, $from, $amount, $text, $date, '');
        if ($error) {
            throw new \RuntimeException($error);
        }

        if (!$this->gnuCash->lastTxGUID) {
            throw new \RuntimeException('No new guid generated');
        }

        $splits = $this->tx2accountSplits($this->gnuCash->lastTxGUID);

        if (empty($splits[$bankRow->account])) {
            throw new \RuntimeException('account not found on tx');
        }

        return $this->connectBankRow($row_nr, $splits[$bankRow->account]);
    }

    /**
     * @param string $transaction
     *
     * @return string[] account.code -> split.guid
     * @throws Exception
     */
    public function tx2accountSplits($transaction): array
    {
        $transaction = $this->database->quote($transaction);
        $query = "SELECT accounts.code, splits.guid FROM splits INNER JOIN accounts ON (accounts.guid = splits.account_guid) WHERE tx_guid = {$transaction}";
        $splits = $this->database->read($query);
        $splitByAccount = array_column($splits, 'guid', 'code');
        if (count($splitByAccount) !== count($splits)) {
            throw new \RuntimeException('TX splits are not account uniqe');
        }
        return $splitByAccount;
    }

    /**
     * @return string[] code -> name
     */
    public function accounts(): array
    {
        return Account::codeNames($this->database);
    }

    /**
     * @param int $accountCode
     *
     * @return \PhpDoc\bank_transactions_cache[]
     * @throws \RuntimeException
     */
    public function accountCache($accountCode): array
    {
        $list = [];
        $accountCode = (int) $accountCode;
        $query = <<<SQL_BLOCK
SELECT bank_transactions.bank_t_row, bank_transactions_cache.md5, bank_transactions_cache.data
FROM bank_transactions LEFT JOIN bank_transactions_cache USING (bank_t_row)
WHERE account = {$accountCode} AND bank_tid IS NULL AND bdate >= '2016-09-01'
ORDER BY bdate DESC, bank_t_row DESC
SQL_BLOCK;
        foreach ($this->database->g_objects($query) as $dbRow) {
            if (!$dbRow->data) {
                $bankRow = $this->bankRow($dbRow->bank_t_row);
                $bankRow->saveCache($this->database);
                $dbRow = $this->database->object(strtr($query, ['ORDER BY ' =>' AND bank_t_row = ' . $dbRow->bank_t_row . ' ORDER BY ']));
            }
            $list[] = json_decode($dbRow->data, true, 512, JSON_THROW_ON_ERROR);
        }
        return $list;
    }
}
