<?php

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

require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/token_auth.php';

$db = Auth::new_db();
$sum = 0;
$trs = [];

/** @var saldo_sum $o */
foreach ($db->g_objects($query, 'code') as $o) {
    $trs[] = implode(
        PHP_EOL,
        [
            '			<tr>',
            '				<td>' . ($code = htmlentities($o->code)) . '</td>',
            '				<td>' . htmlentities($o->name) . '</td>',
            '				<td>' . number_format($o->v, 2, '.', ' ') . '</td>',
            '				<td><a href="trs.php?account=' . $code . '">' . number_format(
                $o->c,
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
echo implode(PHP_EOL, $trs);
echo <<<HTML_BLOCK
			</tbody>
		</table>
	</body>
</html>

HTML_BLOCK;

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
