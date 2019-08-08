<?php
/**
 * Created by PhpStorm.
 * User: acarrasco
 * Date: 1/29/19
 * Time: 12:25 PM
 */


include_once getcwd() .  "/src/config/config.php";
include_once getcwd() . '/src/SumaAPIProcessor.php';
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
foreach ($argv as $arg){
	$arg = escapeshellcmd($arg);

	switch ($arg) {
		case "all":
			$harvester = new SumaAPIProcessor(false);
			$harvester->getCountsFromBeginningOfTime();
			die();
			break;
		case "delete-all-archive":
			$harvester = new SumaAPIProcessor(false);
			$harvester->remove_all_from_google_drive(true);
			die();
			break;
		case "delete-all-daily":
			$harvester = new SumaAPIProcessor(true);
			$harvester->remove_all_from_google_drive();
			die();
			break;
	}
}

$harvester = new SumaAPIProcessor(true);
$harvester->getPreviousDayCount();