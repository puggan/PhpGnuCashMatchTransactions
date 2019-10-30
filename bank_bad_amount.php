<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/GnuCash.php';
require_once __DIR__ . '/token_auth.php';

$database = Auth::new_db();

$database->write(
    'UPDATE bank_transactions LEFT JOIN splits ON (splits.guid = bank_transactions.bank_tid) SET bank_transactions.bank_tid = NULL WHERE splits.guid IS NULL AND bank_transactions.bank_tid IS NOT NULL'
);

if (empty($_GET['account'])) {
    $query = <<<SQL_BLOCK
SELECT
	bank_transactions.account,
	accounts.name,
	MIN(IF(bank_tid IS NULL, NULL, bank_transactions.bdate)) AS f_date,
	MAX(IF(bank_tid IS NULL, NULL, bank_transactions.bdate)) AS t_date,
	COUNT(DISTINCT IF(bank_tid IS NULL, bank_transactions.bank_t_row, NULL)) AS erows,
	accounts.guid AS account_guid
FROM bank_transactions
	INNER JOIN accounts ON (accounts.code = bank_transactions.account)
WHERE bdate >= '2016-09-01'
GROUP BY account
SQL_BLOCK;

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
					<th>First Connected</th>
					<th>Last Connected</th>
					<th>Empty</th>
					<th>Bad amount</th>
				</tr>
			</thead>
			<tbody>

HTML_BLOCK;

    $isOdd = false;
    /** @var \PhpDoc\table_db_result_account_name_rows_bad_amount $dbRow */
    foreach ($database->g_objects($query) as $dbRow) {
        /** @var \PhpDoc\table_db_result_account_name_rows_bad_amount $rowHtml */
        $rowHtml = (object) array_map('htmlentities', (array) $dbRow);

        $accountGuidSql = $database->quote($dbRow->account_guid);
        $fDateSql = $database->quote($dbRow->f_date);
        $tDateSql = $database->quote($dbRow->t_date);
        $badAmountQuery = <<<SQL_BLOCK
SELECT COUNT(*) AS c
FROM splits
	INNER JOIN transactions ON (transactions.guid = splits.tx_guid)
	INNER JOIN bank_transactions ON (bank_transactions.bank_tid = splits.guid)
WHERE bank_transactions.amount * splits.value_denom <> splits.value_num
	AND splits.account_guid = {$accountGuidSql}
	AND transactions.post_date BETWEEN {$fDateSql} AND {$tDateSql}
SQL_BLOCK;

        $badAmount = (int) $database->get($badAmountQuery);

        $rowClass = ($isOdd = !$isOdd) ? 'odd' : 'even';
        echo <<<HTML_BLOCK
				<tr class="{$rowClass}">
					<td><a href="?account={$rowHtml->account}">{$rowHtml->name}</a></td>
					<td>{$rowHtml->f_date}</td>
					<td>{$rowHtml->t_date}</td>
					<td>{$rowHtml->erows}</td>
					<td>{$badAmount}</td>
				</tr>

HTML_BLOCK;

        $links[$dbRow->account] = "<a href=\"?account={$dbRow->account}\">" . htmlentities(
                $dbRow->name
            ) . "</a> ({$badAmount})";
    }

    echo <<<HTML_BLOCK
			</tbody>
		</table>
		<p><a href="./bank_import.php">Import from bank</a></p>
		<p><a href="./bank_odd_matches.php">Odd Matches</a></p>
		<p><a href="./bank.php">Account view</a></p>
	</body>
</html>
HTML_BLOCK;
    die();
}

/** @var string[] $accountIds */
$accountIds = $database->read('SELECT code, guid FROM `accounts` WHERE LENGTH(code) = 4 ORDER BY code', 'code', 'guid');
/** @var string[] $accountNames */
$accountNames = $database->read('SELECT code, name FROM `accounts` WHERE LENGTH(code) = 4 ORDER BY code', 'code', 'name');
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
            $gnuCash = Auth::new_gnucash();

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

if (isset($_GET['skip'])) {
    $limit = '20 OFFSET ' . (int) $_GET['skip'];
    $skipUrlHtml = '&amp;skip=' . (int) $_GET['skip'];
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

$query = <<<SQL_BLOCK
SELECT
	bank_transactions.account,
	accounts.name,
	MIN(IF(bank_tid IS NULL, NULL, bank_transactions.bdate)) AS f_date,
	MAX(IF(bank_tid IS NULL, NULL, bank_transactions.bdate)) AS t_date,
	COUNT(DISTINCT IF(bank_tid IS NULL, bank_transactions.bank_t_row, NULL)) AS erows,
	accounts.guid AS account_guid
FROM bank_transactions
	INNER JOIN accounts ON (accounts.code = bank_transactions.account)
WHERE bdate >= '2016-09-01'
	AND accounts.code = {$selectedAccount}
SQL_BLOCK;

$account_row = $database->get($query);
/** @var \PhpDoc\table_db_result_account_name_rows_bad_amount $account_row_sql */
$account_row_sql = (object) array_map([$database, 'quote'], $account_row);

$badAmountQuery = <<<SQL_BLOCK
SELECT
	'bt',
	bank_transactions.*,
	't',
	transactions.*,
	's',
	splits.*,
	'a',
	GROUP_CONCAT(al.code) AS account_codes,
	GROUP_CONCAT(al.name) AS account_names
FROM splits
	INNER JOIN transactions ON (transactions.guid = splits.tx_guid)
	INNER JOIN bank_transactions ON (bank_transactions.bank_tid = splits.guid)
	INNER JOIN splits AS s2 ON (s2.tx_guid = transactions.guid AND s2.guid <> splits.guid)
	INNER JOIN accounts AS al ON (al.guid = s2.account_guid)
WHERE bank_transactions.amount * splits.value_denom <> splits.value_num
	AND splits.account_guid = {$account_row_sql->account_guid}
GROUP BY bank_transactions.bank_tid
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

$current_account_option = "<option value=\"{$selectedAccount}\">{$accountNamesHtml[$selectedAccount]}</option>";

$isOdd = false;

/** @var \PhpDoc\table_db_result_missing_splits $bt_row */
foreach ($database->objects($badAmountQuery) as $bt_row) {
    echo '<pre>';
    print_r($bt_row);
    echo '</pre>';

    continue;
    /** @noinspection PhpUnreachableStatementInspection TODO replace debug code with real code */
    if ($bt_row->amount > 0) {
        $from_option = $options;
        $to_option = $current_account_option;
        $amount = $bt_row->amount;
    } else {
        $from_option = $current_account_option;
        $to_option = $options;
        $amount = -$bt_row->amount;
    }

    $text = htmlentities($bt_row->vtext);
    $class = (($isOdd = !$isOdd) ? 'odd' : 'even') . ' ' . ($bt_row->amount > 0 ? 'inc' : 'dec');

    if (preg_match('#/(\d\d-\d\d-\d\d)$#', $text, $m)) {
        $text = trim(substr($text, 0, -9));
        $date = 20 . $m[1];
    } else {
        $date = $bt_row->bdate;
    }

    echo <<<HTML_BLOCK
		<form method="post" action="?account={$selectedAccount}{$skipUrlHtml}&amp;row={$bt_row->bank_t_row}">
			<fieldset class="{$class}">
				<legend>{$bt_row->amount} kr @ {$bt_row->bdate}</legend>

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
						{$from_option}
					</select>
				</label>

				<label>
					<span>To:</span>
					<select name="to">
						{$to_option}
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
	IFNULL(account2.name, '') AS other_account
FROM bank_transactions
	INNER JOIN accounts ON (accounts.code = bank_transactions.account)
	INNER JOIN splits ON (splits.account_guid = accounts.guid)
	INNER JOIN transactions ON (transactions.guid = splits.tx_guid)
	LEFT JOIN bank_transactions AS used ON (used.bank_tid = splits.guid)
	LEFT JOIN splits AS split2 ON (split2.tx_guid = splits.tx_guid AND split2.value_num = -splits.value_num)
	LEFT JOIN accounts AS account2 ON (account2.guid = split2.account_guid)
WHERE bank_transactions.bank_t_row = {$bt_row->bank_t_row}
	AND used.bank_t_row IS NULL
	AND transactions.post_date BETWEEN bank_transactions.bdate - INTERVAL 1 WEEK AND bank_transactions.bdate + INTERVAL 1 WEEK
	AND splits.value_num - bank_transactions.amount * splits.value_denom BETWEEN -100 AND 100
GROUP BY
    splits.guid,
    splits.value_denom,
    splits.value_num,
    bank_transactions.amount,
    bank_transactions.bdate,
    transactions.post_date
ORDER BY ABS(splits.value_num - bank_transactions.amount * splits.value_denom),
	ABS(UNIX_TIMESTAMP(transactions.post_date) - UNIX_TIMESTAMP(bank_transactions.bdate))
SQL_BLOCK;

    $count = 0;
    /** @var \db $database why is this row needed? */
    /** @var \Models\Combined\BankTransactionMatchingSplits $match_row */
    foreach ($database->objects($query) as $match_row) {
        if (!$count++) {
            echo <<<HTML_BLOCK
						<h3>Match sugestions (TR)</h3>
						<ul>
						
HTML_BLOCK;
        }
        $description = htmlentities($match_row->description);
        $url = "?account={$selectedAccount}&amp;row={$bt_row->bank_t_row}&amp;guid={$match_row->guid}";
        $date = substr($match_row->date, 0, 10);
        $other_account = $match_row->other_account ? ' (' . htmlentities($match_row->other_account) . ')' : '';
        echo <<<HTML_BLOCK
						<li><a href="{$url}">{$match_row->value} @ {$date}: {$description}</a>{$other_account}</li>

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
WHERE t1.bank_t_row = {$bt_row->bank_t_row}
GROUP BY accounts.guid
ORDER BY
	IF({$bt_row->amount} BETWEEN MIN(t2.amount) AND MAX(t2.amount), 0, 1),
	COUNT(t2.amount) DESC,
	MAX(DATE(transactions.post_date)) DESC
SQL_BLOCK;

    $count = 0;

    /** @var \Models\Combined\BankTransactionMatchingAcconts $match_row */
    foreach ($database->objects($query) as $match_row) {
        if (!$count++) {
            echo <<<HTML_BLOCK
						<h3>Match sugestions (Text)</h3>
						<ul>
						
HTML_BLOCK;
        }
        $other_account_name = htmlentities($match_row->name);
        if ($match_row->connections > 1) {
            echo <<<HTML_BLOCK
						<li onclick="setAccount(this, '{$match_row->code}')">{$other_account_name}, {$match_row->connections} connections, amount in range {$match_row->amount_from} - {$match_row->amount_to}, dates in range {$match_row->date_from} - {$match_row->date_to}</li>

HTML_BLOCK;
        } else {
            echo <<<HTML_BLOCK
						<li onclick="setAccount(this, '{$match_row->code}')">{$other_account_name}, {$match_row->connections} connections, amount {$match_row->amount_from}, date {$match_row->date_from}</li>

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
