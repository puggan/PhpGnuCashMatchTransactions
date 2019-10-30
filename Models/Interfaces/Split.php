<?php
declare(strict_types=1);

namespace Models\Interfaces;

/**
 * @property string guid
 * @property string tx_guid
 * @property string account_guid
 * @property string memo
 * @property string action
 * @property string reconcile_state
 * @property string reconcile_date
 * @property int value_num
 * @property int value_denom
 * @property int quantity_num
 * @property int quantity_denom
 * @property string lot_guid
 * @property int|float value
 * @property int|float quantity
 */
interface Split
{
}
