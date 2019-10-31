<?php
declare(strict_types=1);

use Puggan\GnuCashMatcher\Auth;

$query = <<<SQL_BLOCK
SELECT
	accounts.code,
	if(SUBSTRING(accounts.name, 1, 7) = CONCAT(accounts.code, ' - '), SUBSTRING(accounts.name, 8), accounts.name) as name,
   SUM(value_num/value_denom) AS v,
   COUNT(*) AS c
FROM splits
   INNER JOIN transactions ON (transactions.guid = splits.tx_guid)
   INNER JOIN accounts ON (accounts.guid = splits.account_guid)
GROUP BY accounts.code
SQL_BLOCK;

require_once __DIR__ . '/token_auth.php';

$database = Auth::newDatabase();
$total = 0;
$transactions = [];

/** @var \PhpDoc\saldo_sum $saldo */
foreach ($database->g_objects($query, 'code') as $saldo) {
    $transactions[] = implode(
        PHP_EOL,
        [
            '			<tr>',
            '				<td>' . ($code = htmlentities($saldo->code)) . '</td>',
            '				<td>' . htmlentities($saldo->name) . '</td>',
            '				<td>' . number_format($saldo->v, 2, '.', ' ') . '</td>',
            '				<td><a href="trs.php?account=' . $code . '">' . number_format(
                $saldo->c,
                0,
                '.',
                ' '
            ) . '</a></td>',
            '			</tr>'
        ]
    );
}

echo <<<HTML_BLOCK
<html>
	<head>
		<title>Accounts</title>
		<style type="text/css">
			TD {padding: 5px; text-align: right;}
			TD:nth-child(2) {text-align: left;}
			TR:nth-child(odd) TD {background-color: #EEE;}
		</style>
	</head>
	<body>
		<table>
			<thead>
				<tr>
					<th>Code</th>
					<th>Name</th>
					<th>Saldo</th>
					<th>Transaction count</th>
				</tr>
			</thead>
			<tbody>

HTML_BLOCK;
echo implode(PHP_EOL, $transactions);
echo <<<HTML_BLOCK
			</tbody>
		</table>
	</body>
</html>

HTML_BLOCK;
