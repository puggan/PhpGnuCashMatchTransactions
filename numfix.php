<?php

	require_once(__DIR__ . "/auth.php");

	$db = Auth::new_db();

	$weeks = $db->read("SELECT YEARWEEK(post_date, 1) AS y FROM transactions WHERE num = '' AND post_date < NOW() - INTERVAL 1 MONTH GROUP BY 1", 'y', 'y');

	foreach($weeks as $week)
	{
		$ok = TRUE;
		$prefix = substr($week, 0, 4) . 'w' . substr($week, 4) . 't';
		$last = 0;
		$transactions = $db->read("SELECT guid, num FROM transactions WHERE YEARWEEK(post_date, 1) = " . ((int) $week) . " ORDER BY post_date, enter_date" , 'guid', 'num');
		$used_nums = array();
		foreach($transactions as $guid => $num)
		{
			if($num)
			{
				if(isset($used_nums[$num]))
				{
					$ok = FALSE;
					trigger_error("{$num} used in multiple transactions: {$used_nums[$num]}, {$guid}");
				}
				else if(substr($num, 0, strlen($prefix)) != $prefix)
				{
					$ok = FALSE;
					trigger_error("{$num} don't match {$prefix}");
				}
				else
				{
					$used_nums[$num] = $guid;
				}
			}
		}
		if(!$ok)
		{
			continue;
		}
		foreach($transactions as $guid => $num)
		{
			if($num)
			{
				$nr = (int) ltrim(substr($num, strlen($prefix)), '0');
				$last = max($nr, $last);
			}
			else
			{
				$next = $last + 1;
				$new_num = $prefix . ($next < 10 ? '0' : '') . $next;
				while(isset($used_nums[$new_num]))
				{
					$next++;
					$new_num = $prefix . ($next < 10 ? '0' : '') . $next;
				}

				$guid_sql = $db->quote($guid);
				$num_sql = $db->quote($new_num);
				$db->write("UPDATE transactions SET num = {$num_sql} WHERE guid = {$guid_sql}");

				$last = $next;
				$used_nums[$new_num] = $guid;
echo "UPDATE transactions SET num = {$num_sql} WHERE guid = {$guid_sql};\n";
			}
		}
	}

/*
SELECT
   transactions.num,
   DATE(transactions.post_date),
   transactions.description,
   REPLACE(
      GROUP_CONCAT(
         DISTINCT
         FORMAT(
            ABS(splits.value_num / splits.value_denom),
            2)
         SEPARATOR ' - '),
      ',',
      '')
FROM transactions
   INNER JOIN splits ON (splits.tx_guid = transactions.guid)
WHERE transactions.num > '2016w35t01'
GROUP BY transactions.guid
ORDER BY transactions.num
INTO OUTFILE '/tmp/transactions.csv';
*/
