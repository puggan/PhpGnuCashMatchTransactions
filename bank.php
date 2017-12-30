<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

	require_once(__DIR__ . "/auth.php");
	require_once(__DIR__ . "/gnucach.php");

	$db = Auth::new_db();

	if(empty($_GET['account']))
	{
		$query = <<<SQL_BLOCK
SELECT
	bank_transactions.account,
	accounts.name,
	MAX(IF(bank_tid IS NULL, bank_transactions.bdate, NULL)) AS edate,
	MAX(IF(bank_tid IS NULL, NULL, bank_transactions.bdate)) AS fdate,
	SUM(IF(bank_tid IS NULL, GREATEST(bank_transactions.amount, 0), NULL)) AS missingPos,
	SUM(IF(bank_tid IS NULL, LEAST(bank_transactions.amount, 0), NULL)) AS missingNeg,
	COUNT(DISTINCT IF(bank_tid IS NULL, bank_transactions.bank_t_row, NULL)) AS erows,
	COUNT(DISTINCT bank_transactions.bank_t_row) AS rows
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
					<th>Last Empty</th>
					<th>Last Connected</th>
					<th>Empty</th>
					<th>Total</th>
					<th>% Empty</th>
					<th>Income</th>
					<th>Fees</th>
				</tr>
			</thead>
			<tbody>

HTML_BLOCK;

		$odd = FALSE;
		/** @var table_db_result_account_name_rows $row */
		foreach($db->g_objects($query) as $row)
		{
			$row->prows = (int) (100 * $row->erows / $row->rows);
			$row->pos = (($row->missingPos > 0) ? number_format($row->missingPos, 2, '.', ' ') : '');
			$row->neg = (($row->missingNeg < 0) ? number_format($row->missingNeg, 2, '.', ' ') : '');
			/** @var table_db_result_account_name_rows $row_html */
			$row_html = (object) array_map('htmlentities', (array) $row);


			$row_class = ($odd = !$odd) ? 'odd' : 'even';
			echo <<<HTML_BLOCK
				<tr class="{$row_class}">
					<td><a href="bank2.php?account={$row_html->account}">{$row_html->name}</a></td>
					<td>{$row_html->edate}</td>
					<td>{$row_html->fdate}</td>
					<td>{$row_html->erows}</td>
					<td>{$row_html->rows}</td>
					<td>{$row_html->prows} %</td>
					<td>{$row_html->pos}</td>
					<td>{$row_html->neg}</td>
				</tr>

HTML_BLOCK;

			$links[$row->account] = "<a href=\"?account={$row->account}\">" . htmlentities($row->name) . "</a> ({$row->erows} / {$row->rows})";
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

	$account_ids = $db->read("SELECT code, guid FROM `accounts` WHERE LENGTH(code) = 4 ORDER BY code", "code", "guid");
	$account_names = $db->read("SELECT code, name FROM `accounts` WHERE LENGTH(code) = 4 ORDER BY code", "code", "name");
	$account_names_html = array_map('htmlentities', $account_names);
	$selected_account = (int) $_GET['account'];

	$edit_row = $_GET['row'] ?? 0;
	if($edit_row)
	{
		$new_guid = $_GET['guid'] ?? '';
		if(!$new_guid)
		{
			$date = $_POST['date'] ?? NULL;
			$amount = $_POST['amount'] ?? NULL;
			$from = $_POST['from'] ?? NULL;
			$to = $_POST['to'] ?? NULL;
			$text = $_POST['text'] ?? NULL;
			$amount = strtr($amount, array(',' => '.', ' ' => ''));

			if($date)
			{
				$date = date("Y-m-d", strtotime($date));
			}

			if($from)
			{
				$from = $account_ids[$from] ?? NULL;
			}

			if($to)
			{
				$to = $account_ids[$to] ?? NULL;
			}

			if($date AND $amount AND $from AND $to AND $text)
			{
				$GnuCash = Auth::new_gnucash();

				if($GnuCash->GUIDExists($from) AND $GnuCash->GUIDExists($to))
				{
					$error = $GnuCash->createTransaction($to, $from, $amount, $text, $date, '');

					if($GnuCash->lastTxGUID)
					{
						$tx_guid_sql = $db->quote($GnuCash->lastTxGUID);
						$account_guid_sql = $db->quote($account_ids[$selected_account]);
						$query = <<<SQL_BLOCK
SELECT guid
FROM splits
WHERE tx_guid = {$tx_guid_sql}
	AND account_guid = {$account_guid_sql}
SQL_BLOCK;
						$matching_splits = $db->read($query, NULL, 'guid');
						if(count($matching_splits))
						{
							$new_guid = $matching_splits[0];
						}

					}
else echo $error;
				}
			}
		}
		if($new_guid)
		{
			$new_guid_sql = $db->quote($new_guid);
			$query = <<<SQL_BLOCK
UPDATE bank_transactions
SET bank_tid = {$new_guid_sql}
WHERE bank_tid IS NULL
	AND bank_t_row = {$edit_row}
SQL_BLOCK;
			$db->write($query);
		}
	}

	$options = array("<option value=\"\">-- Select Account --</option>");
	foreach($account_names as $account_code => $account_name)
	{
		$account_name_html = htmlentities($account_name);
		$options[] = "<option value=\"{$account_code}\">{$account_name_html}</option>";
	}
	$options = implode(PHP_EOL, $options);

	$skip_count = 0;
	$order_by = "bdate DESC";

	if(isset($_GET['skip']))
	{
		$skip_count = (int) $_GET['skip'];
		if(empty($_GET['limit']))
		{
			$limit_count = 20;
		}
		else
		{
			$limit_count = (int) $_GET['limit'];
		}
		$limit = "{$limit_count} OFFSET {$skip_count}";
		$skip_url_html = "&amp;limit={$limit_count}&amp;skip={$skip_count}";
		$filter = '';
	}
	else if(isset($_GET['q']))
	{
		$filter = 'AND bank_transactions.vtext LIKE ' . $db->quote('%' . $_GET['q'] . '%');
		$limit = 50000;
		$skip_url_html = '&amp;q=' . htmlentities(urlencode($_GET['q']));
	}
	else if(isset($_GET['a']))
	{
		$amount = (int) $_GET['a'];
		$filter = 'AND ((bank_transactions.amount BETWEEN ' . ($amount - 5) . ' AND ' . ($amount + 5) . ') OR (bank_transactions.amount BETWEEN ' . (0 - $amount - 5) . ' AND ' . (5 - $amount) . '))';
		$limit = 50000;
		$skip_url_html = '&amp;a=' . $amount;
	}
	else
	{
		$limit = 500;
		$skip_url_html = '';
		$filter = '';
	}

	if(isset($_GET['old'])) {
		$order_by = "bdate";
		$skip_url_html .= '&amp;old';
	}

	if(!isset($limit_count))
	{
		$limit_count = $limit;
	}

	$query = <<<SQL_BLOCK
SELECT *
FROM bank_transactions
WHERE bank_tid IS NULL
	AND bdate >= '2016-09-01'
	AND account = {$selected_account}
	{$filter}
ORDER BY {$order_by}
LIMIT {$limit}
SQL_BLOCK;

	echo <<<HTML_BLOCK
<html>
	<head>
		<title>Unmatched transactions of account: {$selected_account}</title>
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
		<h1>Unmatched transactions of account: {$account_names_html[$selected_account]}</h1>

HTML_BLOCK;

	$current_account_option = "<option value=\"{$selected_account}\">{$account_names_html[$selected_account]}</option>";

	$odd = FALSE;
	$row_count = 0;

	/** @var table_bank_transactions $bt_row */
	foreach($db->objects($query) as $bt_row)
	{
		$base_url = "?account={$selected_account}{$skip_url_html}&amp;row={$bt_row->bank_t_row}";
		$row_count++;

		if($bt_row->amount > 0)
		{
			$from_option = $options;
			$to_option = $current_account_option;
			$amount = $bt_row->amount;
		}
		else
		{
			$from_option = $current_account_option;
			$to_option = $options;
			$amount = -$bt_row->amount;
		}

		$text = htmlentities($bt_row->vtext);
		$class = (($odd = !$odd) ? 'odd' : 'even') . ' ' . ($bt_row->amount > 0 ? 'inc' : 'dec');

		if(preg_match("#/([0-9][0-9]-[0-9][0-9]-[0-9][0-9])$#", $text, $m))
		{
			$text = trim(substr($text, 0, -9));
			$date = 20 . $m[1];
		}
		else
		{
			$date = $bt_row->bdate;
		}

		echo <<<HTML_BLOCK
		<form method="post" action="{$base_url}">
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
	COALESCE(account2.name, '') AS other_account
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
GROUP BY splits.guid
ORDER BY ABS(splits.value_num - bank_transactions.amount * splits.value_denom),
	ABS(UNIX_TIMESTAMP(transactions.post_date) - UNIX_TIMESTAMP(bank_transactions.bdate))
SQL_BLOCK;

	$count = 0;
	/** @var table_db_result_row_value_date_description_guid $match_row */
	foreach($db->objects($query) as $match_row)
	{
		if(!$count++)
		{
			echo <<<HTML_BLOCK
						<h3>Match sugestions (TR)</h3>
						<ul>
						
HTML_BLOCK;
		}
		$description = htmlentities($match_row->description);
		$url = "{$base_url}&amp;guid={$match_row->guid}";
		$date = substr($match_row->date, 0, 10);
		$other_account = $match_row->other_account ? ' (' . htmlentities($match_row->other_account) . ')' : '';
		echo <<<HTML_BLOCK
						<li><a href="{$url}">{$match_row->value} @ {$date}: {$description}</a>{$other_account}</li>

HTML_BLOCK;
	}

	if($count)
	{
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

	/** @var table_db_result_row_text_match $match_row */
	foreach($db->objects($query) as $match_row)
	{
		if(!$count++)
		{
			echo <<<HTML_BLOCK
						<h3>Match sugestions (Text)</h3>
						<ul>
						
HTML_BLOCK;
		}
		$other_account_name =  htmlentities($match_row->name);
		if($match_row->connections > 1)
		{
			echo <<<HTML_BLOCK
						<li onclick="setAccount(this, '{$match_row->code}')">{$other_account_name}, {$match_row->connections} connections, amount in range {$match_row->amount_from} - {$match_row->amount_to}, dates in range {$match_row->date_from} - {$match_row->date_to}</li>

HTML_BLOCK;
		}
		else
		{
			echo <<<HTML_BLOCK
						<li onclick="setAccount(this, '{$match_row->code}')">{$other_account_name}, {$match_row->connections} connections, amount {$match_row->amount_from}, date {$match_row->date_from}</li>

HTML_BLOCK;
		}
	}

	if($count)
	{
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

	if($limit_count) {
		$relative_url = "?account={$selected_account}&amp;limit={$limit_count}";
		if(isset($_GET['old'])) {
			$order_by = "bdate";
			$relative_url .= '&amp;old';
		}

		$links = [];
		if($skip_count) {
			$links[] = "<a href=\"{$relative_url}&amp;skip=0\">&lt;&lt;-</a>";

			if($skip_count > $limit_count)
			{
				$skip_back = $skip_count - $limit_count;
				$links[] = "<a href=\"{$relative_url}&amp;skip={$skip_back}\">&lt;-</a>";
			}
		}
		$page = 1 + floor($skip_count / $limit_count);
		$first = $skip_count + 1;
		$last = $skip_count + $row_count;
		$links[] = "<strong>{$page}</strong>";
		$links[] = "<span>({$first} - {$last})</span>";
		if($row_count > 0 AND $row_count == $limit_count) {
			$skip_next = $skip_count + $limit_count;
			$links[] = "<a href=\"{$relative_url}&amp;skip={$skip_next}\">-&gt;</a>";
		}
		echo "<p>" . implode("<span> &nbsp; &nbsp; </span>", $links) . "</p>";
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

/*
CREATE TABLE `bank_transactions` (
 `bdate` date NOT NULL,
 `vdate` date NOT NULL,
 `vnr` int(11) NOT NULL,
 `vtext` varchar(255) NOT NULL,
 `amount` decimal(10,0) NOT NULL,
 `saldo` decimal(10,0) NOT NULL,
 `account` char(4) DEFAULT NULL,
 `bank_tid` varchar(255) DEFAULT NULL,
 `bank_t_row` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
 PRIMARY KEY (`bank_t_row`),
 UNIQUE KEY `bank_row` (`account`,`bdate`,`vdate`,`vnr`,`vtext`,`amount`,`saldo`) USING BTREE,
 KEY `bank_data` (`bdate`,`vdate`,`vnr`,`vtext`,`amount`,`saldo`) USING BTREE,
 KEY `bank_tid` (`bank_tid`)
) ENGINE=InnoDB AUTO_INCREMENT=2179 DEFAULT CHARSET=utf8

-- File: /tmp/1221_20170218.csv
-- Import columns: bdate,vdate,vnr,vtext,amount,saldo
UPDATE IGNORE bank_transactions SET account = 1221 WHERE account IS NULL;
DELETE FROM bank_transactions WHERE account IS NULL;

-- Broken
SELECT bank_transactions.* FROM bank_transactions LEFT JOIN splits ON (splits.guid = bank_transactions.bank_tid) WHERE splits.guid IS NULL AND  bank_transactions.bank_tid IS NOT NULL;
UPDATE bank_transactions LEFT JOIN splits ON (splits.guid = bank_transactions.bank_tid) SET bank_transactions.bank_tid = NULL WHERE splits.guid IS NULL AND bank_transactions.bank_tid IS NOT NULL;
*/

class table_bank_transactions
{
	public $bdate;
	public $vdate;
	public $vnr;
	public $vtext;
	public $amount;
	public $saldo;
	public $account;
	public $bank_tid;
	public $bank_t_row;
}

class table_db_result_account_name_rows
{
	public $account;
	public $name;
	public $rows;
	public $erows;
	public $prows;
	public $edate;
	public $fdate;
}

class table_db_result_row_value_date_description_guid
{
	public $row;
	public $value;
	public $date;
	public $description;
	public $guid;
	public $other_account;
}

class table_db_result_row_text_match
{
	public $code;
	public $name;
	public $connections;
	public $amount_from;
	public $amount_to;
	public $date_from;
	public $date_to;
}
