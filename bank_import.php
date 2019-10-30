<?php

require_once(__DIR__ . "/token_auth.php");
require_once(__DIR__ . "/auth.php");

define("REGEXP_INT", "#^(0|-?[1-9][0-9]*)$#");
define("REGEXP_DATE", "#^(20[0-9][0-9])-([0-1][0-9])-([0-3][0-9])$#");
define("REGEXP_MONEY", "#^(-)?(0|[1-9][0-9]*)([ ,][0-9][0-9][0-9])*[,\\.]([0-9][0-9])$#");
define("REGEXP_MONEY_SEK", "#^(-)?(0|[1-9][0-9]*)([ ][0-9][0-9][0-9])*([,\\.]([0-9][0-9]?))?$#");

$db = Auth::new_db();

if (!empty($_POST['data']) and !empty($_POST['account'])) {
    $ok = 0;
    $min_date = $_POST['min_date'] ?? '2016-09-01';

    if ($_POST['import_type'] == 'seb') {
        foreach (explode("\n", $_POST['data']) as $row_nr => $row) {
            if (!($row = trim($row))) {
                continue;
            }
            $cells = explode("\t", $row);
            switch (count($cells)) {
                case 6:
                {
                    if (trim($cells[0]) < $min_date) {
                        continue 2;
                    }
                    if (!preg_match(REGEXP_DATE, $cells[0])) {
                        echo $row_nr . ": Bad date in 1st column '{$cells[0]}' @ '{$row}' <br />\n";
                        continue 2;
                    }
                    if (!preg_match(REGEXP_DATE, $cells[1])) {
                        echo $row_nr . ": Bad date in 2th column '{$cells[1]}' @ '{$row}' <br />\n";
                        continue 2;
                    }
                    if (!preg_match(REGEXP_INT, $cells[2])) {
                        echo $row_nr . ": Bad int in 3th column '{$cells[2]}' @ '{$row}'"; // no line break
                        $cells[2] = preg_replace('#\D+#', '', $cells[2]);
                        if (!preg_match(REGEXP_INT, $cells[2])) {
                            echo "<br />\n";
                            continue 2;
                        }
                        echo " -> Fixed, replaced with '{$cells[2]}' <br />\n";
                    }
                    if (preg_match(REGEXP_MONEY, $cells[4])) {
                        $cells[4] = str_replace(['.', ',', ' '], '', $cells[4]);
                    } else {
                        if (preg_match(REGEXP_MONEY_SEK, $cells[4])) {
                            $cells[4] = strtr($cells[4], [',' => '.', ' ' => '']) * 100;
                        } else {
                            echo $row_nr . ": Bad amount in 2th last column '{$cells[4]}' @ '{$row}' <br />\n";
                            continue 2;
                        }
                    }
                    if (preg_match(REGEXP_MONEY, $cells[5])) {
                        $cells[5] = str_replace(['.', ',', ' '], '', $cells[5]);
                    } else {
                        if (preg_match(REGEXP_MONEY_SEK, $cells[5])) {
                            $cells[5] = strtr($cells[5], [',' => '.', ' ' => '']) * 100;
                        } else {
                            echo $row_nr . ": Bad amount in last column '{$cells[5]}' @ '{$row}' <br />\n";
                            continue 2;
                        }
                    }

                    $fields = [];
                    // bdate
                    $fields[] = $db->quote($cells[0]);
                    // vdate
                    $fields[] = $db->quote($cells[1]);
                    // vnr
                    $fields[] = $db->quote($cells[2]);
                    // vtext
                    $fields[] = $db->quote($cells[3]);
                    // amount
                    $fields[] = $db->quote($cells[4]) . ' / 100';
                    // saldo
                    $fields[] = $db->quote($cells[5]) . ' / 100';
                    // account
                    $fields[] = $db->quote($_POST['account']);

                    $query = "INSERT INTO bank_transactions(bdate, vdate, vnr, vtext, amount, saldo, account) SELECT " . implode(
                            ', ',
                            $fields
                        );
                    // echo $query, '; ', PHP_EOL;
                    $db->write($query);
                    $ok++;
                    break;
                }

                default:
                {
                    echo $row_nr . ": Bad number of columns, found " . count(
                            $cells
                        ) . ", expected 6 @ '{$row}' <br />\n";
                }
            }
        }
    } else {
        if ($_POST['import_type'] == 'swedbank') {
            foreach (explode("\n", $_POST['data']) as $row_nr => $row) {
                if (!($row = trim($row))) {
                    continue;
                }
                $cells = explode("\t", preg_replace("#  +#", "\t", $row));
                if (count($cells) === 1) {
                    $cells = explode(";", $row);
                }
                switch (count($cells)) {
                    case 9:
                    {
                        $bdate = "20" . $cells[4];
                        if (trim($bdate) < $min_date) {
                            continue 2;
                        }
                        if (!preg_match(REGEXP_DATE, $bdate)) {
                            echo $row_nr . ": Bad date in 4st column @ '{$row}' <br />\n";
                            continue 2;
                        }

                        $vdate = "20" . $cells[5];
                        if (!preg_match(REGEXP_DATE, $vdate)) {
                            echo $row_nr . ": Bad date in 5th column @ '{$row}' <br />\n";
                            continue 2;
                        }
                        if (!preg_match(REGEXP_MONEY, $cells[8])) {
                            echo $row_nr . ": Bad amount in 8th last column @ '{$row}' <br />\n";
                            continue 2;
                        }

                        $fields = [];
                        // bdate
                        $fields[] = $db->quote($bdate);
                        // vdate
                        $fields[] = $db->quote($vdate);
                        // vnr
                        $fields[] = $db->quote($cells[0] . $cells[1]);
                        // vtext
                        $fields[] = $db->quote($cells[6]);
                        // amount
                        $fields[] = $db->quote(str_replace(['.', ',', ' '], '', $cells[8])) . ' / 100';
                        // saldo
                        $fields[] = '0';
                        // account
                        $fields[] = $db->quote($_POST['account']);

                        $query = "INSERT INTO bank_transactions(bdate, vdate, vnr, vtext, amount, saldo, account) SELECT " . implode(
                                ', ',
                                $fields
                            );
                        $db->write($query);
                        $ok++;
                        break;
                    }
                    case 8:
                    {
                        $bdate = "20" . $cells[4];
                        if (trim($bdate) < $min_date) {
                            continue 2;
                        }
                        if (!preg_match(REGEXP_DATE, $bdate)) {
                            echo $row_nr . ": Bad date in 4st column @ '{$row}' <br />\n";
                            continue 2;
                        }

                        $vdate = "20" . $cells[5];
                        if (!preg_match(REGEXP_DATE, $vdate)) {
                            echo $row_nr . ": Bad date in 5th column @ '{$row}' <br />\n";
                            continue 2;
                        }
                        if (!preg_match(REGEXP_MONEY, $cells[7])) {
                            echo $row_nr . ": Bad amount in 8th last column @ '{$row}' <br />\n";
                            continue 2;
                        }

                        $fields = [];
                        // bdate
                        $fields[] = $db->quote($bdate);
                        // vdate
                        $fields[] = $db->quote($vdate);
                        // vnr
                        $fields[] = $db->quote($cells[0] . $cells[1]);
                        // vtext
                        $fields[] = $db->quote($cells[6]);
                        // amount
                        $fields[] = $db->quote(str_replace(['.', ',', ' '], '', $cells[7])) . ' / 100';
                        // saldo
                        $fields[] = '0';
                        // account
                        $fields[] = $db->quote($_POST['account']);

                        $query = "INSERT INTO bank_transactions(bdate, vdate, vnr, vtext, amount, saldo, account) SELECT " . implode(
                                ', ',
                                $fields
                            );
                        $db->write($query);
                        $ok++;
                        break;
                    }
                    case 5:
                    {
                        // 0: vtext, 1: bdate, 2: vdate, 3: amount, 4: saldo.
                        $bdate = $cells[1];
                        if (trim($bdate) < $min_date) {
                            continue 2;
                        }
                        if (!preg_match(REGEXP_DATE, $bdate)) {
                            echo $row_nr . ": Bad b-date in column 1 @ '{$row}' <br />\n";
                            continue 2;
                        }

                        $vdate = $cells[2];
                        if (!preg_match(REGEXP_DATE, $vdate)) {
                            echo $row_nr . ": Bad v-date in column 2 @ '{$row}' <br />\n";
                            continue 2;
                        }
                        if (!preg_match(REGEXP_MONEY, $cells[3])) {
                            echo $row_nr . ": Bad amount in column 3 @ '{$row}' <br />\n";
                            continue 2;
                        }
                        if (!preg_match(REGEXP_MONEY, $cells[4])) {
                            echo $row_nr . ": Bad saldo in column 4 @ '{$row}' <br />\n";
                            continue 2;
                        }

                        $fields = [];
                        // bdate
                        $fields[] = $db->quote($bdate);
                        // vdate
                        $fields[] = $db->quote($vdate);
                        // vnr
                        $fields[] = 0;
                        // vtext
                        $fields[] = $db->quote($cells[0]);
                        // amount
                        $fields[] = $db->quote(str_replace(['.', ',', ' '], '', $cells[3])) . ' / 100';
                        // saldo
                        $fields[] = $db->quote(str_replace(['.', ',', ' '], '', $cells[4])) . ' / 100';
                        // account
                        $fields[] = $db->quote($_POST['account']);

                        $query = "INSERT INTO bank_transactions(bdate, vdate, vnr, vtext, amount, saldo, account) SELECT " . implode(
                                ', ',
                                $fields
                            );
                        $db->write($query);
                        $ok++;
                        break;
                    }

                    default:
                    {
                        echo $row_nr . ": Bad number of columns, found " . count(
                                $cells
                            ) . ", expected 9 @ '{$row}' <br />\n";
                    }
                }
            }
        }
    }

    if ($ok) {
        echo "{$ok} rows parsed ok <br />\n";
    }
    echo "<p><a href=\"./bank.php\">&laquo; Account view</a></p>";
} else {
    $account_names = $db->read(
        "SELECT code, accounts.name FROM accounts WHERE LENGTH(code) = 4 AND code LIKE '12%' ORDER BY code",
        "code",
        "name"
    );

    $account_options = [];
    foreach ($account_names as $account_code => $account_name) {
        $account_options[$account_code] = "<option value=\"{$account_code}\">" . htmlentities(
                $account_name
            ) . "</option>";
    }
    $account_options = implode(PHP_EOL, $account_options);

    $min_date = $_POST['min_date'] ?? date("Y-m-d", strtotime("-6 months"));

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
					<select name="import_type">
						<option value="seb">SEB</option>
						<option value="swedbank">Swedbank</option>
					</select>
				</label><br />

				<label>
					<span>Account</span><br />
					<select name="account"><option value="">-- Select account --</option>{$account_options}</select>
				</label><br />

				<label>
					<span>Date Filter (Only included rows newer then)</span><br />
					<input type="date" name="min_date" value="{$min_date}"/>
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

/*
	$query = <<<SQL_BLOCK
SELECT *
FROM bank_transactions
WHERE bank_tid IS NULL
	AND bdate >= '2016-09-01'
	AND account = {$selected_account}
ORDER BY bdate DESC
LIMIT 500
SQL_BLOCK;
*/
