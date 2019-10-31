<?php
declare(strict_types=1);

use Puggan\GnuCashMatcher\Auth;
use Puggan\GnuCashMatcher\Models\Account;

require_once __DIR__ . '/token_auth.php';

if (empty($_GET['account'])) {
    header('Location: 	accounts.php');
    exit();
}

$code = (int) $_GET['account'];
$database = Auth::newDatabase();

$accounts = Account::all($database, 'code');

if (empty($accounts[$code])) {
    header('Location: 	accounts.php');
    exit();
}

if (isset($_POST['tx_guid'], $_POST['prediction_id'])) {
    $predictionParts = explode(':', $_POST['prediction_id'], 2);
    $predictionGuid = $database->quote($_POST['tx_guid']);
    $predictionId = $database->quote($predictionParts[0]);
    $predictionDate = $database->quote($predictionParts[1]);
    $query = "UPDATE prediction_dates SET tx_guid = {$predictionGuid} WHERE tx_guid IS NULL AND prediction_id = {$predictionId} AND prediction_date = {$predictionDate}";
    $database->write($query);
}

$account = $accounts[$code];

$aguid = $database->quote($account->guid);

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

$total = 0;
$transactions = [];

/** @var \PhpDoc\tr_sum $sums */
foreach ($database->g_objects($query, 'code') as $sums) {
    $total += $sums->mv;
    $otherValuesList = explode(',', $sums->ov);
    sort($otherValuesList);
    $otherValuesCount = count($otherValuesList);
    $tdHtml = '				<td>';
    if ($otherValuesCount > 1) {
        $tdHtml = '				<td rowspan="' . $otherValuesCount . '">';
    }
    $parts = [
        '			<tr class="real_row">',
        $tdHtml . ($sums->prediction_id ? '' : '<input name="tx_guid" type="radio" value="' . htmlentities(
                $sums->guid
            ) . '" />') . '</td>',
        $tdHtml . htmlentities(substr($sums->post_date, 0, 10)) . '</td>',
        $tdHtml . number_format($sums->mv, 2, '.', ' ') . '</td>',
        $tdHtml . htmlentities($sums->description) . '</td>',
    ];
    foreach ($otherValuesList as $otherValuesPair) {
        [$otherCode, $otherValue] = explode(':', $otherValuesPair, 2);
        $parts[] = '				<td>' . htmlentities($accounts[$otherCode]->name) . '</td>';
        $parts[] = '				<td>' . number_format($otherValue, 2, '.', ' ') . '</td>';
        $parts[] = '         </tr>';
        $parts[] = '         <tr class="merged_row">';
    }

    if ($otherValuesCount & 1) {
        array_pop($parts);
    } else {
        $parts[] = '</tr>';
    }
    $transactions[] = implode(PHP_EOL, $parts);
}
$transactions[] = '         <tr class="real_row"><td colspan="2">Sum:</td><td>' . number_format(
        $total,
        2,
        '.',
        ' '
    ) . '</td></tr>';

$predictionTrs = [];
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
/** @var \PhpDoc\tr_prediction $prediction */
foreach ($database->g_objects($query) as $prediction) {
    $total += $prediction->value;
    $predictionTrs[] = implode(
        PHP_EOL,
        [
            '         <tr>',
            '            <td><input name="prediction_id" type="radio" value="' . htmlentities(
                $prediction->prediction_id . ':' . $prediction->prediction_date
            ) . '" /></td>',
            '            <td>' . htmlentities($prediction->prediction_date) . '</td>',
            '            <td>' . number_format($prediction->value, 2, '.', ' ') . '</td>',
            '            <td>' . htmlentities($prediction->name) . '</td>',
            '         </tr>',
        ]
    );
}

if ($predictionTrs) {
    $transactions[] = '			<tr><th colspan="6">Predictions</th></tr>';
    $transactions[] .= implode(PHP_EOL, $predictionTrs);
    $transactions[] = '         <tr class="real_row"><td colspan="2">Predicted Sum:</td><td>' . number_format(
            $total,
            2,
            '.',
            ' '
        ) . '</td></tr>';
}

$accountName = htmlentities($account->name);
echo <<<HTML_BLOCK
<html>
	<head>
		<title>Account {$accountName}</title>
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
		<h1>Account  {$accountName}</h1>
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
echo implode(PHP_EOL, $transactions);
echo <<<HTML_BLOCK
			</tbody>
		</table>
		<input type="submit" value="Connect" />
		</form>
	</body>
</html>

HTML_BLOCK;
