<?php
declare(strict_types=1);

use Puggan\GnuCashMatcher\Auth;
use Puggan\GnuCashMatcher\Models\BankTransaction;

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/vendor/autoload.php';

$database = Auth::newDatabase();

if (empty($_GET['account'])) {
    $query = <<<SQL_BLOCK
SELECT
	bank_transactions.account,
	accounts.name,
	MAX(IF(bank_tid IS NULL, bank_transactions.bdate, NULL)) AS edate,
	MAX(IF(bank_tid IS NULL, NULL, bank_transactions.bdate)) AS fdate,
	SUM(IF(bank_tid IS NULL, GREATEST(bank_transactions.amount, 0), NULL)) AS missingPos,
	SUM(IF(bank_tid IS NULL, LEAST(bank_transactions.amount, 0), NULL)) AS missingNeg,
	COUNT(DISTINCT IF(bank_tid IS NULL, bank_transactions.bank_t_row, NULL)) AS erowc,
	COUNT(DISTINCT bank_transactions.bank_t_row) AS rowc,
	accounts.guid AS account_guid
FROM bank_transactions
	INNER JOIN accounts ON (accounts.code = bank_transactions.account)
WHERE bdate >= '2016-09-01'
GROUP BY account
SQL_BLOCK;

    $saldoQuery = <<<SQL_BLOCK
SELECT account_guid, SUM(value_num/value_denom) AS s
FROM splits
GROUP BY account_guid
SQL_BLOCK;
    /** @var string[] $saldos */
    $saldos = $database->read($saldoQuery, 'account_guid', 's');

    echo <<<HTML_BLOCK
<html>
	<head>
		<title>Select bank account</title>
		<style type="text/css">
			TH, TD {border: solid gray 1px; padding: 5px;}
			TD {text-align: right;}
			TD:first-child {text-align: left;}
			TR.odd {background-color: #FFFFCC;}
			TR.even {background-color: #CCFFFF;}
		</style>
	</head>
	<body>
		<h1>Select bank account</h1>
		<table>
			<thead>
				<tr>
					<th>Account Name</th>
					<th>Last Empty</th>
					<th>Last Connected</th>
					<th>Empty</th>
					<th>Total</th>
					<th>% Empty</th>
					<th>Income</th>
					<th>Fees</th>
					<th>Saldo</th>
				</tr>
			</thead>
			<tbody>

HTML_BLOCK;

    $isOdd = false;
    /** @var \PhpDoc\table_db_result_account_name_rows_balance $dbRow */
    foreach ($database->g_objects($query) as $dbRow) {
        $dbRow->prowc = (int) (100 * $dbRow->erowc / $dbRow->rowc);
        $dbRow->pos = $dbRow->missingPos > 0 ? number_format(+$dbRow->missingPos, 2, '.', ' ') : '';
        $dbRow->neg = $dbRow->missingNeg < 0 ? number_format(+$dbRow->missingNeg, 2, '.', ' ') : '';
        $dbRow->saldo = number_format(
            empty($saldos[$dbRow->account_guid]) ? 0 : $saldos[$dbRow->account_guid] + $dbRow->missingPos + $dbRow->missingNeg,
            2,
            '.',
            ' '
        );
        /** @var \PhpDoc\table_db_result_account_name_rows_balance $rowHtml */
        $rowHtml = (object) array_map('htmlentities', (array) $dbRow);

        $rowClass = ($isOdd = !$isOdd) ? 'odd' : 'even';
        echo <<<HTML_BLOCK
				<tr class="{$rowClass}">
					<td><a href="bank2.php?account={$rowHtml->account}">{$rowHtml->name}</a></td>
					<td>{$rowHtml->edate}</td>
					<td>{$rowHtml->fdate}</td>
					<td>{$rowHtml->erowc}</td>
					<td>{$rowHtml->rowc}</td>
					<td>{$rowHtml->prowc} %</td>
					<td>{$rowHtml->pos}</td>
					<td>{$rowHtml->neg}</td>
					<td>{$rowHtml->saldo}</td>
				</tr>

HTML_BLOCK;

        $links[$dbRow->account] = "<a href=\"?account={$dbRow->account}\">" . htmlentities(
                $dbRow->name
            ) . "</a> ({$dbRow->erowc} / {$dbRow->rowc})";
    }

    echo <<<HTML_BLOCK
			</tbody>
		</table>
		<p><a href="./bank_import.php">Import from bank</a></p>
		<p><a href="./bank_odd_matches.php">Odd Matches</a></p>
		<p><a href="./bank_missing.php">Missing at bank</a></p>
		<p><a href="./food.php">Find dublicate lunches</a></p>
	</body>
</html>
HTML_BLOCK;
    die();
}

/** @var string[] $accountIds */
$accountIds = $database->read('SELECT code, guid FROM `accounts` WHERE LENGTH(code) = 4 ORDER BY code', 'code', 'guid');
$accountNames = $database->read(
    'SELECT code, name FROM `accounts` WHERE LENGTH(code) = 4 ORDER BY code',
    'code',
    'name'
);
$accountNamesHtml = array_map('htmlentities', $accountNames);
$selectedAccount = (int) $_GET['account'];

$editRow = $_GET['row'] ?? 0;
if ($editRow) {
    $newGuid = $_GET['guid'] ?? '';
    if (!$newGuid) {
        $date = $_POST['date'] ?? null;
        $amount = $_POST['amount'] ?? null;
        $fromAccount = $_POST['from'] ?? null;
        $toAccount = $_POST['to'] ?? null;
        $text = $_POST['text'] ?? null;
        $amount = strtr($amount, [',' => '.', ' ' => '']);

        if ($date) {
            $date = date('Y-m-d', strtotime($date));
        }

        if ($fromAccount) {
            $fromAccount = $accountIds[$fromAccount] ?? null;
        }

        if ($toAccount) {
            $toAccount = $accountIds[$toAccount] ?? null;
        }

        if ($date && $amount && $fromAccount && $toAccount && $text) {
            $gnuCash = Auth::newGnuCash();

            if ($gnuCash->GUIDExists($fromAccount) && $gnuCash->GUIDExists($toAccount)) {
                $error = $gnuCash->createTransaction($toAccount, $fromAccount, $amount, $text, $date, '');

                if ($gnuCash->lastTxGUID) {
                    $txGuidSql = $database->quote($gnuCash->lastTxGUID);
                    $accountGuidSql = $database->quote($accountIds[$selectedAccount]);
                    $query = <<<SQL_BLOCK
SELECT guid
FROM splits
WHERE tx_guid = {$txGuidSql}
	AND account_guid = {$accountGuidSql}
SQL_BLOCK;
                    /** @var string[] $matchingSplits */
                    $matchingSplits = $database->read($query, null, 'guid');
                    if (count($matchingSplits)) {
                        $newGuid = $matchingSplits[0];
                    }
                } else {
                    echo $error;
                }
            }
        }
    }
    if ($newGuid) {
        $newGuidSql = $database->quote($newGuid);
        $query = <<<SQL_BLOCK
UPDATE bank_transactions
SET bank_tid = {$newGuidSql}
WHERE bank_tid IS NULL
	AND bank_t_row = {$editRow}
SQL_BLOCK;
        $database->write($query);
    }
}

$options = ['<option value="">-- Select Account --</option>'];
foreach ($accountNames as $accountCode => $accountName) {
    $accountNameHtml = htmlentities($accountName);
    $options[] = "<option value=\"{$accountCode}\">{$accountNameHtml}</option>";
}
$options = implode(PHP_EOL, $options);

$skipCount = 0;
$orderBy = 'bdate DESC';

if (isset($_GET['skip'])) {
    $skipCount = (int) $_GET['skip'];
    if (empty($_GET['limit'])) {
        $limitCount = 20;
    } else {
        $limitCount = (int) $_GET['limit'];
    }
    $limit = "{$limitCount} OFFSET {$skipCount}";
    $skipUrlHtml = "&amp;limit={$limitCount}&amp;skip={$skipCount}";
    $filter = '';
} elseif (isset($_GET['q'])) {
    $filter = 'AND bank_transactions.vtext LIKE ' . $database->quote('%' . $_GET['q'] . '%');
    $limit = 50000;
    $skipUrlHtml = '&amp;q=' . htmlentities(urlencode($_GET['q']));
} elseif (isset($_GET['a'])) {
    $amount = (int) $_GET['a'];
    $filter = 'AND ((bank_transactions.amount BETWEEN ' . ($amount - 5) . ' AND ' . ($amount + 5) . ') OR (bank_transactions.amount BETWEEN ' . (0 - $amount - 5) . ' AND ' . (5 - $amount) . '))';
    $limit = 50000;
    $skipUrlHtml = '&amp;a=' . $amount;
} else {
    $limit = 500;
    $skipUrlHtml = '';
    $filter = '';
}

if (isset($_GET['old'])) {
    $orderBy = 'bdate';
    $skipUrlHtml .= '&amp;old';
}

if (!isset($limitCount)) {
    $limitCount = $limit;
}

$query = <<<SQL_BLOCK
SELECT *
FROM bank_transactions
WHERE bank_tid IS NULL
	AND bdate >= '2016-09-01'
	AND account = {$selectedAccount}
	{$filter}
ORDER BY {$orderBy}
LIMIT {$limit}
SQL_BLOCK;

echo <<<HTML_BLOCK
<html>
	<head>
		<title>Unmatched transactions of account: {$selectedAccount}</title>
		<link rel="stylesheet" href="lib/chosen/chosen.css" />
		<link rel="stylesheet" href="https://ajax.googleapis.com/ajax/libs/jqueryui/1.12.1/themes/smoothness/jquery-ui.css" />
		<style type="text/css">
			SELECT {min-width: 300px;}
			FIELDSET.inc.odd {background-color: #CCFFCC;}
			FIELDSET.inc.even {background-color: #EEFFEE;}
			FIELDSET.dec.odd {background-color: #FFCCCC;}
			FIELDSET.dec.even {background-color: #FFEEEE;}
			FIELDSET.dec {background-color: #FF0000;}
		</style>
		<script type="text/javascript">
			function setAccount(element, account)
			{
				var current_form = element;
				while(current_form.parentNode && current_form.tagName != 'FORM' && current_form.tagName != 'HTML' & current_form.tagName != 'BODY')
				{
					current_form = current_form.parentNode;
				}
				var selects = current_form.getElementsByTagName('select');
				var selects_count = selects.length;
				for(var si = 0; si < selects_count; si++)
				{
					var s = selects[si];
					if(!s.value || s.value === '')
					{
						s.value = account;
						$(s).trigger("chosen:updated");
					}
				}
			}
		</script>
	</head>
	<body>
		<h1>Unmatched transactions of account: {$accountNamesHtml[$selectedAccount]}</h1>

HTML_BLOCK;

$accountOption = "<option value=\"{$selectedAccount}\">{$accountNamesHtml[$selectedAccount]}</option>";

$isOdd = false;
$rowCount = 0;

/** @var BankTransaction $btRow */
foreach ($database->objects($query) as $btRow) {
    $baseUrl = "?account={$selectedAccount}{$skipUrlHtml}&amp;row={$btRow->bank_t_row}";
    $rowCount++;

    if ($btRow->amount > 0) {
        $fromOption = $options;
        $toOption = $accountOption;
        $amount = $btRow->amount;
    } else {
        $fromOption = $accountOption;
        $toOption = $options;
        $amount = -$btRow->amount;
    }

    $text = htmlentities($btRow->vtext);
    $class = (($isOdd = !$isOdd) ? 'odd' : 'even') . ' ' . ($btRow->amount > 0 ? 'inc' : 'dec');

    if (preg_match("#/(\d\d-\d\d-\d\d)$#", $text, $matches)) {
        $text = trim(substr($text, 0, -9));
        $date = 20 . $matches[1];
    } else {
        $date = $btRow->bdate;
    }

    echo <<<HTML_BLOCK
		<form method="post" action="{$baseUrl}">
			<fieldset class="{$class}">
				<legend>{$btRow->amount} kr @ {$btRow->bdate}</legend>

				<label>
					<span>Date:</span>
					<input type="date" name="date" value="{$date}" />
				</label>

				<label>
					<span>Amount:</span>
					<input type="number" name="amount" value="{$amount}" />
				</label>

				<label>
					<span>From:</span>
					<select name="from">
						{$fromOption}
					</select>
				</label>

				<label>
					<span>To:</span>
					<select name="to">
						{$toOption}
					</select>
				</label>

				<br />

				<label>
					<span>Text:</span>
					<input type="text" name="text" value="{$text}" style="width: 900px; min-width: 150px; max-width: 100%;"/>
				</label>

				<label>
					<input type="submit" value="Add Row" />
				</label>
				
HTML_BLOCK;

    $query = <<<SQL_BLOCK
SELECT
	bank_transactions.bank_t_row AS row,
	splits.value_num / splits.value_denom AS value,
	transactions.post_date AS date,
	transactions.description,
	splits.guid,
	COALESCE(account2.name, '') AS other_account
FROM bank_transactions
	INNER JOIN accounts ON (accounts.code = bank_transactions.account)
	INNER JOIN splits ON (splits.account_guid = accounts.guid)
	INNER JOIN transactions ON (transactions.guid = splits.tx_guid)
	LEFT JOIN bank_transactions AS used ON (used.bank_tid = splits.guid)
	LEFT JOIN splits AS split2 ON (split2.tx_guid = splits.tx_guid AND split2.value_num = -splits.value_num)
	LEFT JOIN accounts AS account2 ON (account2.guid = split2.account_guid)
WHERE bank_transactions.bank_t_row = {$btRow->bank_t_row}
	AND used.bank_t_row IS NULL
	AND transactions.post_date BETWEEN bank_transactions.bdate - INTERVAL 1 WEEK AND bank_transactions.bdate + INTERVAL 1 WEEK
	AND splits.value_num - bank_transactions.amount * splits.value_denom BETWEEN -100 AND 100
GROUP BY splits.guid
ORDER BY ABS(splits.value_num - bank_transactions.amount * splits.value_denom),
	ABS(UNIX_TIMESTAMP(transactions.post_date) - UNIX_TIMESTAMP(bank_transactions.bdate))
SQL_BLOCK;

    $count = 0;
    /** @var \PhpDoc\table_db_result_row_value_date_description_guid $matchRow */
    foreach ($database->objects($query) as $matchRow) {
        if (!$count++) {
            echo <<<HTML_BLOCK
						<h3>Match sugestions (TR)</h3>
						<ul>
						
HTML_BLOCK;
        }
        $description = htmlentities($matchRow->description);
        $linkUrl = "{$baseUrl}&amp;guid={$matchRow->guid}";
        $date = substr($matchRow->date, 0, 10);
        $otherAccount = $matchRow->other_account ? ' (' . htmlentities($matchRow->other_account) . ')' : '';
        echo <<<HTML_BLOCK
						<li><a href="{$linkUrl}">{$matchRow->value} @ {$date}: {$description}</a>{$otherAccount}</li>

HTML_BLOCK;
    }

    if ($count) {
        echo <<<HTML_BLOCK
					</ul>

HTML_BLOCK;
    }

    $query = <<<SQL_BLOCK
SELECT
	accounts.code,
	accounts.name,
	COUNT(t2.amount) AS 'connections',
	MIN(ABS(t2.amount)) AS 'amount_from',
	MAX(ABS(t2.amount)) AS 'amount_to',
	MIN(DATE(transactions.post_date)) AS 'date_from',
	MAX(DATE(transactions.post_date)) AS 'date_to'
FROM bank_transactions AS t1
	INNER JOIN bank_transactions AS t2 ON (
		IF(t2.vtext RLIKE '/[0-9][0-9]-[0-9][0-9]-[0-9][0-9]$', SUBSTRING(t2.vtext, 1, LENGTH(t2.vtext) - 9), t2.vtext)
		=
		IF(t1.vtext RLIKE '/[0-9][0-9]-[0-9][0-9]-[0-9][0-9]$', SUBSTRING(t1.vtext, 1, LENGTH(t1.vtext) - 9), t1.vtext)
	)
	INNER JOIN splits ON (splits.guid = t2.bank_tid)
	INNER JOIN transactions ON (transactions.guid = splits.tx_guid)
	INNER JOIN splits AS s2 ON (s2.tx_guid = transactions.guid AND s2.guid <> splits.guid)
	INNER JOIN accounts ON (accounts.guid = s2.account_guid)
WHERE t1.bank_t_row = {$btRow->bank_t_row}
GROUP BY accounts.guid
ORDER BY
	IF({$btRow->amount} BETWEEN MIN(t2.amount) AND MAX(t2.amount), 0, 1),
	COUNT(t2.amount) DESC,
	MAX(DATE(transactions.post_date)) DESC
SQL_BLOCK;

    $count = 0;

    /** @var \PhpDoc\table_db_result_row_text_match $match_row */
    foreach ($database->objects($query) as $matchRow) {
        if (!$count++) {
            echo <<<HTML_BLOCK
						<h3>Match sugestions (Text)</h3>
						<ul>
						
HTML_BLOCK;
        }
        $otherAccountName = htmlentities($matchRow->name);
        if ($matchRow->connections > 1) {
            echo <<<HTML_BLOCK
						<li onclick="setAccount(this, '{$matchRow->code}')">{$otherAccountName}, {$matchRow->connections} connections, amount in range {$matchRow->amount_from} - {$matchRow->amount_to}, dates in range {$matchRow->date_from} - {$matchRow->date_to}</li>

HTML_BLOCK;
        } else {
            echo <<<HTML_BLOCK
						<li onclick="setAccount(this, '{$matchRow->code}')">{$otherAccountName}, {$matchRow->connections} connections, amount {$matchRow->amount_from}, date {$matchRow->date_from}</li>

HTML_BLOCK;
        }
    }

    if ($count) {
        echo <<<HTML_BLOCK
					</ul>

HTML_BLOCK;
    }

    echo <<<HTML_BLOCK
				<br />
			</fieldset>
		</form>

HTML_BLOCK;
}

if ($limitCount) {
    $relativeUrl = "?account={$selectedAccount}&amp;limit={$limitCount}";
    if (isset($_GET['old'])) {
        $orderBy = 'bdate';
        $relativeUrl .= '&amp;old';
    }

    $links = [];
    if ($skipCount) {
        $links[] = "<a href=\"{$relativeUrl}&amp;skip=0\">&lt;&lt;-</a>";

        if ($skipCount > $limitCount) {
            $skipBack = $skipCount - $limitCount;
            $links[] = "<a href=\"{$relativeUrl}&amp;skip={$skipBack}\">&lt;-</a>";
        }
    }
    $page = 1 + floor($skipCount / $limitCount);
    $first = $skipCount + 1;
    $last = $skipCount + $rowCount;
    $links[] = "<strong>{$page}</strong>";
    $links[] = "<span>({$first} - {$last})</span>";
    if ($rowCount > 0 && $rowCount === $limitCount) {
        $skipNext = $skipCount + $limitCount;
        $links[] = "<a href=\"{$relativeUrl}&amp;skip={$skipNext}\">-&gt;</a>";
    }
    echo '<p>' . implode('<span> &nbsp; &nbsp; </span>', $links) . '</p>';
}

echo <<<HTML_BLOCK
	    <p><a href="?">&laquo; Account view</a></p>
	
		<script src="https://ajax.googleapis.com/ajax/libs/jquery/1.12.4/jquery.js" type="text/javascript"></script>
		<script src="https://ajax.googleapis.com/ajax/libs/jqueryui/1.12.1/jquery-ui.js" type="text/javascript"></script>
		<script src="lib/chosen/chosen.jquery.js" type="text/javascript"></script>
		<script src="lib/chosen/docsupport/prism.js" type="text/javascript" charset="utf-8"></script>
		<script type="text/javascript">
			$( function() {
				$('select').chosen({disable_search_threshold: 2});
				$.datepicker.setDefaults({ dateFormat: 'yy-mm-dd' });
				var dateselectors = $('INPUT[type=date]');
				dateselectors.datepicker();
				dateselectors.each(function() {    
					$(this).datepicker('setDate', $(this).val());
				});
		   });
		</script>
	</body>
</html>

HTML_BLOCK;
