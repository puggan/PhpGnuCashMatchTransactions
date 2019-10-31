<?php
declare(strict_types=1);

use Puggan\GnuCashMatcher\Auth;

require_once __DIR__ . '/vendor/autoload.php';

$database = Auth::newDatabase();

$weeks = $database->read(
    "SELECT YEARWEEK(post_date, 1) AS y FROM transactions WHERE num = '' AND post_date < NOW() - INTERVAL 1 MONTH GROUP BY 1",
    'y',
    'y'
);

foreach ($weeks as $week) {
    $isOk = true;
    $prefix = substr($week, 0, 4) . 'w' . substr($week, 4) . 't';
    $last = 0;
    $transactions = $database->read(
        'SELECT guid, num FROM transactions WHERE YEARWEEK(post_date, 1) = ' . (int) $week . ' ORDER BY post_date, enter_date',
        'guid',
        'num'
    );
    $usedNums = [];
    foreach ($transactions as $guid => $numberString) {
        if ($numberString) {
            if (isset($usedNums[$numberString])) {
                $isOk = false;
                trigger_error("{$numberString} used in multiple transactions: {$usedNums[$numberString]}, {$guid}");
            } elseif (strpos($numberString, $prefix) !== 0) {
                $isOk = false;
                trigger_error("{$numberString} don't match {$prefix}");
            } else {
                $usedNums[$numberString] = $guid;
            }
        }
    }
    if (!$isOk) {
        continue;
    }
    foreach ($transactions as $guid => $numberString) {
        if ($numberString) {
            $number = (int) ltrim(substr($numberString, strlen($prefix)), '0');
            $last = max($number, $last);
        } else {
            $next = $last + 1;
            $newNumber = $prefix . ($next < 10 ? '0' : '') . $next;
            while (isset($usedNums[$newNumber])) {
                $next++;
                $newNumber = $prefix . ($next < 10 ? '0' : '') . $next;
            }

            $guidSql = $database->quote($guid);
            $numberSql = $database->quote($newNumber);
            $database->write("UPDATE transactions SET num = {$numberSql} WHERE guid = {$guidSql}");

            $last = $next;
            $usedNums[$newNumber] = $guid;
            echo "UPDATE transactions SET num = {$numberSql} WHERE guid = {$guidSql};\n";
        }
    }
}

/*
SELECT
   transactions.num,
   DATE(transactions.post_date),
   transactions.description,
   REPLACE(
      GROUP_CONCAT(
         DISTINCT
         FORMAT(
            ABS(splits.value_num / splits.value_denom),
            2)
         SEPARATOR ' - '),
      ',',
      '')
FROM transactions
   INNER JOIN splits ON (splits.tx_guid = transactions.guid)
WHERE transactions.num > '2016w35t01'
GROUP BY transactions.guid
ORDER BY transactions.num
INTO OUTFILE '/tmp/transactions.csv';
*/
