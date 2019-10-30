<?php

require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/GnuCash.php';

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
    public function db()
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
    public function connect_bank_row($row_nr, $guid)
    {
        $bank_row = $this->get_bank_row($row_nr);
        if (!$bank_row) {
            throw new \Exception('row dosn\'t exisists');
        }
        if ($bank_row->bank_tid) {
            throw new \Exception('row allredy connected');
        }

        $guid = $this->db->quote($guid);
        $row_nr = (int) $row_nr;
        $query = "UPDATE bank_transactions SET bank_tid = {$guid} WHERE bank_tid IS NULL AND bank_t_row = {$row_nr}";
        return $this->db->write($query);
    }

    /**
     * @param int $row_nr
     *
     * @return table_bank_transactions
     */
    public function get_bank_row($row_nr)
    {
        $row_nr = (int) $row_nr;
        $query = "SELECT * FROM bank_transactions WHERE bank_t_row = {$row_nr}";
        return $this->db->object($query, null, table_bank_transactions::class);
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
     */
    public function add_from_bank_row($row_nr, $date, $amount, $from, $to, $text)
    {
        if (!$row_nr) {
            throw new \Exception('invalid parameters row_nr to add_from_bank_row()');
        }
        if (!$date) {
            throw new \Exception('invalid parameters date to add_from_bank_row()');
        }
        if (!$amount) {
            throw new \Exception('invalid parameters amount to add_from_bank_row()');
        }
        if (!$from) {
            throw new \Exception('invalid parameters to add_from_bank_row()');
        }
        if (!$to) {
            throw new \Exception('invalid parameters to add_from_bank_row()');
        }
        if (!$text) {
            throw new \Exception('invalid parameters to add_from_bank_row()');
        }

        if (!$this->gc->GUIDExists($from)) {
            throw new \Exception('from account dosn\'t exists');
        }
        if (!$this->gc->GUIDExists($to)) {
            throw new \Exception('to account dosn\'t exists');
        }

        $bank_row = $this->get_bank_row($row_nr);
        if (!$bank_row) {
            throw new \Exception('row dosn\'t exisists');
        }
        if ($bank_row->bank_tid) {
            throw new \Exception('row allready connected');
        }

        $error = $this->gc->createTransaction($to, $from, $amount, $text, $date, '');
        if ($error) {
            throw new \Exception($error);
        }

        if (!$this->gc->lastTxGUID) {
            throw new \Exception('No new guid generated');
        }

        $splits = $this->tx_to_account_splits($this->gc->lastTxGUID);

        if (empty($splits[$bank_row->account])) {
            throw new \Exception('account not found on tx');
        }

        return $this->connect_bank_row($row_nr, $splits[$bank_row->account]);
    }

    /**
     * @param string $tx
     *
     * @return string[] account.code -> split.guid
     * @throws Exception
     */
    public function tx_to_account_splits($tx)
    {
        $tx = $this->db->quote($tx);
        $query = "SELECT accounts.code, splits.guid FROM splits INNER JOIN accounts ON (accounts.guid = splits.account_guid) WHERE tx_guid = {$tx}";
        $splits = $this->db->read($query);
        $split_by_account = array_column($splits, 'guid', 'code');
        if (count($split_by_account) != count($splits)) {
            throw new \Exception('TX splits are not account uniqe');
        }
        return $split_by_account;
    }

    /**
     * @return string[] code -> name
     */
    public function accounts()
    {
        return $this->db->read(
            "SELECT code, name FROM `accounts` WHERE LENGTH(code) >= 4 ORDER BY code",
            "code",
            "name"
        );
    }

    /**
     * @param int $account_code
     *
     * @return bank_transactions_cache[]
     * @throws Exception
     */
    public function account_cache($account_code)
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
            $list[] = json_decode($row->data);
        }
        return $list;
    }
}

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
class table_bank_transactions
{
    public $bdate;
    public $vdate;
    public $vnr;
    public $vtext;
    public $amount;
    public $saldo;
    public $account;
    public $bank_tid;
    public $bank_t_row;

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

class table_db_result_account_name_rows
{
    public $account;
    public $name;
    public $rows;
    public $erows;
    public $prows;
    public $edate;
    public $fdate;
}

class table_db_result_row_value_date_description_guid
{
    public $row;
    public $value;
    public $date;
    public $description;
    public $guid;
    public $other_account;
}

class table_db_result_row_text_match
{
    public $code;
    public $name;
    public $connections;
    public $amount_from;
    public $amount_to;
    public $date_from;
    public $date_to;
}

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
 * @property table_db_result_row_value_date_description_guid[][]|table_db_result_row_text_match[][] $matches
 * @property string md5
 */
class bank_transactions_cache
{
}
