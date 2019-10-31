<?php
declare(strict_types=1);

use Puggan\GnuCashMatcher\Models\BankTransaction;

require_once __DIR__ . '/bank_funk.php';

$bankInterface = new Bank_interface();
$database = $bankInterface->database();

$database->write(
    'DELETE gnclock FROM gnclock INNER JOIN gnclock_ts USING (PID, Hostname) WHERE ts < NOW() - INTERVAL 1 HOUR'
);
$database->write(
    "UPDATE transactions SET post_date = post_date + INTERVAL 12 HOUR WHERE DATE(post_date) < DATE(CONVERT_TZ(post_date, 'UTC', 'SYSTEM'))"
);
$database->write(
    'UPDATE bank_transactions LEFT JOIN splits ON (splits.guid = bank_transactions.bank_tid) SET bank_transactions.bank_tid = NULL WHERE splits.guid IS NULL AND bank_transactions.bank_tid IS NOT NULL'
);

$query = "SELECT * FROM bank_transactions WHERE bank_tid IS NULL AND bdate >= '2016-09-01'";
/**
 * @var int $rowNr
 * @var BankTransaction $bankRow
 */
foreach ($database->g_objects($query, 'bank_t_row', BankTransaction::class) as $rowNr => $bankRow) {
    $bankRow->saveCache($database);
}

$query = "DELETE bank_transactions_cache FROM bank_transactions_cache LEFT JOIN bank_transactions USING (bank_t_row) WHERE bank_transactions.bank_t_row IS NULL OR bank_tid IS NOT NULL OR bdate < '2016-09-01'";
$database->write($query);
