<?php

use Models\Account;

require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/token_auth.php';

if (empty($_GET['account'])) {
    header('Location: 	accounts.php');
    exit();
}

$code = (int) $_GET['account'];
$db = Auth::new_db();

$accounts = Account::all($db, 'code');

if (empty($accounts[$code])) {
    header('Location: 	accounts.php');
    exit();
}

if (isset($_POST['tx_guid']) and isset($_POST['prediction_id'])) {
    $p_parts = explode(':', $_POST['prediction_id'], 2);
    $p_guid = $db->quote($_POST['tx_guid']);
    $p_id = $db->quote($p_parts[0]);
    $p_date = $db->quote($p_parts[1]);
    $query = "UPDATE prediction_dates SET tx_guid = {$p_guid} WHERE tx_guid IS NULL AND prediction_id = {$p_id} AND prediction_date = {$p_date}";
    $db->write($query);
}

$account = $accounts[$code];

$aguid = $db->quote($account->guid);

$query = <<<SQL_BLOCK
SELECT
	transactions.guid,
	transactions.post_date,
	transactions.description,
	ms.value_num/ms.value_denom as mv,
	GROUP_CONCAT(CONCAT(accounts.code, ':', os.value_num/os.value_denom)) AS ov,
	prediction_dates.prediction_id
FROM splits AS ms
   INNER JOIN transactions ON (transactions.guid = ms.tx_guid)
   INNER JOIN splits AS os ON (transactions.guid = os.tx_guid AND ms.guid <> os.guid)
   INNER JOIN accounts ON (accounts.guid = os.account_guid)
   LEFT JOIN prediction_dates ON (prediction_dates.tx_guid = transactions.guid)
WHERE ms.account_guid = {$aguid}
GROUP BY transactions.guid
ORDER BY transactions.post_date, transactions.guid
SQL_BLOCK;

$sum = 0;
$trs = [];

/** @var \PhpDoc\tr_sum $o */
foreach ($db->g_objects($query, 'code') as $o) {
    $sum += $o->mv;
    $ovl = explode(',', $o->ov);
    sort($ovl);
    $c = count($ovl);
    $td = '				<td>';
    if ($c > 1) {
        $td = '				<td rowspan="' . $c . '">';
    }
    $parts = [
        '			<tr class="real_row">',
        $td . ($o->prediction_id ? '' : '<input name="tx_guid" type="radio" value="' . htmlentities(
                $o->guid
            ) . '" />') . '</td>',
        $td . htmlentities(substr($o->post_date, 0, 10)) . '</td>',
        $td . number_format($o->mv, 2, '.', ' ') . '</td>',
        $td . htmlentities($o->description) . '</td>',
    ];
    foreach ($ovl as $l) {
        [$lc, $lv] = explode(':', $l);
        $parts[] = '				<td>' . htmlentities($accounts[$lc]->name) . '</td>';
        $parts[] = '				<td>' . number_format($lv, 2, '.', ' ') . '</td>';
        $parts[] = '         </tr>';
        $parts[] = '         <tr class="merged_row">';
    }

    if ($c & 1) {
        array_pop($parts);
    } else {
        $parts[] = '</tr>';
    }
    $trs[] = implode(PHP_EOL, $parts);
}
$trs[] = '         <tr class="real_row"><td colspan="2">Sum:</td><td>' . number_format(
        $sum,
        2,
        '.',
        ' '
    ) . '</td></tr>';

$prediction_trs = [];
$query = <<<SQL_BLOCK
SELECT
	prediction_id,
	predictions.name,
	prediction_date,
	prediction_splits.value
FROM prediction_splits
	INNER JOIN prediction_dates USING (prediction_id, prediction_date)
	INNER JOIN predictions USING (prediction_id)
WHERE prediction_splits.code = {$code}
	AND prediction_dates.tx_guid IS NULL
ORDER BY prediction_date, prediction_id
SQL_BLOCK;
/** @var \PhpDoc\tr_prediction $p */
foreach ($db->g_objects($query) as $p) {
    $sum += $p->value;
    $prediction_trs[] = implode(
        PHP_EOL,
        [
            '         <tr>',
            '            <td><input name="prediction_id" type="radio" value="' . htmlentities(
                $p->prediction_id . ':' . $p->prediction_date
            ) . '" /></td>',
            '            <td>' . htmlentities($p->prediction_date) . '</td>',
            '            <td>' . number_format($p->value, 2, '.', ' ') . '</td>',
            '            <td>' . htmlentities($p->name) . '</td>',
            '         </tr>',
        ]
    );
}

if ($prediction_trs) {
    $trs[] = '			<tr><th colspan="6">Predictions</th></tr>';
    $trs[] .= implode(PHP_EOL, $prediction_trs);
    $trs[] = '         <tr class="real_row"><td colspan="2">Predicted Sum:</td><td>' . number_format(
            $sum,
            2,
            '.',
            ' '
        ) . '</td></tr>';
}

$account_name = htmlentities($account->name);
echo <<<HTML_BLOCK
<html>
	<head>
		<title>Account {$account_name}</title>
		<style type="text/css">
			TD {padding: 5px; text-align: right;}
			TR.real_row TD:nth-child(2) {text-align: left;}
			TR.merged_row TD:first-child {text-align: left;}
			TD:nth-child(4) {text-align: left;}
			TR:nth-child(odd) TD {background-color: #EEE;}
			.ov_code {float: left; margin-right: 1em;}
			.ov_row {clear: all;}
			TR.real_row TD {border-top: solid 1px black;}
			TR.real_row:first-child TD {border-top: none;}
		</style>
	</head>
	<body>
		<form method="post">
		<h1>Account  {$account_name}</h1>
		<table style="table-layout: fixed;">
			<colgroup>
				<col style="width: 2em;" />
				<col style="width: 6em;" />
				<col style="width: 6em;" />
				<col />
				<col />
				<col style="width: 6em;" />
			</colgroup>
			<thead>
				<tr>
					<th>&nbsp;</th>
					<th>Date</th>
					<th>Amount</th>
					<th>Transaction</th>
					<th>From / To</th>
					<th>Sum</th>
				</tr>
			</thead>
			<tbody>

HTML_BLOCK;
echo implode(PHP_EOL, $trs);
echo <<<HTML_BLOCK
			</tbody>
		</table>
		<input type="submit" value="Connect" />
		</form>
	</body>
</html>

HTML_BLOCK;
