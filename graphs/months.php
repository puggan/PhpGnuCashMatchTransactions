<?php
declare(strict_types=1);

use Puggan\GnuCashMatcher\Auth;

require_once __DIR__ . '/../vendor/autoload.php';

$database = Auth::newDatabase();

$query = <<<SQL_BLOCK
SELECT
	YEAR(transactions.post_date) AS year,
	MONTH(transactions.post_date) AS month,
	SUM(IF(accounts.code BETWEEN 4000 AND 8999, value_num / value_denom, 0)) AS cost,
	SUM(IF(accounts.code = 4999, value_num / value_denom, 0)) AS tax,
	SUM(IF(accounts.code BETWEEN 2100 AND 2198 AND value_num > 0, value_num / value_denom, 0)) AS loan_payment,
	SUM(IF(accounts.code BETWEEN 2100 AND 2198 AND value_num < 0, value_num / value_denom, 0)) AS loan_taken,
	SUM(IF(accounts.code BETWEEN 3000 AND 3999, value_num / value_denom, 0)) AS income,
	SUM(IF(accounts.code BETWEEN 3000 AND 3999, value_num / value_denom, 0)) AS liquidity
FROM splits INNER JOIN transactions ON (splits.tx_guid = transactions.guid) INNER JOIN accounts ON (splits.account_guid = accounts.guid)
WHERE transactions.post_date >= '2016-09-01'
GROUP BY 1, 2 ORDER BY 1 DESC, 2 DESC
SQL_BLOCK;
// WHERE (accounts.code BETWEEN 4000 AND 4900 OR accounts.code BETWEEN 8100 AND 8999)

/**
 * @param int $number number to format, put last 3 chars in class u, and the rest in class k
 * @return string
 */
function kSplit($number)
{
    /** @noinspection CallableParameterUseCaseInTypeContextInspection TODO https://github.com/JetBrains/phpstorm-stubs/pull/700 */
    $number = round($number);
    return '<span class="k">' . substr($number, 0, -3) . '</span> <span class="u">' . substr($number, -3) . '</span>';
}

$transactions = [];
foreach ($database->objects($query) as $dbRow) {
    $result = -$dbRow->income - $dbRow->cost - $dbRow->loan_payment;
    if ($result > 0) {
        $class = 'good';
    } elseif ($result > -$dbRow->loan_payment) {
        $class = 'ok';
    } else {
        $class = 'bad';
    }
    $transactions[] = '<tr class="' . $class . '"><td>' .
        $dbRow->year . '-' . str_pad($dbRow->month, 2, '0', STR_PAD_LEFT) . '-xx' .
        '</td><td>' . kSplit($dbRow->cost - $dbRow->tax) .
        '</td><td>' . kSplit($dbRow->loan_payment) .
        '</td><td>' . kSplit($dbRow->tax) .
        '</td><td>' . kSplit(-$dbRow->income) .
        '</td><td>' . kSplit(-$dbRow->income - $dbRow->tax) .
        '</td><td>' . kSplit(-$dbRow->loan_taken) .
        '</td><td>' . kSplit(-$dbRow->income - $dbRow->cost - $dbRow->loan_payment) .
        '</td></tr>';
}
$transactions = implode("\n\t\t\t\t", $transactions);
echo <<<HTML_BLOCK
<html>
	<head>
		<title>Cost per month</title>
		<style>
			TABLE
			{
				border-spacing: 1em 0.3em;
			}
			TH
			{
				border-right: solid gray 1px;
				padding: 0.5em;
			}
			TD
			{
				border-bottom: solid gray 1px;
				text-align: right;
			}
			TD .u
			{
				color: gray;
				font-size: 0.8em;
			}
			TR.good TD
			{
				background: rgba(0, 255, 0, 0.2);
			}
			TR.ok TD
			{
				background: rgba(255, 255, 0, 0.2);
			}
			TR.bad TD
			{
				background: rgba(255, 0, 0, 0.2);
			}
		</style>
	</head>
	<body>
		<h1>Cost per month</h1>
		<table>
			<thead>
				<tr>
					<th rowspan="2">Month</th>
					<th colspan="3">Costs</th>
					<th colspan="3">Income</th>
					<th rowspan="2">Result¹</th>
				<tr>
					<th>Base</th>
					<th>Loan Payments</th>
					<th>Tax</th>
					<th>Brutto</th>
					<th>Netto</th>
					<th>Loan Taken</th>
				</tr>
			</thead>
			<tbody>
				{$transactions}
			</tbody>
		</table>
	</body>
</html>
HTML_BLOCK;
