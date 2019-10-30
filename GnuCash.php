<?php
/** @noinspection PhpTooManyParametersInspection */
declare(strict_types=1);
/** @noinspection SpellCheckingInspection */
/** @noinspection UnknownInspectionInspection */
/** @noinspection DuplicatedCode */
/** @noinspection TypeUnsafeComparisonInspection */
/** @noinspection AutoloadingIssuesInspection */
/** @noinspection UnusedFunctionResultInspection */
/** @noinspection PhpIllegalPsrClassPathInspection */
/** @noinspection PhpTooManyParametersInspection */
/** @noinspection PhpVariableNamingConventionInspection */
/** @noinspection PhpPropertyNamingConventionInspection */
/** @noinspection PhpMethodNamingConventionInspection */

/**
 * Class GnuCash
 * @property string lastTxGUID
 * @property string lastQuery
 */
class GnuCash
{
    private $con;
    private $eException;
    private $sDbName;
    public $lastTxGUID;
    public $lastQuery;

    /**
     * GnuCash constructor.
     * @param string $sHostname
     * @param string $sDbName
     * @param string $sUsername
     * @param string $sPassword
     */
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

    /**
     * @return string
     */
    public function getErrorMessage(): string
    {
        if ($this->eException) {
            return $this->eException->getMessage();
        }
        return '';
    }

    /**
     * @return int
     */
    public function getErrorCode(): int
    {
        if ($this->eException) {
            return $this->eException->getCode();
        }
        return 0;
    }

    /**
     * @param $sSql
     * @param array $aParameters
     * @param bool $bReturnFirst
     * @return array|bool|mixed
     * @throw \PDOException
     */
    public function runQuery($sSql, $aParameters = [], $bReturnFirst = false)
    {
        $this->lastQuery = strtr($sSql, $aParameters);
        /** @noinspection BadExceptionsProcessingInspection */
        try {
            $q = $this->con->prepare($sSql);
            $result = $q->execute($aParameters);
            $query_type = explode(' ', preg_replace("#\\s+#", ' ', $sSql, 1), 2)[0];
            switch (strtoupper($query_type)) {
                case 'INSERT':
                    return $result;

                case 'UPDATE':
                /** @noinspection PhpDuplicateSwitchCaseBodyInspection */
                case 'DELETE':
                    return $result;

                case 'USE':
                case 'START':
                case 'ROLLBACK':
                /** @noinspection PhpDuplicateSwitchCaseBodyInspection */
                case 'COMMIT':
                    return $result;

                default:
                    break;
            }
            $q->setFetchMode(PDO::FETCH_ASSOC);
            $aReturn = [];
            while (($aRow = $q->fetch()) !== false) {
                if ($bReturnFirst) {
                    return $aRow;
                }
                $aReturn[] = $aRow;
            }
            return $aReturn;
        } catch (\PDOException $e) {
            $this->eException = $e;
            throw $e;
        }
    }

    /**
     * @return string
     */
    public function getNewGUID(): string
    {
        $sTempGUID = null;
        mt_srand((double) microtime() * 10000);

        while (true) {
            $sTempGUID = strtolower(md5(uniqid(mt_rand(), true)));
            //  Theoretically there is an extremely small chance that there are duplicates.
            //  However, why not?
            if (!$this->GUIDExists($sTempGUID)) {
                break;
            }
        }
        return $sTempGUID;
    }

    /**
     * @param string $sGUID
     * @return bool
     */
    public function GUIDExists($sGUID): bool
    {
        $this->runQuery('USE `information_schema`;');
        $aTables = $this->runQuery(
            'SELECT * FROM `TABLES` WHERE `TABLE_SCHEMA` LIKE :dbname;',
            [':dbname' => $this->sDbName]
        );
        $this->runQuery("USE `{$this->sDbName}`;");
        foreach ($aTables as $aTable) {
            $aGUIDs = $this->runQuery(
                'S'.'ELECT * FROM `' . $aTable['TABLE_NAME'] . '` WHERE `guid` LIKE :guid;',
                [':guid' => $sGUID]
            );
            if ($aGUIDs) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param string $sAccountGUID
     * @return array|bool|mixed
     */
    public function getAccountInfo($sAccountGUID)
    {
        return $this->runQuery(
            'SELECT * FROM `accounts` WHERE `guid` = :guid ORDER BY code, name',
            [':guid' => $sAccountGUID],
            true
        );
    }

    /**
     * @return array|bool|mixed
     */
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

    /**
     * @return array
     */
    public function getSortedAccounts(): array
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

    /**
     * @return array
     */
    public function getSortedAccountGUIDs(): array
    {
        $query = 'SELECT guid FROM accounts WHERE parent_guid IS NULL';
        $queryData = $this->runQuery($query);
        $parentGuid = array_column($queryData, 'guid');
        $guids = $this->childGUIDs($parentGuid);
        foreach($parentGuid as $guid) {
            unset($guids[$guid]);
        }
        return array_values($guids);
    }

    /**
     * @param string|string[] $sParentGUID
     * @return array
     */
    public function childGUIDs($sParentGUID): array
    {
        if(is_array($sParentGUID)) {
            /** @var string[] $todo_guids */
            $todo_guids = array_values($sParentGUID);
            $todo_guids = array_combine($todo_guids, $todo_guids);
        } else {
            /** @noinspection SuspiciousArrayElementInspection */
            $todo_guids = [$sParentGUID => $sParentGUID];
        }
        $last_child_guids = [];
        while($todo_guids) {
            $guid = array_pop($todo_guids);
            $query = 'SELECT guid FROM accounts WHERE parent_guid = :parent_guid ORDER BY code, name';
            $queryData = $this->runQuery($query, [':parent_guid' => $sParentGUID]);
            if(!$queryData) {
                $last_child_guids[$guid] = $guid;
                continue;
            }
            /** @var string[] $child_guids */
            $child_guids = array_column($queryData, 'guid');
            foreach($child_guids as $child_guid) {
                $todo_guids[$child_guid] = $child_guid;
            }
        }
        return $last_child_guids;
    }

    /**
     * @param string $sAccountGUID
     * @return array|bool|mixed
     */
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

    /**
     * @param string $sGUID
     * @return array
     */
    public function getTransactionInfo($sGUID): array
    {
        $aSplits = $this->runQuery(
            'SELECT * FROM `splits` WHERE `tx_guid` = :guid;',
            [':guid' => $sGUID]
        );
        return [$this->getTransaction($sGUID), $aSplits];
    }

    /**
     * @param string $sGUID
     * @return array|bool|mixed
     */
    public function getTransaction($sGUID)
    {
        return $this->runQuery(
            'SELECT * FROM `transactions` WHERE `guid` = :guid;',
            [':guid' => $sGUID]
        );
    }

    /**
     * @param string $sGUID
     * @return array|bool|mixed
     * @noinspection PhpUnused
     */
    public function getSplit($sGUID)
    {
        return $this->runQuery(
            'SELECT * FROM `splits` WHERE `guid` = :guid;',
            [':guid' => $sGUID],
            true
        );
    }

    /**
     * @return bool|mixed
     */
    public function isLocked()
    {
        // Bad juju to edit the database when it's locked.
        // I've done tests, and you can but the desktop client won't reflect changes that it didn't make.
        //  -So you can add a transaction while the desktop client is open but it won't show until you restart it.
        $aLocks = $this->runQuery('SELECT * FROM `gnclock`;');
        if ($aLocks) {
            return $aLocks[0];
        }
        return false;
    }

    /**
     * @param string|string[] $sDebitGUID
     * @param string|string[] $sCreditGUID
     * @param int|float $fAmount
     * @param string $sName
     * @param string $sDate
     * @param string $sMemo
     * @return string
     */
    public function createTransaction($sDebitGUID, $sCreditGUID, $fAmount, $sName, $sDate, $sMemo): string
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
        if ($sDebitGUID && is_array($sDebitGUID)) {
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
            $sDebitGUID = (string) $sDebitGUID;
            $debitAmount = $fAmount;
            $aDebbitAccount = $this->getAccountInfo($sDebitGUID);
            if (!$aDebbitAccount) {
                return 'Failed to retrieve account for GUID: ' . $sDebitGUID . '.';
            }
            $aaDebbitAccounts = [];
            $aaDebbitAccounts[$sDebitGUID] = $aDebbitAccount;
            $aaDebbitAccounts[$sDebitGUID]['amount'] = $fAmount;
        }
        if ($sCreditGUID && is_array($sCreditGUID)) {
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
            $sCreditGUID = (string) $sCreditGUID;
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
            return 'unbalanced';
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
        $sEnterDate = date('Y-m-d H:i:s');

        $this->runQuery('START TRANSACTION');

        $this->runQuery(
            'INSERT INTO `transactions` (`guid`, `currency_guid`, `num`, `post_date`, `enter_date`, `description`) VALUES (:guid, :currency_guid, :num, :post_date, :enter_date, :description);',
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
            $this->runQuery('ROLLBACK');
            $sError = 'Error:' . ($this->getErrorMessage() ? ' ' . $this->getErrorMessage() . '.' : '');
            $sError .= ' Failed to create transaction record: <strong>' . $sTransactionMessage . '</strong>';
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
                $this->runQuery('ROLLBACK');
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
                $this->runQuery('ROLLBACK');
                return 'Failed to add split. (' . $aguid . ') ' . $this->eException->getMessage();
            }
        }

        $this->lastTxGUID = $sTransactionGUID;
        $this->runQuery('COMMIT');
        return '';
    }

    /**
     * @param string $sTransactionGUID
     * @param string $sAccountGUID
     * @param int|float $fAmount
     * @param int $sCurrencySCU
     * @param int $fCommodityScale
     * @param int $fCommoditySCU
     * @param string $sMemo
     * @return bool|string
     */
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

    /**
     * @param string $sTransactionGUID
     * @return bool
     */
    public function deleteTransaction($sTransactionGUID): bool
    {
        if ($this->isLocked()) {
            return false;
        }
        $this->runQuery(
            'DELETE FROM `transactions` WHERE `guid` = :guid;',
            [':guid' => $sTransactionGUID]
        );
        $this->runQuery(
            'DELETE FROM `splits` WHERE `tx_guid` = :guid;',
            [':guid' => $sTransactionGUID]
        );

        // Verify entries were deleted.
        $aTransaction = $this->getTransactionInfo($sTransactionGUID);
        return !($aTransaction[0] || $aTransaction[1]);
    }

    /**
     * @param string $sTransactionGUID
     * @param bool $bReconciled
     * @return bool
     */
    public function setReconciledStatus($sTransactionGUID, $bReconciled): bool
    {
        $sReconciled = $bReconciled ? 'n' : 'c';
        $this->runQuery(
            'UPDATE `splits` SET `reconcile_state` = :reconcile_state WHERE `tx_guid` = :tx_guid;',
            [':reconcile_state' => $sReconciled, ':tx_guid' => $sTransactionGUID]
        );
        $aTransactions = $this->runQuery(
            'SELECT * FROM `splits` WHERE `tx_guid` = :tx_guid;',
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

    /**
     * @return array|bool|mixed
     */
    public function getAllAccounts()
    {
        return $this->runQuery('SELECT * FROM `accounts` ORDER BY code, name');
    }

    /**
     * @param string $sParentGUID
     * @return array|bool|mixed
     */
    public function getChildAccounts($sParentGUID)
    {
        return $this->runQuery(
            'SELECT * FROM `accounts` WHERE `parent_guid` = :parent_guid ORDER BY code, name',
            [':parent_guid' => $sParentGUID]
        );
    }

    /**
     * @param string $sAccountGUID
     * @param string $sNewAccountName
     * @return bool
     */
    public function renameAccount($sAccountGUID, $sNewAccountName): bool
    {
        $this->runQuery(
            'UPDATE `accounts` SET `name` = :name WHERE `guid` = :guid;',
            [':name' => $sNewAccountName, ':guid' => $sAccountGUID]
        );
        $aAccount = $this->runQuery(
            'SELECT * FROM `accounts` WHERE `guid` = :guid;',
            [':guid' => $sAccountGUID],
            true
        );
        return $sNewAccountName == $aAccount['name'];
    }

    /**
     * @param string $sAccountGUID
     * @return array
     */
    public function deleteAccount($sAccountGUID): array
    {
        $aChildAccounts = $this->getChildAccounts($sAccountGUID);
        if ($aChildAccounts) {
            return [0, 'Account has child accounts, can&rsquo;t delete.'];
        }
        $aAccount = $this->getAccountInfo($sAccountGUID);
        if ($aAccount['account_type'] == 'ROOT') {
            return [0, 'Can&rsquo;t delete the root account.'];
        }
        foreach ($this->getAccountTransactions($sAccountGUID) as $aTransaction) {
            $this->deleteTransaction($aTransaction['tx_guid']);
        }
        $this->runQuery(
            'DELETE FROM `accounts` WHERE `guid` = :guid;',
            [':guid' => $sAccountGUID]
        );
        return [1, ''];
        // TODO: Delete scheduled transactions and other entries that reference this account guid.
    }

    /**
     * @param string $sName
     * @param string $sAccountType
     * @param string $sCommodityGUID
     * @param string $sParentAccountGUID
     * @return bool
     */
    public function createAccount($sName, $sAccountType, $sCommodityGUID, $sParentAccountGUID): bool
    {
        $aAccountExists = $this->runQuery(
            'SELECT * FROM `accounts` WHERE `parent_guid` = :parent_guid AND `name` = :name AND `account_type` = :account_type;',
            [':parent_guid' => $sParentAccountGUID, ':name' => $sName, ':account_type' => $sAccountType]
        );
        if ($aAccountExists) {
            return false;
        }
        $aCommodity = $this->runQuery(
            'SELECT * FROM `commodities` WHERE `guid` = :guid;',
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

    /**
     * @return array|bool|mixed
     */
    public function getCommodities()
    {
        return $this->runQuery('SELECT * FROM `commodities`;');
    }

    /**
     * @param string|null $sAccountGUID
     * @return array|bool|mixed
     */
    public function getAccountCommodity($sAccountGUID = null)
    {
        // get commodity for given account
        if ($sAccountGUID) {
            $query = 'SELECT commodity_guid, commodity_scu FROM accounts  WHERE guid = :guid';
            $parameters = [':guid' => $sAccountGUID];
            // get commodity for root account
        } else {
            $query = "SELECT accounts.commodity_guid, accounts.commodity_scu FROM slots INNER JOIN books ON (books.guid = slots.obj_guid) INNER JOIN accounts ON (accounts.guid = books.root_account_guid) WHERE slots.name LIKE 'options'";
            $parameters = null;
        }
        return $this->runQuery(
            $query,
            $parameters,
            true
        );
    }

    /**
     * @param string $sCommodityGUID
     * @param string $sCurrencyGUID
     * @param string $sDate
     * @return array|bool|mixed
     */
    public function getCommodityPrice($sCommodityGUID, $sCurrencyGUID, $sDate)
    {
        $query = 'SELECT value_num, value_denom FROM prices WHERE commodity_guid = :commodity_guid AND currency_guid = :currency_guid';
        $parameters = [':commodity_guid' => $sCommodityGUID, 'currency_guid' => $sCurrencyGUID];

        if ($sDate) {
            $parameters[':date'] = $sDate;
            $query .= ' ORDER BY ABS(UNIX_TIMESTAMP(:date) - UNIX_TIMESTAMP(NOW())) LIMIT 1';
        } else {
            $query .= ' ORDER BY ABS(UNIX_TIMESTAMP(date) - UNIX_TIMESTAMP(NOW())) LIMIT 1';
        }
        return $this->runQuery(
            $query,
            $parameters,
            true
        );
    }

    /**
     * @param string $sAccountGUID
     * @param string $sParentAccountGUID
     * @return bool
     */
    public function changeAccountParent($sAccountGUID, $sParentAccountGUID): bool
    {
        $this->runQuery(
            'UPDATE `accounts` SET `parent_guid` = :parent_guid WHERE `guid` = :guid;',
            [':parent_guid' => $sParentAccountGUID, ':guid' => $sAccountGUID]
        );
        $aAccount = $this->getAccountInfo($sAccountGUID);
        return $aAccount['parent_guid'] == $sParentAccountGUID;
    }

    /**
     * @param string $sTransactionGUID
     * @param string $sNewDescription
     * @return bool
     */
    public function changeTransactionDescription($sTransactionGUID, $sNewDescription): bool
    {
        $this->runQuery(
            'UPDATE `transactions` SET `description` = :description WHERE `guid` = :guid;',
            [':description' => $sNewDescription, ':guid' => $sTransactionGUID]
        );
        $aTransactionInfo = $this->runQuery(
            'SELECT * FROM `transactions` WHERE `guid` = :guid;',
            [':guid' => $sTransactionGUID],
            true
        );
        return $aTransactionInfo['description'] == $sNewDescription;
    }

    /**
     * @param string $sTransactionGUID
     * @param string $sNewAmount
     * @return bool
     */
    public function changeTransactionAmount($sTransactionGUID, $sNewAmount): bool
    {
        // TODO: How to calculate the value/quantity based on value/quantity denominators.
        $this->runQuery(
            'UPDATE `splits` SET `value_num` = :value_num, `quantity_num` = :quantity_num WHERE `tx_guid` = :tx_guid AND `value_num` < 0;',
            [':value_num' => $sNewAmount * -1 * 100, ':quantity_num' => $sNewAmount * -1 * 100, ':tx_guid' => $sTransactionGUID]
        );
        $this->runQuery(
            'UPDATE `splits` SET `value_num` = :value_num, `quantity_num` = :quantity_num WHERE `tx_guid` = :tx_guid AND `value_num` > 0;',
            [':value_num' => $sNewAmount * 100, ':quantity_num' => $sNewAmount * 100, ':tx_guid' => $sTransactionGUID]
        );
        /** @noinspection PhpUnusedLocalVariableInspection */
        $aTransactionInfo = $this->getTransactionInfo($sTransactionGUID);
        // TODO: Verify.
        return true;
    }

    /**
     * @param string $sTransactionGUID
     * @param string $sNewDate
     * @return bool
     * @throws \PDOException
     * @throws Exception
     */
    public function changeTransactionDate($sTransactionGUID, $sNewDate): bool
    {
        $this->runQuery(
            'UPDATE `transactions` SET `post_date` = :post_date, `enter_date` = :enter_date WHERE `guid` = :guid;',
            [':post_date' => $sNewDate, ':enter_date' => $sNewDate, ':guid' => $sTransactionGUID]
        );
        $aTransaction = $this->runQuery(
            'SELECT * FROM `transactions` WHERE `guid` = :guid;',
            [':guid' => $sTransactionGUID],
            true
        );
        $oNewDate = new DateTime($sNewDate);
        $oPostDate = new DateTime($aTransaction['post_date']);
        $oEnterDate = new DateTime($aTransaction['enter_date']);
        return $oNewDate == $oPostDate and $oNewDate == $oEnterDate;
    }

    /**
     * @return array
     * @noinspection PhpUnused
     */
    public function getDatabases(): array
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
