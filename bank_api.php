<?php
declare(strict_types=1);
/**
 * Created by PhpStorm.
 * User: puggan
 * Date: 2017-12-30
 * Time: 18:54
 */

require_once(__DIR__ . '/bank_funk.php');
$bankI = new Bank_interface();

// TODO replace all $bi->db() with function calls

$data = (object) $_POST;

/**
 * @param string $txt
 */
function error($txt)
{
    header('HTTP/1.0 400 Fail');
    echo json_encode(['ok' => false, 'error' => $txt, 'debug' => ['data' => $_POST]], JSON_THROW_ON_ERROR, 512);
    die();
}

if (empty($data->action)) {
    error('no action');
}

api($data->action, $data);

echo json_encode(['ok' => true, 'debug' => ['data' => $_POST]], JSON_THROW_ON_ERROR, 512);
die();

/**
 * @param string $action
 * @param mixed $data
 * @return bool
 */
function api($action, $data)
{
    global $bankI;

    /** @var string[] $accountIds */
    $accountIds = $bankI->db()->read(
        'SELECT code, guid FROM `accounts` WHERE LENGTH(code) >= 4 ORDER BY code',
        'code',
        'guid'
    );
    switch ($action) {
        case 'add':
            {
                if (empty($data->row)) {
                    error('no row');
                }
                if (empty($data->date)) {
                    error('no date');
                }
                if (empty($data->amount)) {
                    error('no amount');
                }
                if (empty($data->from)) {
                    error('no from');
                }
                if (empty($data->to)) {
                    error('no to');
                }
                if (empty($data->text)) {
                    error('no text');
                }

                $dbRow = $bankI->db()->object('SELECT * FROM bank_transactions WHERE bank_t_row = ' . (int) $data->row);

                if (($data->from === $dbRow->account) + ($data->to === $dbRow->account) !== 1) {
                    error('Account missmatch');
                }

                $data->amount = strtr($data->amount, [',' => '.', ' ' => '']);

                $time = strtotime($data->date);
                if (!$time) {
                    error('invalid date');
                }
                $data->date = date('Y-m-d', $time);

                if (empty($accountIds[$data->from])) {
                    error('invalid from account');
                }
                if (empty($accountIds[$data->to])) {
                    error('invalid to account');
                }

                $gnuCash = Auth::new_gnucash();

                if (!$gnuCash->GUIDExists($accountIds[$data->from])) {
                    error('missing from account');
                }
                if (!$gnuCash->GUIDExists($accountIds[$data->to])) {
                    error('missing to account');
                }

                $error = $gnuCash->createTransaction(
                    $accountIds[$data->to],
                    $accountIds[$data->from],
                    abs($data->amount),
                    $data->text,
                    $data->date,
                    ''
                );

                if (!$gnuCash->lastTxGUID) {
                    error($error);
                }

                $txGuidSql = $bankI->db()->quote($gnuCash->lastTxGUID);
                $accountGuidSql = $bankI->db()->quote($accountIds[$dbRow->account]);
                $query = "SELECT guid FROM splits WHERE tx_guid = {$txGuidSql} AND account_guid = {$accountGuidSql}";
                /** @var string[] $matchingSplits */
                $matchingSplits = $bankI->db()->read($query, null, 'guid');
                if (!count($matchingSplits)) {
                    error('split not added');
                }
                $newGuid = $matchingSplits[0];
                return api('connect', (object) ['row' => $data->row, 'guid' => $newGuid]);
            }
            break;

        case 'connect':
            {
                if (empty($data->row)) {
                    error('no row');
                }
                if (empty($data->guid)) {
                    error('no guid');
                }
                $newGuidSql = $bankI->db()->quote($data->guid);
                $query = "UPDATE bank_transactions SET bank_tid = {$newGuidSql} WHERE bank_tid IS NULL AND bank_t_row = " . (int) $data->row;
                if (!$bankI->db()->update($query)) {
                    error('connecting failed');
                }
                return true;
            }
            break;
        default:
            {
                return false;
            }
            break;
    }
}
