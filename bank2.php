<?php
declare(strict_types=1);
/** @noinspection PhpUnhandledExceptionInspection */

if (empty($_GET['account'])) {
    header('Location: bank.php');
    die();
}

$accountCode = (int) $_GET['account'];

require_once __DIR__ . '/token_auth.php';
require_once __DIR__ . '/bank_funk.php';
$bankI = new Bank_interface();
$accounts = $bankI->accounts();

if (empty($accounts[$accountCode])) {
    header('Location: bank.php');
    die();
}

ksort($accounts, SORT_STRING);
$accountHtml = htmlentities($accounts[$accountCode], ENT_QUOTES);
$accountsJson = json_encode(
    $accounts,
    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT + JSON_UNESCAPED_UNICODE + JSON_FORCE_OBJECT,
    512
);
$rowsJson = json_encode(
    $bankI->accountCache($accountCode),
    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT + JSON_UNESCAPED_UNICODE,
    512
);

echo <<<HTML_BLOCK
<html>
	<head>
		<title>Unmatched transactions of account: {$accountHtml}</title>
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
			bi.selected_account = {$accountCode};
			bi.accounts = {$accountsJson};
			bi.rows = {$rowsJson};
		</script>
		<script type="text/javascript" src="bank2.js"></script>
	</head>
	<body>
		<h1>Unmatched transactions of account: {$accountHtml}</h1>
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
