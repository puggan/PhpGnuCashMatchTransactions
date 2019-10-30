<?php

require_once(__DIR__ . "/gnucach.php");

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');

set_error_handler(
    function ($errno, $errstr, $errfile, $errline) {
        die(
        json_encode(
            ['error_code' => $errno, 'message' => $errstr, 'return' => null, 'where' => "{$errfile}:{$errline}"]
        )
        );
    }
);

require_once("auth.php");

/**
 * Class Index
 * @property string $sVersion
 * @property GnuCash $cGnuCash
 * @property string[]|int[] $aReturn
 * @property mixed[] $aData
 * @property string[] $aAccountTypes
 */
class Index extends Auth
{
    public $sVersion = '2.1.0';

    public $cGnuCash;
    public $aReturn = ['return' => 0, 'message' => '', 'error_code' => 0];
    public $aData;

    public $aAccountTypes = ['INCOME', 'EXPENSE', 'BANK', 'ASSET', 'EQUITY', 'CREDIT', 'LIABILITY', 'RECEIVABLE', 'CASH'];

    /**
     * Index constructor.
     */
    public function __construct()
    {
        if (isset($_GET['func'])) {
            $this->aData = [
                'func' => $_GET['func'],
                'login' => [
                    'username' => isset($_GET['user']) ? $_GET['user'] : "",
                    'password' => isset($_GET['pass']) ? $_GET['pass'] : "",
                ],
            ];
            if ($_GET['func'] === 'test_connection') {
                $this->aData['test_connection'] = true;
            }
            if ($_GET['func'] === 'test_credentials') {
                $this->aData['test_credentials'] = true;
            }
        } else {
            if (!isset($_POST['data'])) {
                $this->done("no data");
            }
            $sData = base64_decode($_POST['data']);
            $this->aData = json_decode($sData, true);
        }

        if (isset($this->aData['test_connection'])) {
            $this->aReturn['return'] = 1;
            // If appPassword, tell app we don't have the password, to enable password-fields
            if ($this->sAppPassword) {
                $this->aReturn['hardcoded_credentials'] = 0;
                $this->aReturn['username'] = "";
                $this->aReturn['password'] = "";
                $this->aReturn['database_server'] = "";
                $this->aReturn['database'] = "";
            } else {
                $this->aReturn['hardcoded_credentials'] = 1;
                $this->aReturn['username'] = $this->sUsername;
                $this->aReturn['password'] = $this->sPassword ? "yes" : "";
                $this->aReturn['database_server'] = $this->sDatabaseServer;
                $this->aReturn['database'] = $this->sDatabase;
            }
            $this->done();
        }

        if (!$this->sDatabaseServer) {
            $this->sDatabaseServer = '127.0.0.1';
        }
        if ($this->sAppPassword) {
            if (!isset($this->aData['login']['username'])) {
                $this->done("Username missing");
            }
            if (!isset($this->aData['login']['password'])) {
                $this->done("Password missing");
            }
            if (!isset($this->sAppPassword[$this->aData['login']['username']])) {
                $this->done("User missing");
            }
            if (!$this->verify($this->aData['login']['username'], $this->aData['login']['password'])) {
                $this->done("Wrong password");
            }
        }

        $this->cGnuCash = new GnuCash($this->sDatabaseServer, $this->sDatabase, $this->sUsername, $this->sPassword);

        if ($this->cGnuCash->getErrorCode()) {
            $this->aReturn['message'] = "Database connection failed.<br /><b>{$this->cGnuCash->getErrorMessage()}</b>";
            $this->aReturn['error_code'] = $this->cGnuCash->getErrorCode();
            $this->done();
        } else {
            if (isset($this->aData['test_credentials'])) {
                $this->aReturn['return'] = 1;
                $this->aReturn['databases'] = [$this->sDatabase];
                $this->aReturn['database'] = $this->sDatabase;
                $this->done();
            } else {
                if (!$this->cGnuCash->getAccounts()) {
                    $this->aReturn['message'] = 'No database specified.';
                    if ($this->sDatabase) {
                        $this->aReturn['message'] = "No accounts found, double check the database: {$this->sDatabase}";
                    }
                    $this->done();
                }
            }
        }

        if (isset($this->aData['func'])) {
            $sFunction = $this->aData['func'];
            switch ($sFunction) {
                case 'appCheckSettings':
                    $this->appCheckSettings();
                    break;

                case 'appFetchAccounts':
                    $this->appFetchAccounts();
                    break;

                case 'appGetAccountDescriptions':
                    $this->appGetAccountDescriptions();
                    break;

                case 'appCreateTransaction':
                    $this->appCreateTransaction();
                    break;

                case 'appDeleteTransaction':
                    $this->appDeleteTransaction();
                    break;

                case 'appGetAccountTransactions':
                    $this->appGetAccountTransactions();
                    break;

                case 'appUpdateTransactionReconciledStatus':
                    $this->appUpdateTransactionReconciledStatus();
                    break;

                case 'appGetAccountHeirarchy':
                    $this->appGetAccountHeirarchy();
                    break;

                case 'appRenameAccount':
                    $this->appRenameAccount();
                    break;

                case 'appDeleteAccount':
                    $this->appDeleteAccount();
                    break;

                case 'appCreateAccount':
                    $this->appCreateAccount();
                    break;

                case 'appGetCreateAccountDialog':
                    $this->appGetCreateAccountDialog();
                    break;

                case 'appChangeAccountParent':
                    $this->appChangeAccountParent();
                    break;

                case 'appChangeTransactionDescription':
                    $this->appChangeTransactionDescription();
                    break;

                case 'appChangeTransactionAmount':
                    $this->appChangeTransactionAmount();
                    break;

                case 'appChangeTransactionDate':
                    $this->appChangeTransactionDate();
                    break;

                default:
                    if (!method_exists($this, $sFunction)) {
                        $this->done('unknown function: ' . $sFunction);
                    }
                    $this->$sFunction();
                    break;
            }
        }
        $this->done();
    }

    /**
     * Exit
     *
     * @param string $sMessage
     */
    private function done($sMessage = null)
    {
        if ($sMessage) {
            $this->aReturn['message'] = $sMessage;
        }
        exit(json_encode($this->aReturn));
    }

    /**
     * Disallow changes while database is locked
     */
    private function checkDatabaseLock()
    {
        $aLock = $this->cGnuCash->isLocked();
        if ($aLock) {
            $this->aReturn['message'] = "GnuCash database is locked by: {$aLock['Hostname']}";
            $this->done();
        }
    }

    /**
     * api call: appCheckSettings
     */
    private function appCheckSettings()
    {
        $this->aReturn['return'] = 1;
        $this->aReturn['version'] = $this->sVersion;
        $this->aReturn['message'] = 'Settings verified.';
    }

    /**
     * api call: appFetchAccounts
     */
    private function appFetchAccounts()
    {
        $this->aReturn['return'] = 1;
        $this->aReturn['accounts'] = [];

        foreach ($this->cGnuCash->getSortedAccounts() as $aAccount) {
            $sPrefix = $aAccount['account_type'] . ': ';
            if (strpos($sPrefix, 'INCOME') !== false) {
                $sPrefix = 'Income: ';
            } else {
                if (strpos($sPrefix, 'EXPENSE') !== false) {
                    $sPrefix = 'Expenses: ';
                } else {
                    if (strpos($sPrefix, 'BANK') !== false) {
                        $sPrefix = 'Bank: ';
                    } else {
                        if (strpos($sPrefix, 'ROOT') !== false) {
                            $sPrefix = 'Root: ';
                        } else {
                            if (strpos($sPrefix, 'PAYABLE') !== false) {
                                $sPrefix = 'A/P: ';
                            } else {
                                if (strpos($sPrefix, 'RECEIVABLE') !== false) {
                                    $sPrefix = 'A/R: ';
                                } else {
                                    if (strpos($sPrefix, 'CREDIT') !== false) {
                                        $sPrefix = 'Card: ';
                                    } else {
                                        if (strpos($sPrefix, 'ASSET') !== false) {
                                            $sPrefix = 'Asset: ';
                                        } else {
                                            if (strpos($sPrefix, 'EQUITY') !== false) {
                                                $sPrefix = 'Equity: ';
                                            } else {
                                                if (strpos($sPrefix, 'LIABILITY') !== false) {
                                                    $sPrefix = 'Liability: ';
                                                } else {
                                                    if (strpos($sPrefix, 'CASH') !== false) {
                                                        $sPrefix = 'Cash: ';
                                                    }
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
            $this->aReturn['accounts'][] = [
                'name' => "$sPrefix{$aAccount['name']}",
                'simple_name' => $aAccount['name'],
                'count' => $aAccount['Count'],
                'guid' => $aAccount['guid'],
                'is_parent' => false,
            ];
        }
    }

    /**
     * api call: appGetAccountDescriptions
     */
    private function appGetAccountDescriptions()
    {
        $sAccountGUID = $this->aData['account_guid'];
        $aTransactions = $this->cGnuCash->getAccountTransactions($sAccountGUID);

        $this->aReturn['return'] = 1;
        $this->aReturn['descriptions'] = [];
        $aDescriptions = [];

        foreach ($aTransactions as $aTransaction) {
            $aTransactionInfo = $this->cGnuCash->getTransactionInfo($aTransaction['tx_guid']);
            foreach ($aTransactionInfo[1] as $aTransactionSplit) {
                if (!in_array(
                        $aTransaction['description'],
                        $aDescriptions
                    ) and $aTransactionSplit['account_guid'] != $aTransaction['account_guid']) {
                    $aDescriptions[] = $aTransaction['description'];
                    $aTransferToAccount = $this->cGnuCash->getAccountInfo($aTransactionSplit['account_guid']);
                    $this->aReturn['descriptions'][] = [
                        'title' => $aTransaction['description'],
                        'description' => $aTransferToAccount['name'],
                        'guid' => $aTransactionSplit['account_guid']
                    ];
                }
            }
        }
    }

    /**
     * api call: appCreateTransaction
     */
    private function appCreateTransaction()
    {
        $this->checkDatabaseLock();
        $sDebitGUID = $this->aData['debit_guid'];
        $sCreditGUID = $this->aData['credit_guid'];
        $fAmount = strtr($this->aData['amount'], [',' => '.', ' ' => '']);
        $sDescription = $this->aData['description'];
        $sDate = $this->aData['date'];
        if (!$sDate) {
            $sDate = date('Y-m-d H:i:s', time());
        } else {
            $sDate = date('Y-m-d H:i:s', strtotime($sDate));
        }
        if (empty($this->aData['memo'])) {
            $sMemo = '';
        } else {
            $sMemo = $this->aData['memo'];
        }

        if (!$this->cGnuCash->GUIDExists($sDebitGUID)) {
            $this->aReturn['message'] = "GUID: $sDebitGUID does not exist for to account.";
        } else {
            if (!$this->cGnuCash->GUIDExists($sCreditGUID)) {
                $this->aReturn['message'] = "GUID: $sCreditGUID does not exist for from account.";
            } else {
                if (!is_numeric($fAmount)) {
                    $this->aReturn['message'] = "$fAmount is not a valid number.";
                } else {
                    if (empty($sDescription)) {
                        $this->aReturn['message'] = 'Please enter a name for this transaction.';
                    } else {
                        if (empty($sDate) or !(bool) strtotime($sDate)) {
                            $this->aReturn['message'] = 'Please enter a valid date for this transaction.';
                        } else {
                            $this->aReturn['message'] = $this->cGnuCash->createTransaction(
                                $sDebitGUID,
                                $sCreditGUID,
                                $fAmount,
                                $sDescription,
                                $sDate,
                                $sMemo
                            );
                            if ($this->aReturn['message']) {
                            } else {
                                $this->aReturn['return'] = 1;
                                $this->aReturn['message'] = 'Transaction successful.';
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * api call: appDeleteTransaction
     */
    private function appDeleteTransaction()
    {
        $sTransactionGUID = $this->aData['guid'];

        if (!$this->cGnuCash->GUIDExists($sTransactionGUID)) {
            $this->aReturn['message'] = "GUID: $sTransactionGUID does not exist.";
        } else {
            if (!$this->cGnuCash->deleteTransaction($sTransactionGUID)) {
                $this->aReturn['message'] = 'Failed to delete transaction.';
            } else {
                $this->aReturn['return'] = 1;
                $this->aReturn['message'] = 'Successfully deleted transaction.';
            }
        }
    }

    /**
     * api call: appGetAccountTransactions
     */
    private function appGetAccountTransactions()
    {
        $sAccountGUID = $this->aData['guid'];
        $this->aReturn['transactions'] = [];
        $rstates = ['c' => true, 'y' => true];

        $aTransactions = $this->cGnuCash->getAccountTransactions($sAccountGUID);
        if ($aTransactions) {
            $this->aReturn['return'] = 1;
            foreach ($aTransactions as $aTransaction) {
                $aDate = explode(' ', $aTransaction['post_date']);
                $this->aReturn['transactions'][] = [
                    'guid' => $aTransaction['tx_guid'],
                    'description' => $aTransaction['description'],
                    'amount' => number_format(($aTransaction['value_num'] / $aTransaction['value_denom']), 2),
                    'memo' => $aTransaction['memo'],
                    'date' => date('m-d-y', strtotime($aDate[0])),
                    'reconciled' => isset($rstates[$aTransaction['reconcile_state']]),
                ];
            }
        } else {
            $this->aReturn['message'] = 'No transactions for this account.';
        }
    }

    /**
     * api call: appUpdateTransactionReconciledStatus
     */
    private function appUpdateTransactionReconciledStatus()
    {
        $sTransactionGUID = $this->aData['guid'];
        $bReconciled = filter_var($this->aData['reconciled'], FILTER_VALIDATE_BOOLEAN);

        $bSet = $this->cGnuCash->setReconciledStatus($sTransactionGUID, $bReconciled);

        $this->aReturn['reconciled'] = !$bReconciled * 1;

        if ($bSet) {
            $this->aReturn['return'] = 1;
        } else {
            $this->aReturn['message'] = 'Failed to update reconciled status of transaction.';
        }
    }

    /**
     * api call: appGetAccountHeirarchy
     */
    private function appGetAccountHeirarchy()
    {
        $aAccounts = $this->cGnuCash->getAllAccounts();

        $aHeirarchy = [];

        foreach ($aAccounts as $aAccount) {
            if (!$aAccount['parent_guid']) {
                $this->copyAccounts($aAccount, $aHeirarchy, []);
            }
        }
        $this->aReturn['accounts'] = $aHeirarchy;
    }

    /**
     * @param string[] $aAccount
     * @param mixed[] $aHeirarchyPointer
     * @param string[] $aKeys
     */
    private function copyAccounts($aAccount, &$aHeirarchyPointer, $aKeys)
    {
        if ($aAccount['name'] === 'Template Root') {
            return;
        }
        $aTransactions = $this->cGnuCash->getAccountTransactions($aAccount['guid']);
        $fTotal = 0;
        $bAllReconciled = true;
        $rstates = ['c' => true, 'y' => true];
        foreach ($aTransactions as $aTransaction) {
            $fTotal += $aTransaction['value_num'] / $aTransaction['value_denom'];
            $bAllReconciled = ($bAllReconciled and isset($rstates[$aTransaction['reconcile_state']]));
        }
        $aNewAccount = [
            'name' => $aAccount['name'],
            'guid' => $aAccount['guid'],
            'total' => $fTotal,
            'all_transactions_reconciled' => $bAllReconciled,
            'sub_accounts' => []
        ];

        $aTempHeirarchy = &$aHeirarchyPointer;
        foreach ($aKeys as $sKey) {
            $aTempHeirarchy = &$aTempHeirarchy[$sKey]['sub_accounts'];
        }
        $aChildAccounts = $this->cGnuCash->getChildAccounts($aAccount['guid']);
        if ($aChildAccounts) {
            if (!array_key_exists($aAccount['guid'], $aTempHeirarchy)) {
                $aTempHeirarchy[$aAccount['guid']] = $aNewAccount;
            }
            $aKeys[] = $aAccount['guid'];
            foreach ($aChildAccounts as $aChildAccount) {
                copyAccounts($this, $aChildAccount, $aHeirarchyPointer, $aKeys);
            }
        } else {
            if (!in_array($aAccount['guid'], $aTempHeirarchy)) {
                $aTempHeirarchy[$aAccount['guid']] = $aNewAccount;
            }
        }
    }

    /**
     * api call: appRenameAccount
     */
    public function appRenameAccount()
    {
        $this->aReturn['return'] = $this->cGnuCash->renameAccount(
                $this->aData['guid'],
                $this->aData['new_account_name']
            ) * 1;
    }

    /**
     * api call: appDeleteAccount
     */
    public function appDeleteAccount()
    {
        $aReturn = $this->cGnuCash->deleteAccount($this->aData['guid']);
        $this->aReturn['return'] = $aReturn[0] * 1;
        $this->aReturn['message'] = $aReturn[1];
    }

    /**
     * api call: appCreateAccount
     */
    public function appCreateAccount()
    {
        $sName = $this->aData['name'];
        $sAccountType = $this->aData['account_type'];
        $sCommodityGUID = $this->aData['commodity_guid'];
        $sParentAccountGUID = $this->aData['parent_guid'];
        $this->aReturn['return'] = $this->cGnuCash->createAccount(
            $sName,
            $sAccountType,
            $sCommodityGUID,
            $sParentAccountGUID
        );
    }

    /**
     * api call: appGetCreateAccountDialog
     */
    public function appGetCreateAccountDialog()
    {
        $sAccountTypeDropdown = '<select class="ui dropdown" name="account_type">';
        foreach ($this->aAccountTypes as $sType) {
            $sAccountTypeDropdown .= '<option value="' . $sType . '">' . $sType . '</option>';
        }
        $sAccountTypeDropdown .= '</select>';

        $aCommodities = $this->cGnuCash->getCommodities();
        $sCommodityDropdown = '<select class="ui dropdown" name="commodity_guid">';
        foreach ($aCommodities as $aCommodity) {
            $sCommodityDropdown .= '<option value="' . $aCommodity['guid'] . '">' . $aCommodity['fullname'] . '</option>';
        }
        $sCommodityDropdown .= '</select>';
        $this->aReturn['form_id'] = 'fmNewAccount';
        $this->aReturn['html'] = '
        <form class="ui inverted form" id="' . $this->aReturn['form_id'] . '">
            <input type="hidden" name="parent_guid" val="" />
            <div class="field">
                <label>Name</label>
                <input name="name" type="text" />
            </div>
            <div class="field">
                <label>Type</label>
                ' . $sAccountTypeDropdown . '
            </div>
            <div class="field">
                <label>Currency</label>
                ' . $sCommodityDropdown . '
            </div>
        </form>
        ';
    }

    /**
     * api call: appChangeAccountParent
     */
    public function appChangeAccountParent()
    {
        $this->aReturn['return'] = $this->cGnuCash->changeAccountParent(
                $this->aData['guid'],
                $this->aData['parent_guid']
            ) * 1;
    }

    /**
     * api call: appChangeTransactionDescription
     */
    public function appChangeTransactionDescription()
    {
        $this->aReturn['return'] = $this->cGnuCash->changeTransactionDescription(
            $this->aData['transaction_guid'],
            $this->aData['new_description']
        );
    }

    /**
     * api call: appChangeTransactionAmount
     */
    public function appChangeTransactionAmount()
    {
        $this->aReturn['return'] = $this->cGnuCash->changeTransactionAmount(
            $this->aData['transaction_guid'],
            $this->aData['new_amount']
        );
    }

    /**
     * api call: appChangeTransactionDate
     */
    public function appChangeTransactionDate()
    {
        $sDate = date('Y-m-d H:i:s', strtotime($this->aData['new_date']));
        $this->aReturn['return'] = $this->cGnuCash->changeTransactionDate($this->aData['transaction_guid'], $sDate);
    }
}

new Index();
