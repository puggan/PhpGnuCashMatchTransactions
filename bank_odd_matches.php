<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once(__DIR__ . "/auth.php");
require_once(__DIR__ . "/gnucach.php");
require_once(__DIR__ . "/token_auth.php");

$db = Auth::new_db();

$json_options = 0
    // + JSON_HEX_QUOT
    // + JSON_HEX_TAG
    // + JSON_HEX_AMP
    // + JSON_HEX_APOS
    + JSON_NUMERIC_CHECK
    + JSON_PRETTY_PRINT
    + JSON_UNESCAPED_SLASHES
    // + JSON_FORCE_OBJECT
    + JSON_UNESCAPED_UNICODE;

echo <<<HTML_BLOCK
<html>
	<head>
		<title>Odd matches</title>
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
	</head>
	<body>
		<h1>Odd matches</h1>

HTML_BLOCK;

$query = <<<SQL_BLOCK
SELECT
	IF(vtext RLIKE '/[0-9][0-9]-[0-9][0-9]-[0-9][0-9]$', SUBSTRING(vtext, 1, LENGTH(vtext) - 9), vtext) AS matchtext
FROM bank_transactions
	INNER JOIN splits ON (splits.guid = bank_transactions.bank_tid)
	INNER JOIN transactions ON (transactions.guid = splits.tx_guid)
	INNER JOIN splits AS s2 ON (s2.tx_guid = transactions.guid AND s2.guid <> splits.guid)
	INNER JOIN accounts ON (accounts.guid = s2.account_guid)
WHERE bank_transactions.bank_t_row > ''
GROUP BY matchtext
HAVING
	COUNT(DISTINCT accounts.code) > 1
	AND COUNT(*) / COUNT(DISTINCT transactions.guid) < COUNT(DISTINCT accounts.code)
ORDER BY COUNT(DISTINCT accounts.code), COUNT(*) DESC, MAX(transactions.post_date)
SQL_BLOCK;

$count = 0;

/** @var table_db_result_row_odd_match $match_row */
foreach ($db->objects($query) as $match_row) {
    if (!$count++) {
        echo <<<HTML_BLOCK
						<ul>
						
HTML_BLOCK;
    }

    $text = htmlentities($match_row->matchtext);
    echo <<<HTML_BLOCK
						<li>
							<span>{$text}</span>
							<ul>

HTML_BLOCK;

    $safe_text = $db->quote($match_row->matchtext);
    $query = <<<SQL_BLOCK
SELECT
	accounts.code,
	accounts.name,
	COUNT(bank_transactions.amount) AS 'connections',
	MIN(ABS(bank_transactions.amount)) AS 'amount_from',
	MAX(ABS(bank_transactions.amount)) AS 'amount_to',
	MIN(DATE(transactions.post_date)) AS 'date_from',
	MAX(DATE(transactions.post_date)) AS 'date_to'
FROM bank_transactions
	INNER JOIN splits ON (splits.guid = bank_transactions.bank_tid)
	INNER JOIN transactions ON (transactions.guid = splits.tx_guid)
	INNER JOIN splits AS s2 ON (s2.tx_guid = transactions.guid AND s2.guid <> splits.guid)
	INNER JOIN accounts ON (accounts.guid = s2.account_guid)
WHERE IF(bank_transactions.vtext RLIKE '/[0-9][0-9]-[0-9][0-9]-[0-9][0-9]$', SUBSTRING(bank_transactions.vtext, 1, LENGTH(bank_transactions.vtext) - 9), bank_transactions.vtext) = {$safe_text}
GROUP BY accounts.guid
ORDER BY
	COUNT(bank_transactions.amount) DESC,
	MAX(DATE(transactions.post_date)) DESC
SQL_BLOCK;
    foreach ($db->objects($query) as $account_row) {
        $j = '<pre>' . htmlentities(json_encode($account_row, $json_options)) . '</pre>';
        echo <<<HTML_BLOCK
								<li>{$j}</li>
HTML_BLOCK;
    }
    echo <<<HTML_BLOCK
							</ul>
						</li>

HTML_BLOCK;
}

if ($count) {
    echo <<<HTML_BLOCK
					</ul>

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

class table_db_result_row_odd_match
{
    public $row_count;
    public $account_count;
    public $accounts;
    public $matchtext;
}
