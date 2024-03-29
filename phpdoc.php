<?php /** @noinspection PhpPropertyNamingConventionInspection */
/** @noinspection PhpMultipleClassesDeclarationsInOneFile */
/** @noinspection PhpIllegalPsrClassPathInspection */
declare(strict_types=1);
/** @noinspection PhpPropertyNamingConventionInspection */
/** @noinspection PhpClassNamingConventionInspection */

/** @noinspection PhpMultipleClassesDeclarationsInOneFile */

namespace PhpDoc {

    use Puggan\GnuCashMatcher\Models\Combined\BankTransactionMatchingAcconts;
    use Puggan\GnuCashMatcher\Models\Combined\BankTransactionMatchingSplits;

    /**
     * Class saldo_sum
     * @property string guid
     * @property string post_date
     * @property string description
     * @property float mv
     * @property string ov
     * @property int prediction_id
     */
    class tr_sum
    {
    }

    /**
     * Class saldo_sum
     * @property int prediction_id
     * @property string name
     * @property string prediction_date
     * @property int value
     */
    class tr_prediction
    {
    }


    /**
     * Class saldo_sum
     * @property string code
     * @property string name
     * @property float v
     * @property int c
     */
    class saldo_sum
    {
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
     * @property BankTransactionMatchingAcconts[][]|BankTransactionMatchingSplits[][] $matches
     * @property string md5
     */
    class bank_transactions_cache
    {
    }

    /**
     * Class table_db_result_account_name_rows_balance
     * @package PhpDoc
     */
    class table_db_result_account_name_rows_balance
    {
        public $account;
        public $edate;
        public $erowc;
        public $fdate;
        public $missingPos;
        public $missingNeg;
        public $name;
        public $prowc;
        public $rowc;

        public $pos;
        public $neg;
        public $saldo;
    }

    /**
     * Class table_db_result_account_name_rows_bad_amount
     * @package PhpDoc
     */
    class table_db_result_account_name_rows_bad_amount
    {
        public $account;
        public $account_guid;
        public $erows;
        public $f_date;
        public $name;
        public $rows;
        public $t_date;
    }

    /**
     * Class table_db_result_missing_splits
     * @property string guid
     * @property string tx_guid
     * @property string account_guid
     * @property string memo
     * @property string action
     * @property string reconcile_state
     * @property string reconcile_date
     * @property string value_num
     * @property string value_denom
     * @property string quantity_num
     * @property string quantity_denom
     * @property string lot_guid
     * @property string currency_guid
     * @property string num
     * @property string post_date
     * @property string enter_date
     * @property string description
     * @property string bdate
     * @property string vdate
     * @property string vnr
     * @property string vtext
     * @property string amount
     * @property string saldo
     * @property string account
     * @property string bank_tid
     * @property string bank_t_row
     */
    class table_db_result_missing_splits
    {
    }

    /**
     * Class table_db_result_row_odd_match
     * @property int row_count
     * @property int account_count
     * @property string[] accounts
     * @property string matchtext
     */
    class table_db_result_row_odd_match
    {
    }

    /**
     * Class table_db_result_row_text_match
     * @property string code
     * @property string name
     * @property string connections
     * @property int|float amount_from
     * @property int|float amount_to
     * @property string date_from
     * @property string date_to
     */
    class table_db_result_row_text_match
    {
    }

    /**
     * Class table_db_result_row_value_date_description_guid
     * @property string row
     * @property string value
     * @property string date
     * @property string description
     * @property string guid
     * @property string other_account
     */
    class table_db_result_row_value_date_description_guid
    {
    }
}
