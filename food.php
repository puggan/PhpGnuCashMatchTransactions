<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/GnuCash.php';
require_once __DIR__ . '/token_auth.php';

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
		<title>Food per day</title>
		<link rel="stylesheet" href="lib/chosen/chosen.css" />
		<link rel="stylesheet" href="https://ajax.googleapis.com/ajax/libs/jqueryui/1.12.1/themes/smoothness/jquery-ui.css" />
		<style type="text/css">
			SELECT {min-width: 300px;}
			FIELDSET.inc.odd {background-color: #CCFFCC;}
			FIELDSET.inc.even {background-color: #EEFFEE;}
			FIELDSET.dec.odd {background-color: #FFCCCC;}
			FIELDSET.dec.even {background-color: #FFEEEE;}
			FIELDSET.dec {background-color: #FF0000;}
			H1 {font-size: 16pt; margin: 0;}
			H2 {font-size: 14pt; margin: 0;}
			LI {font-size: 12pt; margin: 0;}
			TABLE, TR, TH, TD {border: solid 1px rgba(0,0,0,0.2);}
			TABLE {margin-bottom: 10px;}
			CAPTION {text-align: left;}
			TD {padding-left: 10px;}
			TD:nth-child(1) {text-align: right; padding-right: 10px;}
			TBODY TR:nth-child(odd) {background: rgba(255,255,0,0.1);}
			TBODY TR:nth-child(even) {background: rgba(0,0,255,0.1);}
		</style>
	</head>
	<body>
		<h1>Food per day</h1>

HTML_BLOCK;

$query = <<<SQL_BLOCK
SELECT
	accounts.code,
	accounts.name,
	transactions.post_date,
	transactions.description,
	splits.value_num,
   splits.value_denom,
	GROUP_CONCAT(a2.code) AS code2,
	GROUP_CONCAT(a2.name) AS name2
FROM accounts
	INNER JOIN splits ON (splits.account_guid = accounts.guid)
	INNER JOIN transactions ON (transactions.guid = splits.tx_guid)
	INNER JOIN splits AS s2 ON (s2.account_guid <> splits.account_guid AND s2.tx_guid = transactions.guid)
	INNER JOIN accounts AS a2 ON (a2.guid = s2.account_guid)
WHERE accounts.code BETWEEN 4510 AND 4519
GROUP BY transactions.guid
ORDER BY transactions.post_date DESC, accounts.code, transactions.description
SQL_BLOCK;

$count = 0;
$day_count = 0;
$last_day = null;

/** @var \PhpDoc\table_db_result_row_odd_match $match_row */
foreach ($db->objects($query) as $row) {
    $row->day = substr($row->post_date, 0, 10);
    $row->value = round($row->value_num / $row->value_denom, 2);

    if ($row->day != $last_day) {
        if ($day_count) {
            echo <<<HTML_BLOCK
				</tbody>
			</table>
						
HTML_BLOCK;
        }
        $day_count = 0;
        $last_day = $row->day;
        $weekday = date("l", strtotime($row->day));
        echo <<<HTML_BLOCK
			<table>
				<caption><h2>{$last_day}, {$weekday}</h2></caption>
				<colgroup>
					<col style="width: 100px;" />
					<col style="width: 200px;" />
					<col style="width: 300px;" />
					<col style="width: 250px;" />
				</colgroup>
				<thead>
					<tr><th>Value</th><th>Account</th><th>Description</th><th>Other Account(s)</th></tr>
				</thead>
				<tbody>
						
HTML_BLOCK;
    }

    $day_count++;
    $json = json_encode($row, $json_options);
    $htmljson = htmlentities($json);
    $htmlrow = (object) array_map('htmlentities', (array) $row);
    echo <<<HTML_BLOCK
					<tr><td>{$htmlrow->value} kr</td><td>{$htmlrow->name}</td><td>{$htmlrow->description}</td><td>{$htmlrow->name2}</td></tr>

HTML_BLOCK;
}

if ($day_count) {
    echo <<<HTML_BLOCK
				</tbody>
			</table>

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
