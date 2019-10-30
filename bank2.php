<?php

if (empty($_GET['account'])) {
    header('Location: bank.php');
    die();
}

$account_code = (int) $_GET['account'];

require_once(__DIR__ . "/token_auth.php");
require_once(__DIR__ . "/bank_funk.php");
$bi = new Bank_interface();
$accounts = $bi->accounts();

if (empty($accounts[$account_code])) {
    header('Location: bank.php');
    die();
}

ksort($accounts, SORT_STRING);
$selected_account_html = htmlentities($accounts[$account_code]);
$accounts_json = json_encode($accounts, JSON_PRETTY_PRINT + JSON_UNESCAPED_UNICODE + JSON_FORCE_OBJECT);
$rows_json = json_encode($bi->account_cache($account_code), JSON_PRETTY_PRINT + JSON_UNESCAPED_UNICODE);

echo <<<HTML_BLOCK
<html>
	<head>
		<title>Unmatched transactions of account: {$selected_account_html}</title>
		<link rel="stylesheet" href="lib/chosen/chosen.css" />
		<link rel="stylesheet" href="https://ajax.googleapis.com/ajax/libs/jqueryui/1.12.1/themes/smoothness/jquery-ui.css" />
		<style type="text/css">
			SELECT {min-width: 300px;}
			LI:nth-child(odd) FIELDSET.inc {background-color: #CCFFCC;}
			LI:nth-child(even) FIELDSET.inc {background-color: #EEFFEE;}
			LI:nth-child(odd) FIELDSET.dec {background-color: #FFCCCC;}
			LI:nth-child(even) FIELDSET.dec {background-color: #FFEEEE;}
			UL#main_list {padding: 0;}
			UL#main_list LI {list-style: none;}
			
			input.input_text {width: 900px; min-width: 150px; max-width: 100%;}
		</style>
		<script src="https://ajax.googleapis.com/ajax/libs/jquery/1.12.4/jquery.js" type="text/javascript"></script>
		<script src="https://ajax.googleapis.com/ajax/libs/jqueryui/1.12.1/jquery-ui.js" type="text/javascript"></script>
		<script src="lib/chosen/chosen.jquery.js" type="text/javascript"></script>
		<script src="lib/chosen/docsupport/prism.js" type="text/javascript" charset="utf-8"></script>
		<script type="text/javascript">
			bi = {};
			bi.selected_account =; {$account_code};
			bi.accounts =; {$accounts_json};
			bi.rows =; {$rows_json};
		</script>
		<script type="text/javascript" src="bank2.js"></script>
	</head>
	<body>
		<h1>Unmatched transactions of account: {$selected_account_html}</h1>
		<ul id="main_list">
		
		</ul>
		<script type="text/javascript">
			$( function() {
				bi.init()
		   });
		</script>
		<p><a href="?">&laquo; Account view</a></p>
	</body>
</html>
HTML_BLOCK;
