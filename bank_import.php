<?php

	require_once(__DIR__ . "/auth.php");

	define("REGEXP_DATE", "#^(20[0-9][0-9])-([0-1][0-9])-([0-3][0-9])$#");
	define("REGEXP_MONEY", "#^(-)?(0|[1-9][0-9]*)([ ,][0-9][0-9][0-9])*[,\\.]([0-9][0-9])$#");

	$db = Auth::new_db();

	if(!empty($_POST['data']) AND !empty($_POST['account']))
	{
		$ok = 0;

		if($_POST['import_type'] == 'seb')
		{
			foreach(explode("\n", $_POST['data']) as $row_nr => $row)
			{
				$cells = explode("\t", trim($row));
				switch(count($cells))
				{
					case 6:
					{
						if(!preg_match(REGEXP_DATE, $cells[0]))
						{
							echo $row_nr . ": Bad date in 1st column @ '{$row}' <br />\n";
							continue;
						}
						if(!preg_match(REGEXP_DATE, $cells[1]))
						{
							echo $row_nr . ": Bad date in 2th column @ '{$row}' <br />\n";
							continue;
						}
						if(!preg_match(REGEXP_MONEY, $cells[4]))
						{
							echo $row_nr . ": Bad amount in 2th last column @ '{$row}' <br />\n";
							continue;
						}
						if(!preg_match(REGEXP_MONEY, $cells[5]))
						{
							echo $row_nr . ": Bad amount in last column @ '{$row}' <br />\n";
							continue;
						}

						$fields = array();
						$fields[] = $db->quote($cells[0]);
						$fields[] = $db->quote($cells[1]);
						$fields[] = $db->quote($cells[2]);
						$fields[] = $db->quote($cells[3]);
						$fields[] = $db->quote(str_replace(['.', ',', ' '], '', $cells[4])) . ' / 100';
						$fields[] = $db->quote(str_replace(['.', ',', ' '], '', $cells[5])) . ' / 100';
						$fields[] = $db->quote($_POST['account']);

						$query = "INSERT INTO bank_transactions(bdate, vdate, vnr, vtext, amount, saldo, account) SELECT " . implode(', ', $fields);
						$db->write($query);
						$ok++;
						break;
					}

					default:
					{
						echo $row_nr . ": Bad number of columns, found " . count($cells) . ", expected 6 @ '{$row}' <br />\n";
					}
				}
			}
		}

		if($ok)
		{
			echo "{$ok} rows parsed ok <br />\n";
		}
		echo "<p><a href=\"./bank.php\">&laquo; Account view</a></p>";
	}
	else
	{
		$account_names = $db->read("SELECT code, accounts.name FROM accounts WHERE LENGTH(code) = 4 AND code LIKE '12%' ORDER BY code", "code", "name");

		$account_options = array();
		foreach($account_names as $account_code => $account_name)
		{
			$account_options[$account_code] = "<option value=\"{$account_code}\">" . htmlentities($account_name) . "</option>";
		}
		$account_options = implode(PHP_EOL, $account_options);

		echo <<<HTML_BLOCK
<html>
	<head>
		<title>Bank Import</title>
	</head>
	<body>
		<h1>Bank Import</h1>
		<form method="post" action="?">
			<fieldset>
				<label>
					<span>Import type</span><br />
					<select name="import_type"><option value="seb">SEB</option></select>
				</label><br />

				<label>
					<span>Account</span><br />
					<select name="account"><option value="">-- Select account --</option>{$account_options}</select>
				</label><br />

				<label>
					<span>Transaction Data</span><br />
					<textarea name="data" style="min-width: 100px; width: 50%; min-height: 30px; height: 400px;"></textarea>
				</label><br />

				<label>
					<input name="save" value="Save" type="submit" />
				</label><br />
			</fieldset>
		</form>
		<p><a href="./bank.php">&laquo; Account view</a></p>
	</body>
</html>
HTML_BLOCK;

    }

	$query = <<<SQL_BLOCK
SELECT *
FROM bank_transactions
WHERE bank_tid IS NULL
	AND bdate >= '2016-09-01'
	AND account = {$selected_account}
ORDER BY bdate DESC
LIMIT 500
SQL_BLOCK;

