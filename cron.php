<?php

	require_once(__DIR__ . "/auth.php");

	$db = Auth::new_db();

	$db->write("DELETE gnclock FROM gnclock INNER JOIN gnclock_ts USING (PID, Hostname) WHERE ts < NOW() - INTERVAL 1 HOUR");
	$db->write("UPDATE transactions SET post_date = post_date + INTERVAL 12 HOUR WHERE DATE(post_date) < DATE(CONVERT_TZ(post_date, 'UTC', 'SYSTEM'))");

/*

CREATE TABLE `gnclock_ts` (
  `Hostname` varchar(255) NOT NULL DEFAULT '',
  `PID` int(11) NOT NULL DEFAULT '0',
  `ts` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`PID`,`Hostname`),
  CONSTRAINT `gnclock_ts_ibfk_1` FOREIGN KEY (`PID`, `Hostname`) REFERENCES `gnclock` (`PID`, `Hostname`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8

DELIMITER #

CREATE TRIGGER gnclock_add AFTER INSERT ON gnclock
FOR EACH ROW
BEGIN
	INSERT INTO gnclock_ts(Hostname, PID) VALUES (new.Hostname, new.PID);
END#

DELIMITER ;

*/
