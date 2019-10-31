<?php
declare(strict_types=1);

use Puggan\GnuCashMatcher\Auth;
use Puggan\GnuCashMatcher\Models\BankTransaction;

require_once __DIR__ . '/token_auth.php';

define('REGEXP_INT', '#^(0|-?[1-9]\d*)$#');
define('REGEXP_DATE', '#^(20\d\d)-([0-1]\d)-([0-3]\d)$#');
define('REGEXP_MONEY', "#^(-)?(0|[1-9]\d*)([ ,\xc2\xa0]\d\d\d)*[,\\.](\d\d)$#u");
define('REGEXP_MONEY_SEK', "#^(-)?(0|[1-9]\d*)([ \xc2\xa0]\d\d\d)*([,\\.](\d\d?))?$#u");

$database = Auth::newDatabase();

if (!empty($_POST['data']) && !empty($_POST['account'])) {
    $okCount = 0;
    $minDate = $_POST['min_date'] ?? '2016-09-01';

    if ($_POST['import_type'] === 'seb') {
        foreach (explode("\n", $_POST['data']) as $rowNr => $dbRow) {
            if (!($dbRow = trim($dbRow))) {
                continue;
            }
            $cells = explode("\t", $dbRow);
            switch (count($cells)) {
                case 6:
                    if (trim($cells[0]) < $minDate) {
                        continue 2;
                    }
                    if (!preg_match(REGEXP_DATE, $cells[0])) {
                        echo $rowNr . ": Bad date in 1st column '{$cells[0]}' @ '{$dbRow}' <br />\n";
                        continue 2;
                    }
                    if (!preg_match(REGEXP_DATE, $cells[1])) {
                        echo $rowNr . ": Bad date in 2th column '{$cells[1]}' @ '{$dbRow}' <br />\n";
                        continue 2;
                    }
                    if (!preg_match(REGEXP_INT, $cells[2])) {
                        echo $rowNr . ": Bad int in 3th column '{$cells[2]}' @ '{$dbRow}'"; // no line break
                        $cells[2] = preg_replace('#\D+#', '', $cells[2]);
                        /** @noinspection NotOptimalIfConditionsInspection $cells[2] is overwritten since last if */
                        if (!preg_match(REGEXP_INT, $cells[2])) {
                            echo "<br />\n";
                            continue 2;
                        }
                        echo " -> Fixed, replaced with '{$cells[2]}' <br />\n";
                    }
                    if (preg_match(REGEXP_MONEY, $cells[4])) {
                        $cells[4] = str_replace(['.', ',', ' ', "\xc2\xa0"], '', $cells[4]);
                    } elseif (preg_match(REGEXP_MONEY_SEK, $cells[4])) {
                        $cells[4] = strtr($cells[4], [',' => '.', ' ' => '', "\xc2\xa0" => '']) * 100;
                    } else {
                        echo $rowNr . ": Bad amount in 2th last column '{$cells[4]}' @ '{$dbRow}' <br />\n";
                        continue 2;
                    }
                    if (preg_match(REGEXP_MONEY, $cells[5])) {
                        $cells[5] = str_replace(['.', ',', ' ', "\xc2\xa0"], '', $cells[5]);
                    } elseif (preg_match(REGEXP_MONEY_SEK, $cells[5])) {
                        $cells[5] = strtr($cells[5], [',' => '.', ' ' => '', "\xc2\xa0" => '']) * 100;
                    } else {
                        echo $rowNr . ": Bad amount in last column '{$cells[5]}' @ '{$dbRow}' <br />\n";
                        continue 2;
                    }
                    $banktransaction = new BankTransaction();
                    $banktransaction->bdate = $cells[0];
                    $banktransaction->vdate = $cells[1];
                    $banktransaction->vnr = $cells[2];
                    $banktransaction->vtext = $cells[3];
                    $banktransaction->amount = $cells[4] / 100;
                    $banktransaction->saldo = $cells[5] / 100;
                    $banktransaction->account = $_POST['account'];

                    if($banktransaction->add($database)) {
                        $okCount++;
                    }
                    break;

                default:
                    echo $rowNr . ': Bad number of columns, found ' . count(
                            $cells
                        ) . ", expected 6 @ '{$dbRow}' <br />\n";
                    break;
            }
        }
    } elseif ($_POST['import_type'] === 'swedbank') {
        foreach (explode("\n", $_POST['data']) as $rowNr => $dbRow) {
            if (!($dbRow = trim($dbRow))) {
                continue;
            }
            $cells = explode("\t", preg_replace('#  +#', "\t", $dbRow));
            if (count($cells) === 1) {
                $cells = explode(';', $dbRow);
            }
            switch (count($cells)) {
                case 9:
                    $bdate = '20' . $cells[4];
                    if (trim($bdate) < $minDate) {
                        continue 2;
                    }
                    if (!preg_match(REGEXP_DATE, $bdate)) {
                        echo $rowNr . ": Bad date in 4st column @ '{$dbRow}' <br />\n";
                        continue 2;
                    }

                    $vdate = '20' . $cells[5];
                    if (!preg_match(REGEXP_DATE, $vdate)) {
                        echo $rowNr . ": Bad date in 5th column @ '{$dbRow}' <br />\n";
                        continue 2;
                    }
                    if (!preg_match(REGEXP_MONEY, $cells[8])) {
                        echo $rowNr . ": Bad amount in 8th last column @ '{$dbRow}' <br />\n";
                        continue 2;
                    }

                    $banktransaction = new BankTransaction();
                    $banktransaction->bdate = $bdate;
                    $banktransaction->vdate = $vdate;
                    $banktransaction->vnr = $cells[0] . $cells[1];
                    $banktransaction->vtext = $cells[6];
                    $banktransaction->amount = str_replace(['.', ',', ' ', "\xc2\xa0"], '', $cells[8]) / 100;
                    $banktransaction->saldo = 0;
                    $banktransaction->account = $_POST['account'];

                    if($banktransaction->add($database)) {
                        $okCount++;
                    }
                    break;

                case 8:
                    if (trim($cells[1]) < $minDate) {
                        continue 2;
                    }
                    if (!preg_match(REGEXP_DATE, $cells[1])) {
                        echo $rowNr . ": Bad date in 2st column @ '{$dbRow}' <br />\n";
                        continue 2;
                    }

                    if (!preg_match(REGEXP_DATE, $cells[2])) {
                        echo $rowNr . ": Bad date in 5th column @ '{$dbRow}' <br />\n";
                        continue 2;
                    }
                    if (!preg_match(REGEXP_MONEY, $cells[6])) {
                        echo $rowNr . ": Bad amount in column 7 @ '{$dbRow}' <br />\n";
                        continue 2;
                    }
                    if (!preg_match(REGEXP_MONEY, $cells[7])) {
                        echo $rowNr . ": Bad saldo in column 8 @ '{$dbRow}' <br />\n";
                        continue 2;
                    }

                    $banktransaction = new BankTransaction();
                    $banktransaction->bdate = $cells[1];
                    $banktransaction->vdate = $cells[2];
                    $banktransaction->vnr = 0;
                    $banktransaction->vtext = $cells[4];
                    $banktransaction->amount = str_replace(['.', ',', ' ', "\xc2\xa0"], '', $cells[6]) / 100;
                    $banktransaction->saldo = str_replace(['.', ',', ' ', "\xc2\xa0"], '', $cells[7]) / 100;
                    $banktransaction->account = $_POST['account'];

                    if($banktransaction->add($database)) {
                        $okCount++;
                    }
                    break;

                case 5:
                    // 0: vtext, 1: bdate, 2: vdate, 3: amount, 4: saldo.
                    $bdate = $cells[1];
                    if (trim($bdate) < $minDate) {
                        continue 2;
                    }
                    if (!preg_match(REGEXP_DATE, $bdate)) {
                        echo $rowNr . ": Bad b-date in column 1 @ '{$dbRow}' <br />\n";
                        continue 2;
                    }

                    $vdate = $cells[2];
                    if (!preg_match(REGEXP_DATE, $vdate)) {
                        echo $rowNr . ": Bad v-date in column 2 @ '{$dbRow}' <br />\n";
                        continue 2;
                    }
                    if (!preg_match(REGEXP_MONEY, $cells[3])) {
                        echo $rowNr . ": Bad amount in column 3 @ '{$dbRow}' <br />\n";
                        continue 2;
                    }
                    if (!preg_match(REGEXP_MONEY, $cells[4])) {
                        echo $rowNr . ": Bad saldo in column 4 @ '{$dbRow}' <br />\n";
                        continue 2;
                    }

                    $banktransaction = new BankTransaction();
                    $banktransaction->bdate = $bdate;
                    $banktransaction->vdate = $vdate;
                    $banktransaction->vnr = 0;
                    $banktransaction->vtext = $cells[0];
                    $banktransaction->amount = str_replace(['.', ',', ' ', "\xc2\xa0"], '', $cells[3]) / 100;
                    $banktransaction->saldo = str_replace(['.', ',', ' ', "\xc2\xa0"], '', $cells[4]) / 100;
                    $banktransaction->account = $_POST['account'];

                    if($banktransaction->add($database)) {
                        $okCount++;
                    }
                    break;

                default:
                    echo $rowNr . ': Bad number of columns, found ' . count(
                            $cells
                        ) . ", expected 9 @ '{$dbRow}' <br />\n";
                    break;
            }
        }
    }

    if ($okCount) {
        echo "{$okCount} rows parsed ok <br />\n";
    }
    echo '<p><a href="./bank.php">&laquo; Account view</a></p>';
} else {
    $accountNames = $database->read(
        "SELECT code, accounts.name FROM accounts WHERE LENGTH(code) = 4 AND code LIKE '12%' ORDER BY code",
        'code',
        'name'
    );

    $accountOptions = [];
    foreach ($accountNames as $accountCode => $accountName) {
        $accountOptions[$accountCode] = "<option value=\"{$accountCode}\">" .
            htmlentities($accountName, ENT_QUOTES) .
            '</option>';
    }
    $accountOptions = implode(PHP_EOL, $accountOptions);

    $minDate = $_POST['min_date'] ?? date('Y-m-d', strtotime('-6 months'));

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
					<select name="account"><option value="">-- Select account --</option>{$accountOptions}</select>
				</label><br />

				<label>
					<span>Date Filter (Only included rows newer then)</span><br />
					<input type="date" name="min_date" value="{$minDate}"/>
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
