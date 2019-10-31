<?php
declare(strict_types=1);

use Puggan\GnuCashMatcher\Auth;

$query = <<<SQL_BLOCK
SELECT
   DATE(transactions.post_date) AS d,
   SUM(value_num/value_denom) AS v
FROM splits
   INNER JOIN transactions ON (transactions.guid = splits.tx_guid)
   INNER JOIN accounts ON (accounts.guid = splits.account_guid)
WHERE accounts.code LIKE '1%'
GROUP BY 1
SQL_BLOCK;

require_once __DIR__ . '/token_auth.php';

$database = Auth::newDatabase();
$total = 0;
$transactions = [];

/** @var int[] $list */
$list = $database->g_read($query, 'd', 'v');
foreach ($list as $date => $value) {
    $total += $value;
    $transactions[] = '			<tr><td>' . htmlentities($date) . '</td><td>' . number_format(
            $value,
            2,
            '.',
            ' '
        ) . '</td><td>' . number_format($total, 2, '.', ' ') . '</td></li>';
}

echo <<<HTML_BLOCK
<html>
	<head>
		<title>Saldo</title>
		<style type="text/css">
			TD {padding: 5px; text-align: right;}
			TR:nth-child(odd) TD {background-color: #EEE;}
		</style>
	</head>
	<body>
		<table>
			<thead>
				<tr>
					<th>Date</th>
					<th>Change</th>
					<th>Saldo</th>
				</tr>
			</thead>
			<tbody>

HTML_BLOCK;
echo implode("\n", $transactions);
echo <<<HTML_BLOCK
			</tbody>
		</table>
	</body>
</html>

HTML_BLOCK;

