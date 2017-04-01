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
			try
			{
				$this->con = new PDO("mysql:host=$sHostname;dbname=$sDbName", $sUsername, $sPassword, [PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8']);
				$this->con->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
			}
			catch(PDOException $e)
			{
				$this->eException = $e;
			}
		}

		public function getErrorMessage()
		{
			if($this->eException)
			{
				return $this->eException->getMessage();
			}
			return '';
		}

		public function getErrorCode()
		{
			if($this->eException)
			{
				return $this->eException->getCode();
			}
			return 0;
		}

		public function runQuery($sSql, $aParameters = array(), $bReturnFirst = FALSE)
		{
			$this->lastQuery = strtr($sSql, $aParameters);
			try
			{
				$q = $this->con->prepare($sSql);
				$result = $q->execute($aParameters);
				$query_type = explode(' ', preg_replace("#\\s+#", ' ', $sSql))[0];
				switch(strtoupper($query_type))
				{
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
				$aReturn = array();
				while($aRow = $q->fetch())
				{
					if($bReturnFirst)
					{
						return $aRow;
					}
					$aReturn[] = $aRow;
				}
				return $aReturn;
			}
			catch(PDOException $e)
			{
				$this->eException = $e;
			}
		}

		public function getNewGUID()
		{
			mt_srand((double) microtime() * 10000);

			while(TRUE)
			{
				$sTempGUID = strtolower(md5(uniqid(rand(), TRUE)));
				//  Theoretically there is an extremely small chance that there are duplicates.
				//  However, why not?
				if(!$this->GUIDExists($sTempGUID))
				{
					return $sTempGUID;
				}
			}
		}

		public function GUIDExists($sGUID)
		{
			$this->runQuery("USE `information_schema`;");
			$aTables = $this->runQuery(
				"SELECT * FROM `TABLES` WHERE `TABLE_SCHEMA` LIKE :dbname;",
				array(':dbname' => $this->sDbName)
			);
			$this->runQuery("USE `{$this->sDbName}`;");
			foreach($aTables as $aTable)
			{
				$aGUIDs = $this->runQuery(
					"SELECT * FROM `{$aTable['TABLE_NAME']}` WHERE `guid` LIKE :guid;",
					array(':guid' => $sGUID)
				);
				if($aGUIDs)
				{
					return TRUE;
				}
			}
			return FALSE;
		}

		public function getAccountInfo($sAccountGUID)
		{
			return $this->runQuery(
				"SELECT * FROM `accounts` WHERE `guid` = :guid ORDER BY code, name",
				array(':guid' => $sAccountGUID),
				TRUE
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
			$unsorted_accounts = array_column($this->getAccounts(), NULL, 'guid');
			$sorted_accounts = array();
			foreach($this->getSortedAccountGUIDs() as $guid)
			{
				if(isset($unsorted_accounts[$guid]))
				{
					$sorted_accounts[] = $unsorted_accounts[$guid];
				}
			}
			return $sorted_accounts;
		}

		public function getSortedAccountGUIDs()
		{
			$guids = array();
			foreach(array_column($this->runQuery("SELECT guid FROM accounts WHERE parent_guid IS NULL"), 'guid') as $root_guid)
			{
				$child_guids = $this->childGUIDs($root_guid);
				if($child_guids[0] != $root_guid)
				{
					$guids = array_merge($guids, $child_guids);
				}
			}
			return $guids;
		}

		public function childGUIDs($sParentGUID)
		{
			$child_guids = array();
			foreach(array_column($this->runQuery("SELECT guid FROM accounts WHERE parent_guid = :parent_guid ORDER BY code, name", array(':parent_guid' => $sParentGUID)), 'guid') as $childGUID)
			{
				$child_guids = array_merge($child_guids, $this->childGUIDs($childGUID));
			}
			if($child_guids)
			{
				return $child_guids;
			}
			else
			{
				return array($sParentGUID);
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
			return $this->runQuery($query, array(':guid' => $sAccountGUID));
		}

		public function getTransactionInfo($sGUID)
		{
			$aSplits = $this->runQuery(
				"SELECT * FROM `splits` WHERE `tx_guid` = :guid;",
				array(':guid' => $sGUID)
			);
			return array($this->getTransaction($sGUID), $aSplits);

		}

		public function getTransaction($sGUID)
		{
			return $this->runQuery(
				"SELECT * FROM `transactions` WHERE `guid` = :guid;",
				array(':guid' => $sGUID)
			);
		}

		public function getSplit($sGUID)
		{
			return $this->runQuery(
				"SELECT * FROM `splits` WHERE `guid` = :guid;",
				array(':guid' => $sGUID),
				TRUE
			);
		}

		public function isLocked()
		{
			// Bad juju to edit the database when it's locked.
			// I've done tests, and you can but the desktop client won't reflect changes that it didn't make.
			//  -So you can add a transaction while the desktop client is open but it won't show until you restart it.
			$aLocks = $this->runQuery("SELECT * FROM `gnclock`;");
			if($aLocks)
			{
				return $aLocks[0];
			}
			return FALSE;
		}

		public function createTransaction($sDebitGUID, $sCreditGUID, $fAmount, $sName, $sDate, $sMemo)
		{
			if($this->isLocked())
			{
				return 'Database is locked';
			}
			// Transaction GUID, same for both debit and credit entries in transactions.
			$sTransactionGUID = $this->getNewGUID();
			$this->lastTxGUID = $sTransactionGUID;
			if(!$sTransactionGUID)
			{
				return 'Failed to get a new transaction GUID.';
			}
			$aDebbitAccount = $this->getAccountInfo($sDebitGUID);
			if(!$aDebbitAccount)
			{
				return 'Failed to retrieve account for GUID: ' . $sDebitGUID . '.';
			}
			$aCreditAccount = $this->getAccountInfo($sCreditGUID);
			if(!$aCreditAccount)
			{
				return 'Failed to retrieve account for GUID: ' . $sCreditGUID . '.';
			}
			if($aDebbitAccount['commodity_guid'] == $aCreditAccount['commodity_guid'])
			{
				$sCurrencyGUID = $aDebbitAccount['commodity_guid'];
				$sCurrencySCU = max($aDebbitAccount['commodity_scu'], $aCreditAccount['commodity_scu']);
				$fDebbitPrice = 1;
				$fCreditPrice = 1;
			}
			else
			{
				$aRootAccount = $this->getAccountCommodity();
				$sCurrencyGUID = $aRootAccount['commodity_guid'];
				$sCurrencySCU = $aRootAccount['commodity_scu'];

				if($aDebbitAccount['commodity_guid'] == $aRootAccount['commodity_guid'])
				{
					$fDebbitPrice = 1;
				}
				else
				{
					$aDebbitPrice = $this->getCommodityPrice($aDebbitAccount['commodity_guid'], $sCurrencyGUID, $sDate);
					if($aDebbitPrice)
					{
						$fDebbitPrice = $aDebbitPrice['value_num'] / $aDebbitPrice['value_denom'];
					}
					else
					{
						$fDebbitPrice = 1;
					}
				}
				if($aCreditAccount['commodity_guid'] == $aRootAccount['commodity_guid'])
				{
					$fCreditPrice = 1;
				}
				else
				{
					$aCreditPrice = $this->getCommodityPrice($aCreditAccount['commodity_guid'], $sCurrencyGUID, $sDate);
					if($aCreditPrice)
					{
						$fCreditPrice = $aCreditPrice['value_num'] / $aCreditPrice['value_denom'];
					}
					else
					{
						$fCreditPrice = 1;
					}
				}
			}

			if(!$sCurrencyGUID)
			{
				return 'Currency GUID is empty.';
			}
			$sSplitDebitGUID = $this->getNewGUID();
			if(!$sSplitDebitGUID)
			{
				return 'Failed to get a new GUID for split 1.';
			}
			$sSplitCreditGUID = $this->getNewGUID();
			if(!$sSplitCreditGUID)
			{
				return 'Failed to get a new GUID for split 2.';
			}
			// Time may change during the execution of this function.
			$sEnterDate = date('Y-m-d H:i:s', time());

			$this->runQuery(
				"INSERT INTO `transactions` (`guid`, `currency_guid`, `num`, `post_date`, `enter_date`, `description`) VALUES (:guid, :currency_guid, :num, :post_date, :enter_date, :description);",
				array(
					':guid' => $sTransactionGUID,
					':currency_guid' => $sCurrencyGUID,
					':num' => '',
					':post_date' => $sDate,
					':enter_date' => $sEnterDate,
					':description' => $sName
				)
			);
			$sTransactionMessage = $this->eException->getMessage();
			$aTransaction = $this->getTransaction($sTransactionGUID);
			$this->runQuery(
				"INSERT INTO `splits` (`guid`, `tx_guid`, `account_guid`, `memo`, `action`, `reconcile_state`, `reconcile_date`, `value_num`, `value_denom`, `quantity_num`, `quantity_denom`) VALUES (:guid, :tx_guid, :account_guid, :memo, :action, :reconcile_state, :reconcile_date, :value_num, :value_denom, :quantity_num, :quantity_denom);",
				array(
					':guid' => $sSplitDebitGUID,
					':tx_guid' => $sTransactionGUID,
					':account_guid' => $sDebitGUID,
					':memo' => $sMemo,
					':reconcile_state' => 'n',
					':reconcile_date' => NULL,
					':action' => '',
					':value_num' => round($fAmount * $sCurrencySCU),
					':value_denom' => $sCurrencySCU,
					':quantity_num' => round($fAmount * $aDebbitAccount['commodity_scu'] / $fDebbitPrice),
					':quantity_denom' => $aDebbitAccount['commodity_scu']
				)
			);
			$sDebitMessage = $this->eException->getMessage();
			$aSplitDebit = $this->getSplit($sSplitDebitGUID);
			$this->runQuery(
				"INSERT INTO `splits` (`guid`, `tx_guid`, `account_guid`, `memo`, `action`, `reconcile_state`, `reconcile_date`, `value_num`, `value_denom`, `quantity_num`, `quantity_denom`) VALUES (:guid, :tx_guid, :account_guid, :memo, :action, :reconcile_state, :reconcile_date, :value_num, :value_denom, :quantity_num, :quantity_denom);",
				array(
					':guid' => $sSplitCreditGUID,
					':tx_guid' => $sTransactionGUID,
					':account_guid' => $sCreditGUID,
					':memo' => '',
					':reconcile_state' => 'n',
					':reconcile_date' => NULL,
					':action' => '',
					':value_num' => -1 * round($fAmount * $sCurrencySCU),
					':value_denom' => $sCurrencySCU,
					':quantity_num' => -1 * round($fAmount * $aCreditAccount['commodity_scu'] / $fCreditPrice),
					':quantity_denom' => $aCreditAccount['commodity_scu']
				)
			);
			$sCreditMessage = $this->eException->getMessage();
			$aSplitCredit = $this->getSplit($sSplitCreditGUID);

			if($aTransaction and $aSplitDebit and $aSplitCredit)
			{
				return '';
			}
			// Something happened, delete what was entered.
			$this->deleteTransaction($sTransactionGUID);
			if(!$aTransaction or !$aSplitDebit or !$aSplitCredit)
			{
				$sError = 'Error:' . ($this->getErrorMessage() ? ' ' . $this->getErrorMessage() . '.' : '');
				if(!$aTransaction)
				{
					$sError .= ' Failed to create transaction record: <b>' . $sTransactionMessage . '</b>';
				}
				if(!$aSplitDebit)
				{
					$sError .= ' Failed to create debit split: <b>' . $sDebitMessage . '</b>';
				}
				if(!$aSplitCredit)
				{
					$sError .= ' Failed to create credit split: <b>' . $sCreditMessage . '</b>';
				}
				return $sError;
			}
			return 'Some other error.';
		}

		public function deleteTransaction($sTransactionGUID)
		{
			if($this->isLocked())
			{
				return FALSE;
			}
			$this->runQuery(
				"DELETE FROM `transactions` WHERE `guid` = :guid;",
				array(':guid' => $sTransactionGUID)
			);
			$this->runQuery(
				"DELETE FROM `splits` WHERE `tx_guid` = :guid;",
				array(':guid' => $sTransactionGUID)
			);

			// Verify entries were deleted.
			$aTransaction = $this->getTransactionInfo($sTransactionGUID);
			if($aTransaction[0] or $aTransaction[1])
			{
				return FALSE;
			}
			return TRUE;
		}

		public function setReconciledStatus($sTransactionGUID, $bReconciled)
		{
			$sReconciled = ($bReconciled ? 'n' : 'c');
			$this->runQuery(
				"UPDATE `splits` SET `reconcile_state` = :reconcile_state WHERE `tx_guid` = :tx_guid;",
				array(':reconcile_state' => $sReconciled, ':tx_guid' => $sTransactionGUID)
			);
			$aTransactions = $this->runQuery(
				"SELECT * FROM `splits` WHERE `tx_guid` = :tx_guid;",
				array(':tx_guid' => $sTransactionGUID)
			);
			$bSet = TRUE;
			foreach($aTransactions as $aTransaction)
			{
				if($aTransaction['reconcile_state'] != $sReconciled)
				{
					$bSet = FALSE;
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
				array(':parent_guid' => $sParentGUID)
			);
		}

		public function renameAccount($sAccountGUID, $sNewAccountName)
		{
			$this->runQuery(
				"UPDATE `accounts` SET `name` = :name WHERE `guid` = :guid;",
				array(':name' => $sNewAccountName, ':guid' => $sAccountGUID)
			);
			$aAccount = $this->runQuery(
				"SELECT * FROM `accounts` WHERE `guid` = :guid;",
				array(':guid' => $sAccountGUID),
				TRUE
			);
			return ($sNewAccountName == $aAccount['name']);
		}

		public function deleteAccount($sAccountGUID)
		{
			$aChildAccounts = $this->getChildAccounts($sAccountGUID);
			if($aChildAccounts)
			{
				return array(0, 'Account has child accounts, can&rsquo;t delete.');
			}
			$aAccount = $this->getAccountInfo($sAccountGUID);
			if($aAccount['account_type'] == 'ROOT')
			{
				return array(0, 'Can&rsquo;t delete the root account.');
			}
			$aTransactions = $this->getAccountTransactions($sAccountGUID);
			foreach($aTransactions as $aTransaction)
			{
				$this->deleteTransaction($aTransaction['tx_guid']);
			}
			$this->runQuery(
				"DELETE FROM `accounts` WHERE `guid` = :guid;",
				array(':guid' => $sAccountGUID)
			);
			return array(1, '');
			// TODO: Delete scheduled transactions and other entries that reference this account guid.
		}

		public function createAccount($sName, $sAccountType, $sCommodityGUID, $sParentAccountGUID)
		{
			$aAccountExists = $this->runQuery(
				"SELECT * FROM `accounts` WHERE `parent_guid` = :parent_guid AND `account_name` = :account_name AND `type` = :type;",
				array(':parent_guid' => $sParentAccountGUID, ':name' => $sName, ':account_type' => $sAccountType)
			);
			if($aAccountExists)
			{
				return FALSE;
			}
			$aCommodity = $this->runQuery(
				"SELECT * FROM `commodities` WHERE `guid` = :guid;",
				array(':guid' => $sCommodityGUID),
				TRUE
			);
			$sAccountGUID = $this->getNewGUID();
			$query = <<<SQL_BLOCK
INSERT INTO `accounts` (`guid`, `name`, `account_type`, `commodity_guid`, `commodity_scu`, `non_std_scu`, `parent_guid`, `hidden`, `placeholder`)
VALUES (:guid, :name, :account_type, :commodity_guid, :commodity_scu, :non_std_scu, :parent_guid, :hidden, :placeholder);
SQL_BLOCK;
			$this->runQuery(
				$query,
				array(
					':guid' => $sAccountGUID,
					':name' => $sName,
					':account_type' => $sAccountType,
					':commodity_guid' => $aCommodity['guid'],
					':commodity_scu' => $aCommodity['fraction'],
					':non_std_scu' => 0,
					':parent_guid' => $sParentAccountGUID,
					':hidden' => 0,
					':placeholder' => 0
				)
			);
			$aNewAccount = $this->getAccountInfo($sAccountGUID);
			return !empty($aNewAccount);
		}

		public function getCommodities()
		{
			return $this->runQuery("SELECT * FROM `commodities`;");
		}

		public function getAccountCommodity($sAccountGUID = NULL)
		{
			// get commodity for given account
			if($sAccountGUID)
			{
				return $this->runQuery("SELECT commodity_guid, commodity_scu FROM accounts  WHERE guid = :guid", array(':guid' => $sAccountGUID), TRUE);
				// get commodity for root account
			}
			else
			{
				return $this->runQuery(
					"SELECT accounts.commodity_guid, accounts.commodity_scu FROM slots INNER JOIN books ON (books.guid = slots.obj_guid) INNER JOIN accounts ON (accounts.guid = books.root_account_guid) WHERE slots.name LIKE 'options'",
					NULL,
					TRUE
				);
			}
		}

		public function getCommodityPrice($sCommodityGUID, $sCurrencyGUID, $sDate)
		{
			if($sDate)
			{
				return $this->runQuery(
					"SELECT value_num, value_denom FROM prices WHERE commodity_guid = :commodity_guid AND currency_guid = :currency_guid ORDER BY ABS(UNIX_TIMESTAMP(:date) - UNIX_TIMESTAMP(NOW())) LIMIT 1",
					array(':commodity_guid' => $sCommodityGUID, 'currency_guid' => $sCurrencyGUID, ':date' => $sDate),
					TRUE
				);
			}
			else
			{
				return $this->runQuery(
					"SELECT value_num, value_denom FROM prices WHERE commodity_guid = :commodity_guid AND currency_guid = :currency_guid ORDER BY ABS(UNIX_TIMESTAMP(date) - UNIX_TIMESTAMP(NOW())) LIMIT 1",
					array(':commodity_guid' => $sCommodityGUID, 'currency_guid' => $sCurrencyGUID),
					TRUE
				);
			}
		}

		public function changeAccountParent($sAccountGUID, $sParentAccountGUID)
		{
			$this->runQuery(
				"UPDATE `accounts` SET `parent_guid` = :parent_guid WHERE `guid` = :guid;",
				array(':parent_guid' => $sParentAccountGUID, ':guid' => $sAccountGUID)
			);
			$aAccount = $this->getAccountInfo($sAccountGUID);
			return ($aAccount['parent_guid'] == $sParentAccountGUID);
		}

		public function changeTransactionDescription($sTransactionGUID, $sNewDescription)
		{
			$this->runQuery(
				"UPDATE `transactions` SET `description` = :description WHERE `guid` = :guid;",
				array(':description' => $sNewDescription, ':guid' => $sTransactionGUID)
			);
			$aTransactionInfo = $this->runQuery(
				"SELECT * FROM `transactions` WHERE `guid` = :guid;",
				array(':guid' => $sTransactionGUID),
				TRUE
			);
			return ($aTransactionInfo['description'] == $sNewDescription);
		}

		public function changeTransactionAmount($sTransactionGUID, $sNewAmount)
		{
			// TODO: How to calculate the value/quantity based on value/quantity denominators.
			$this->runQuery(
				"UPDATE `splits` SET `value_num` = :value_num, `quantity_num` = :quantity_num WHERE `tx_guid` = :tx_guid AND `value_num` < 0;",
				array(':value_num' => ($sNewAmount * -1) * 100, ':quantity_num' => ($sNewAmount * -1) * 100, ':tx_guid' => $sTransactionGUID)
			);
			$this->runQuery(
				"UPDATE `splits` SET `value_num` = :value_num, `quantity_num` = :quantity_num WHERE `tx_guid` = :tx_guid AND `value_num` > 0;",
				array(':value_num' => $sNewAmount * 100, ':quantity_num' => $sNewAmount * 100, ':tx_guid' => $sTransactionGUID)
			);
			$aTransactionInfo = $this->getTransactionInfo($sTransactionGUID);
			// TODO: Verify.
			return TRUE;
		}

		public function changeTransactionDate($sTransactionGUID, $sNewDate)
		{
			$this->runQuery(
				"UPDATE `transactions` SET `post_date` = :post_date, `enter_date` = :enter_date WHERE `guid` = :guid;",
				array(':post_date' => $sNewDate, ':enter_date' => $sNewDate, ':guid' => $sTransactionGUID)
			);
			$aTransaction = $this->runQuery(
				"SELECT * FROM `transactions` WHERE `guid` = :guid;",
				array(':guid' => $sTransactionGUID),
				TRUE
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
			foreach($aDatabases as $aDatabase)
			{
				if(in_array($aDatabase['Database'], ['information_schema', 'performance_schema', 'mysql']))
				{
					continue;
				}
				$aReturn[] = $aDatabase['Database'];
			}
			return $aReturn;
		}
	}
