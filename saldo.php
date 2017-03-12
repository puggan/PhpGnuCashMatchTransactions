<?php

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

	require_once(__DIR__ . "/auth.php");

	$db = Auth::new_db();
	$sum = 0;
	$trs = array();

	foreach($db->g_read($query, 'd', 'v') as $d => $v)
	{
		$sum += $v;
		$trs[] = "			<tr><td>" . htmlentities($d) . "</td><td>" . number_format($v, 2, '.', ' ') . "</td><td>" . number_format($sum, 2, '.', ' ') . "</td></li>";
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
	echo implode("\n", $trs);
	echo <<<HTML_BLOCK
			</tbody>
		</table>
	</body>
</html>

HTML_BLOCK;

