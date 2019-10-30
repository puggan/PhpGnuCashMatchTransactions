<?php

class GnuCash
{
    private $con;
    private $eException;
    private $sDbName;
    private $aError;
    public $lastTxGUID;

    public function __construct($sHostname, $sDbName, $sUsername, $sPassword)
    {
        $this->sDbName = $sDbName;
        try {
            $this->con = new PDO(
                "mysql:host=$sHostname;dbname=$sDbName",
                $sUsername,
                $sPassword,
                [PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8']
            );
            $this->con->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $e) {
            $this->eException = $e;
        }
    }

    public function getErrorMessage()
    {
        if ($this->eException) {
            return $this->eException->getMessage();
        }
        return '';
    }

    public function getErrorCode()
    {
        if ($this->eException) {
            return $this->eException->getCode();
        }
        return 0;
    }

    public function runQuery($sSql, $aParameters = [], $bReturnFirst = false)
    {
        $this->lastQuery = strtr($sSql, $aParameters);
        try {
            $q = $this->con->prepare($sSql);
            $result = $q->execute($aParameters);
            $query_type = explode(' ', preg_replace("#\\s+#", ' ', $sSql))[0];
            switch (strtoupper($query_type)) {
                case 'INSERT':
                {
                    return $result;
                }
                case 'UPDATE':
                case 'DELETE':
                {
                    return $result;
                }
                case 'USE':
                case 'START':
                case 'ROLLBACK':
                case 'COMMIT':
                {
                    return $result;
                }
            }
            $q->setFetchMode(PDO::FETCH_ASSOC);
            $aReturn = [];
            while ($aRow = $q->fetch()) {
                if ($bReturnFirst) {
                    return $aRow;
                }
                $aReturn[] = $aRow;
            }
            return $aReturn;
        } catch (PDOException $e) {
            $this->eException = $e;
        }
    }

    public function getNewGUID()
    {
        mt_srand((double) microtime() * 10000);

        while (true) {
            $sTempGUID = strtolower(md5(uniqid(rand(), true)));
            //  Theoretically there is an extremely small chance that there are duplicates.
            //  However, why not?
            if (!$this->GUIDExists($sTempGUID)) {
                return $sTempGUID;
            }
        }
    }

    public function GUIDExists($sGUID)
    {
        $this->runQuery("USE `information_schema`;");
        $aTables = $this->runQuery(
            "SELECT * FROM `TABLES` WHERE `TABLE_SCHEMA` LIKE :dbname;",
            [':dbname' => $this->sDbName]
        );
        $this->runQuery("USE `{$this->sDbName}`;");
        foreach ($aTables as $aTable) {
            $aGUIDs = $this->runQuery(
                "SELECT * FROM `{$aTable['TABLE_NAME']}` WHERE `guid` LIKE :guid;",
                [':guid' => $sGUID]
            );
            if ($aGUIDs) {
                return true;
            }
        }
        return false;
    }

    public function getAccountInfo($sAccountGUID)
    {
        return $this->runQuery(
            "SELECT * FROM `accounts` WHERE `guid` = :guid ORDER BY code, name",
            [':guid' => $sAccountGUID],
            true
        );
    }

    public function getAccounts()
    {
        $query = <<<SQL_BLOCK
SELECT
	accounts.*,
	COUNT(DISTINCT splits.guid) AS Count
FROM accounts
	LEFT OUTER JOIN splits ON (splits.account_guid = accounts.guid)
GROUP BY accounts.guid
SQL_BLOCK;
        return $this->runQuery($query);
    }

    public function getSortedAccounts()
    {
        $unsorted_accounts = array_column($this->getAccounts(), null, 'guid');
        $sorted_accounts = [];
        foreach ($this->getSortedAccountGUIDs() as $guid) {
            if (isset($unsorted_accounts[$guid])) {
                $sorted_accounts[] = $unsorted_accounts[$guid];
            }
        }
        return $sorted_accounts;
    }

    public function getSortedAccountGUIDs()
    {
        $guids = [];
        foreach (array_column(
                     $this->runQuery("SELECT guid FROM accounts WHERE parent_guid IS NULL"),
                     'guid'
                 ) as $root_guid) {
            $child_guids = $this->childGUIDs($root_guid);
            if ($child_guids[0] != $root_guid) {
                $guids = array_merge($guids, $child_guids);
            }
        }
        return $guids;
    }

    public function childGUIDs($sParentGUID)
    {
        $child_guids = [];
        foreach (array_column(
                     $this->runQuery(
                         "SELECT guid FROM accounts WHERE parent_guid = :parent_guid ORDER BY code, name",
                         [':parent_guid' => $sParentGUID]
                     ),
                     'guid'
                 ) as $childGUID) {
            $child_guids = array_merge($child_guids, $this->childGUIDs($childGUID));
        }
        if ($child_guids) {
            return $child_guids;
        } else {
            return [$sParentGUID];
        }
    }

    public function getAccountTransactions($sAccountGUID)
    {
        $query = <<<SQL_BLOCK
SELECT
	`description`,
	`post_date`,
	`tx_guid`,
	`memo`,
	`reconcile_state`,
	`value_num`,
	`value_denom`,
	`quantity_num`,
	`quantity_denom`,
	`account_guid`
FROM `splits`
	LEFT JOIN `transactions` ON (`transactions`.`guid` = `splits`.`tx_guid`)
WHERE `account_guid` = :guid
ORDER BY `transactions`.`post_date` DESC;
SQL_BLOCK;
        return $this->runQuery($query, [':guid' => $sAccountGUID]);
    }

    public function getTransactionInfo($sGUID)
    {
        $aSplits = $this->runQuery(
            "SELECT * FROM `splits` WHERE `tx_guid` = :guid;",
            [':guid' => $sGUID]
        );
        return [$this->getTransaction($sGUID), $aSplits];
    }

    public function getTransaction($sGUID)
    {
        return $this->runQuery(
            "SELECT * FROM `transactions` WHERE `guid` = :guid;",
            [':guid' => $sGUID]
        );
    }

    public function getSplit($sGUID)
    {
        return $this->runQuery(
            "SELECT * FROM `splits` WHERE `guid` = :guid;",
            [':guid' => $sGUID],
            true
        );
    }

    public function isLocked()
    {
        // Bad juju to edit the database when it's locked.
        // I've done tests, and you can but the desktop client won't reflect changes that it didn't make.
        //  -So you can add a transaction while the desktop client is open but it won't show until you restart it.
        $aLocks = $this->runQuery("SELECT * FROM `gnclock`;");
        if ($aLocks) {
            return $aLocks[0];
        }
        return false;
    }

    public function createTransaction($sDebitGUID, $sCreditGUID, $fAmount, $sName, $sDate, $sMemo)
    {
        $this->lastTxGUID = null;
        if ($this->isLocked()) {
            return 'Database is locked';
        }
        // Transaction GUID, same for both debit and credit entries in transactions.
        $sTransactionGUID = $this->getNewGUID();
        if (!$sTransactionGUID) {
            return 'Failed to get a new transaction GUID.';
        }
        if ($sDebitGUID and is_array($sDebitGUID)) {
            $debitAmount = 0;
            $aaDebbitAccounts = [];
            foreach ($sDebitGUID as $sguid => $svalue) {
                $debitAmount += $svalue;
                $aaDebbitAccounts[$sguid] = $this->getAccountInfo($sguid);
                if (!$aaDebbitAccounts[$sguid]) {
                    return 'Failed to retrieve account for GUID: ' . $sguid . '.';
                }
                $aaDebbitAccounts[$sguid]['amount'] = $svalue;
            }
            $aDebbitAccount = reset($aaDebbitAccounts);
        } else {
            $debitAmount = $fAmount;
            $aDebbitAccount = $this->getAccountInfo($sDebitGUID);
            if (!$aDebbitAccount) {
                return 'Failed to retrieve account for GUID: ' . $sDebitGUID . '.';
            }
            $aaDebbitAccounts = [];
            $aaDebbitAccounts[$sDebitGUID] = $aDebbitAccount;
            $aaDebbitAccounts[$sDebitGUID]['amount'] = $fAmount;
        }
        if ($sCreditGUID and is_array($sCreditGUID)) {
            $creditAmount = 0;
            $aaCreditAccount = [];
            foreach ($sCreditGUID as $sguid => $svalue) {
                $creditAmount += $svalue;
                $aaCreditAccount[$sguid] = $this->getAccountInfo($sguid);
                if (!$aaCreditAccount[$sguid]) {
                    return 'Failed to retrieve account for GUID: ' . $sguid . '.';
                }
                $aaCreditAccount[$sguid]['amount'] = $svalue;
            }
            $aCreditAccount = reset($aaCreditAccount);
        } else {
            $creditAmount = $fAmount;
            $aCreditAccount = $this->getAccountInfo($sCreditGUID);
            if (!$aCreditAccount) {
                return 'Failed to retrieve account for GUID: ' . $sCreditGUID . '.';
            }
            $aaCreditAccount = [];
            $aaCreditAccount[$sCreditGUID] = $aCreditAccount;
            $aaCreditAccount[$sCreditGUID]['amount'] = $fAmount;
        }

        if ($creditAmount != $debitAmount) {
            return "unbalanced";
        }

        if ($aDebbitAccount['commodity_guid'] == $aCreditAccount['commodity_guid']) {
            $sCurrencyGUID = $aDebbitAccount['commodity_guid'];
            $sCurrencySCU = $aDebbitAccount['commodity_scu'];
        } else {
            $aRootAccount = $this->getAccountCommodity();
            $sCurrencyGUID = $aRootAccount['commodity_guid'];
            $sCurrencySCU = $aRootAccount['commodity_scu'];
        }

        foreach (array_keys($aaDebbitAccounts) as $aguid) {
            if ($aaDebbitAccounts[$aguid]['commodity_guid'] == $sCurrencyGUID) {
                $aaDebbitAccounts[$aguid]['commodity_scale'] = 1;
            } else {
                $aDebbitPrice = $this->getCommodityPrice(
                    $aaDebbitAccounts[$aguid]['commodity_guid'],
                    $sCurrencyGUID,
                    $sDate
                );
                if ($aDebbitPrice) {
                    $aaDebbitAccounts[$aguid]['commodity_scale'] = $aDebbitPrice['value_num'] / $aDebbitPrice['value_denom'];
                } else {
                    $aaDebbitAccounts[$aguid]['commodity_scale'] = 1;
                }
            }
        }
        foreach (array_keys($aaCreditAccount) as $aguid) {
            if ($aaCreditAccount[$aguid]['commodity_guid'] == $sCurrencyGUID) {
                $aaCreditAccount[$aguid]['commodity_scale'] = 1;
            } else {
                $aDebbitPrice = $this->getCommodityPrice(
                    $aaCreditAccount[$aguid]['commodity_guid'],
                    $sCurrencyGUID,
                    $sDate
                );
                if ($aDebbitPrice) {
                    $aaCreditAccount[$aguid]['commodity_scale'] = $aDebbitPrice['value_num'] / $aDebbitPrice['value_denom'];
                } else {
                    $aaCreditAccount[$aguid]['commodity_scale'] = 1;
                }
            }
        }

        if (!$sCurrencyGUID) {
            return 'Currency GUID is empty.';
        }

        // Time may change during the execution of this function.
        $sEnterDate = date('Y-m-d H:i:s', time());

        $this->runQuery("START TRANSACTION");

        $this->runQuery(
            "INSERT INTO `transactions` (`guid`, `currency_guid`, `num`, `post_date`, `enter_date`, `description`) VALUES (:guid, :currency_guid, :num, :post_date, :enter_date, :description);",
            [
                ':guid' => $sTransactionGUID,
                ':currency_guid' => $sCurrencyGUID,
                ':num' => '',
                ':post_date' => $sDate,
                ':enter_date' => $sEnterDate,
                ':description' => $sName
            ]
        );

        $sTransactionMessage = $this->eException->getMessage();
        $aTransaction = $this->getTransaction($sTransactionGUID);
        if (!$aTransaction) {
            $this->runQuery("ROLLBACK");
            $sError = 'Error:' . ($this->getErrorMessage() ? ' ' . $this->getErrorMessage() . '.' : '');
            $sError .= ' Failed to create transaction record: <b>' . $sTransactionMessage . '</b>';
            return $sError;
        }

        foreach ($aaDebbitAccounts as $aguid => $account) {
            $sSplitDebitGUID = $this->addSplit(
                $sTransactionGUID,
                $aguid,
                $account['amount'],
                $sCurrencySCU,
                $account['commodity_scale'],
                $account['commodity_scu'],
                $sMemo
            );
            $sMemo = '';
            if (!$sSplitDebitGUID) {
                $this->runQuery("ROLLBACK");
                return 'Failed to add split. (' . $aguid . ') ' . $this->eException->getMessage();
            }
        }
        foreach ($aaCreditAccount as $aguid => $account) {
            $sSplitCreditGUID = $this->addSplit(
                $sTransactionGUID,
                $aguid,
                -$account['amount'],
                $sCurrencySCU,
                $account['commodity_scale'],
                $account['commodity_scu'],
                $sMemo
            );
            $sMemo = '';
            if (!$sSplitCreditGUID) {
                $this->runQuery("ROLLBACK");
                return 'Failed to add split. (' . $aguid . ') ' . $this->eException->getMessage();
            }
        }

        $this->lastTxGUID = $sTransactionGUID;
        $this->runQuery("COMMIT");
        return '';
    }

    public function addSplit(
        $sTransactionGUID,
        $sAccountGUID,
        $fAmount,
        $sCurrencySCU = 100,
        $fCommodityScale = 1,
        $fCommoditySCU = 100,
        $sMemo = ''
    ) {
        $sSplitGUID = $this->getNewGUID();
        if (!$sSplitGUID) {
            return false;
        }

        $query = <<<SQL_BLOCK
INSERT INTO splits
SET
	guid = :guid,
	tx_guid = :tx_guid,
	account_guid = :account_guid,
	memo = :memo,
	reconcile_state = :reconcile_state,
	reconcile_date = :reconcile_date,
	action = :action,
	value_num = :value_num,
	value_denom = :value_denom,
	quantity_num = :quantity_num,
	quantity_denom = :quantity_denom
SQL_BLOCK;
        $result = $this->runQuery(
            $query,
            [
                ':guid' => $sSplitGUID,
                ':tx_guid' => $sTransactionGUID,
                ':account_guid' => $sAccountGUID,
                ':memo' => $sMemo,
                ':reconcile_state' => 'n',
                ':reconcile_date' => null,
                ':action' => '',
                ':value_num' => round($fAmount * $sCurrencySCU),
                ':value_denom' => $sCurrencySCU,
                ':quantity_num' => round($fAmount * $fCommoditySCU / $fCommodityScale),
                ':quantity_denom' => $fCommoditySCU
            ]
        );
        return $result ? $sSplitGUID : false;
    }

    public function deleteTransaction($sTransactionGUID)
    {
        if ($this->isLocked()) {
            return false;
        }
        $this->runQuery(
            "DELETE FROM `transactions` WHERE `guid` = :guid;",
            [':guid' => $sTransactionGUID]
        );
        $this->runQuery(
            "DELETE FROM `splits` WHERE `tx_guid` = :guid;",
            [':guid' => $sTransactionGUID]
        );

        // Verify entries were deleted.
        $aTransaction = $this->getTransactionInfo($sTransactionGUID);
        if ($aTransaction[0] or $aTransaction[1]) {
            return false;
        }
        return true;
    }

    public function setReconciledStatus($sTransactionGUID, $bReconciled)
    {
        $sReconciled = ($bReconciled ? 'n' : 'c');
        $this->runQuery(
            "UPDATE `splits` SET `reconcile_state` = :reconcile_state WHERE `tx_guid` = :tx_guid;",
            [':reconcile_state' => $sReconciled, ':tx_guid' => $sTransactionGUID]
        );
        $aTransactions = $this->runQuery(
            "SELECT * FROM `splits` WHERE `tx_guid` = :tx_guid;",
            [':tx_guid' => $sTransactionGUID]
        );
        $bSet = true;
        foreach ($aTransactions as $aTransaction) {
            if ($aTransaction['reconcile_state'] != $sReconciled) {
                $bSet = false;
            }
        }
        return $bSet;
    }

    public function getAllAccounts()
    {
        return $this->runQuery("SELECT * FROM `accounts` ORDER BY code, name");
    }

    public function getChildAccounts($sParentGUID)
    {
        return $this->runQuery(
            "SELECT * FROM `accounts` WHERE `parent_guid` = :parent_guid ORDER BY code, name",
            [':parent_guid' => $sParentGUID]
        );
    }

    public function renameAccount($sAccountGUID, $sNewAccountName)
    {
        $this->runQuery(
            "UPDATE `accounts` SET `name` = :name WHERE `guid` = :guid;",
            [':name' => $sNewAccountName, ':guid' => $sAccountGUID]
        );
        $aAccount = $this->runQuery(
            "SELECT * FROM `accounts` WHERE `guid` = :guid;",
            [':guid' => $sAccountGUID],
            true
        );
        return ($sNewAccountName == $aAccount['name']);
    }

    public function deleteAccount($sAccountGUID)
    {
        $aChildAccounts = $this->getChildAccounts($sAccountGUID);
        if ($aChildAccounts) {
            return [0, 'Account has child accounts, can&rsquo;t delete.'];
        }
        $aAccount = $this->getAccountInfo($sAccountGUID);
        if ($aAccount['account_type'] == 'ROOT') {
            return [0, 'Can&rsquo;t delete the root account.'];
        }
        $aTransactions = $this->getAccountTransactions($sAccountGUID);
        foreach ($aTransactions as $aTransaction) {
            $this->deleteTransaction($aTransaction['tx_guid']);
        }
        $this->runQuery(
            "DELETE FROM `accounts` WHERE `guid` = :guid;",
            [':guid' => $sAccountGUID]
        );
        return [1, ''];
        // TODO: Delete scheduled transactions and other entries that reference this account guid.
    }

    public function createAccount($sName, $sAccountType, $sCommodityGUID, $sParentAccountGUID)
    {
        $aAccountExists = $this->runQuery(
            "SELECT * FROM `accounts` WHERE `parent_guid` = :parent_guid AND `account_name` = :account_name AND `type` = :type;",
            [':parent_guid' => $sParentAccountGUID, ':name' => $sName, ':account_type' => $sAccountType]
        );
        if ($aAccountExists) {
            return false;
        }
        $aCommodity = $this->runQuery(
            "SELECT * FROM `commodities` WHERE `guid` = :guid;",
            [':guid' => $sCommodityGUID],
            true
        );
        $sAccountGUID = $this->getNewGUID();
        $query = <<<SQL_BLOCK
INSERT INTO `accounts` (`guid`, `name`, `account_type`, `commodity_guid`, `commodity_scu`, `non_std_scu`, `parent_guid`, `hidden`, `placeholder`)
VALUES (:guid, :name, :account_type, :commodity_guid, :commodity_scu, :non_std_scu, :parent_guid, :hidden, :placeholder);
SQL_BLOCK;
        $this->runQuery(
            $query,
            [
                ':guid' => $sAccountGUID,
                ':name' => $sName,
                ':account_type' => $sAccountType,
                ':commodity_guid' => $aCommodity['guid'],
                ':commodity_scu' => $aCommodity['fraction'],
                ':non_std_scu' => 0,
                ':parent_guid' => $sParentAccountGUID,
                ':hidden' => 0,
                ':placeholder' => 0
            ]
        );
        $aNewAccount = $this->getAccountInfo($sAccountGUID);
        return !empty($aNewAccount);
    }

    public function getCommodities()
    {
        return $this->runQuery("SELECT * FROM `commodities`;");
    }

    public function getAccountCommodity($sAccountGUID = null)
    {
        // get commodity for given account
        if ($sAccountGUID) {
            return $this->runQuery(
                "SELECT commodity_guid, commodity_scu FROM accounts  WHERE guid = :guid",
                [':guid' => $sAccountGUID],
                true
            );
            // get commodity for root account
        } else {
            return $this->runQuery(
                "SELECT accounts.commodity_guid, accounts.commodity_scu FROM slots INNER JOIN books ON (books.guid = slots.obj_guid) INNER JOIN accounts ON (accounts.guid = books.root_account_guid) WHERE slots.name LIKE 'options'",
                null,
                true
            );
        }
    }

    public function getCommodityPrice($sCommodityGUID, $sCurrencyGUID, $sDate)
    {
        if ($sDate) {
            return $this->runQuery(
                "SELECT value_num, value_denom FROM prices WHERE commodity_guid = :commodity_guid AND currency_guid = :currency_guid ORDER BY ABS(UNIX_TIMESTAMP(:date) - UNIX_TIMESTAMP(NOW())) LIMIT 1",
                [':commodity_guid' => $sCommodityGUID, 'currency_guid' => $sCurrencyGUID, ':date' => $sDate],
                true
            );
        } else {
            return $this->runQuery(
                "SELECT value_num, value_denom FROM prices WHERE commodity_guid = :commodity_guid AND currency_guid = :currency_guid ORDER BY ABS(UNIX_TIMESTAMP(date) - UNIX_TIMESTAMP(NOW())) LIMIT 1",
                [':commodity_guid' => $sCommodityGUID, 'currency_guid' => $sCurrencyGUID],
                true
            );
        }
    }

    public function changeAccountParent($sAccountGUID, $sParentAccountGUID)
    {
        $this->runQuery(
            "UPDATE `accounts` SET `parent_guid` = :parent_guid WHERE `guid` = :guid;",
            [':parent_guid' => $sParentAccountGUID, ':guid' => $sAccountGUID]
        );
        $aAccount = $this->getAccountInfo($sAccountGUID);
        return ($aAccount['parent_guid'] == $sParentAccountGUID);
    }

    public function changeTransactionDescription($sTransactionGUID, $sNewDescription)
    {
        $this->runQuery(
            "UPDATE `transactions` SET `description` = :description WHERE `guid` = :guid;",
            [':description' => $sNewDescription, ':guid' => $sTransactionGUID]
        );
        $aTransactionInfo = $this->runQuery(
            "SELECT * FROM `transactions` WHERE `guid` = :guid;",
            [':guid' => $sTransactionGUID],
            true
        );
        return ($aTransactionInfo['description'] == $sNewDescription);
    }

    public function changeTransactionAmount($sTransactionGUID, $sNewAmount)
    {
        // TODO: How to calculate the value/quantity based on value/quantity denominators.
        $this->runQuery(
            "UPDATE `splits` SET `value_num` = :value_num, `quantity_num` = :quantity_num WHERE `tx_guid` = :tx_guid AND `value_num` < 0;",
            [':value_num' => ($sNewAmount * -1) * 100, ':quantity_num' => ($sNewAmount * -1) * 100, ':tx_guid' => $sTransactionGUID]
        );
        $this->runQuery(
            "UPDATE `splits` SET `value_num` = :value_num, `quantity_num` = :quantity_num WHERE `tx_guid` = :tx_guid AND `value_num` > 0;",
            [':value_num' => $sNewAmount * 100, ':quantity_num' => $sNewAmount * 100, ':tx_guid' => $sTransactionGUID]
        );
        $aTransactionInfo = $this->getTransactionInfo($sTransactionGUID);
        // TODO: Verify.
        return true;
    }

    public function changeTransactionDate($sTransactionGUID, $sNewDate)
    {
        $this->runQuery(
            "UPDATE `transactions` SET `post_date` = :post_date, `enter_date` = :enter_date WHERE `guid` = :guid;",
            [':post_date' => $sNewDate, ':enter_date' => $sNewDate, ':guid' => $sTransactionGUID]
        );
        $aTransaction = $this->runQuery(
            "SELECT * FROM `transactions` WHERE `guid` = :guid;",
            [':guid' => $sTransactionGUID],
            true
        );
        $oNewDate = new DateTime($sNewDate);
        $oPostDate = new DateTime($aTransaction['post_date']);
        $oEnterDate = new DateTime($aTransaction['enter_date']);
        return ($oNewDate == $oPostDate) and ($oNewDate == $oEnterDate);
    }

    public function getDatabases()
    {
        $aDatabases = $this->runQuery('SHOW DATABASES;');
        $aReturn = [];
        foreach ($aDatabases as $aDatabase) {
            if (in_array($aDatabase['Database'], ['information_schema', 'performance_schema', 'mysql'])) {
                continue;
            }
            $aReturn[] = $aDatabase['Database'];
        }
        return $aReturn;
    }
}
