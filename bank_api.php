<?php
/**
 * Created by PhpStorm.
 * User: puggan
 * Date: 2017-12-30
 * Time: 18:54
 */

require_once(__DIR__ . "/bank_funk.php");
$bi = new Bank_interface();

// TODO replace all $bi->db() with function calls

$data = (object) $_POST;

function error($txt)
{
    header('HTTP/1.0 400 Fail');
    echo json_encode(['ok' => false, 'error' => $txt, 'debug' => ['data' => $_POST]]);
    die();
}

if (empty($data->action)) {
    error('no action');
}

api($data->action, $data);

echo json_encode(['ok' => true, 'debug' => ['data' => $_POST]]);
die();

function api($action, $data)
{
    global $bi;

    $account_ids = $bi->db()->read(
        "SELECT code, guid FROM `accounts` WHERE LENGTH(code) >= 4 ORDER BY code",
        "code",
        "guid"
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

                $row = $bi->db()->object("SELECT * FROM bank_transactions WHERE bank_t_row = " . (int) $data->row);

                if ((($data->from == $row->account) + ($data->to == $row->account)) != 1) {
                    error('Account missmatch');
                }

                $data->amount = strtr($data->amount, [',' => '.', ' ' => '']);

                $time = strtotime($data->date);
                if (!$time) {
                    error('invalid date');
                }
                $data->date = date("Y-m-d", $time);

                if (empty($account_ids[$data->from])) {
                    error('invalid from account');
                }
                if (empty($account_ids[$data->to])) {
                    error('invalid to account');
                }

                $GnuCash = Auth::new_gnucash();

                if (!$GnuCash->GUIDExists($account_ids[$data->from])) {
                    error('missing from account');
                }
                if (!$GnuCash->GUIDExists($account_ids[$data->to])) {
                    error('missing to account');
                }

                $error = $GnuCash->createTransaction(
                    $account_ids[$data->to],
                    $account_ids[$data->from],
                    abs($data->amount),
                    $data->text,
                    $data->date,
                    ''
                );

                if (!$GnuCash->lastTxGUID) {
                    error($error);
                }

                $tx_guid_sql = $bi->db()->quote($GnuCash->lastTxGUID);
                $account_guid_sql = $bi->db()->quote($account_ids[$row->account]);
                $query = "SELECT guid FROM splits WHERE tx_guid = {$tx_guid_sql} AND account_guid = {$account_guid_sql}";
                $matching_splits = $bi->db()->read($query, null, 'guid');
                if (!count($matching_splits)) {
                    error('split not added');
                }
                $new_guid = $matching_splits[0];
                return api('connect', (object) ['row' => $data->row, 'guid' => $new_guid]);
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
                $new_guid_sql = $bi->db()->quote($data->guid);
                $query = "UPDATE bank_transactions SET bank_tid = {$new_guid_sql} WHERE bank_tid IS NULL AND bank_t_row = " . (int) $data->row;
                if (!$bi->db()->update($query)) {
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
