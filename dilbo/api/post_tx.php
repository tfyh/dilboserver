<?php

use Api\Container;
use Api\TxcHandler;
include_once "../../tfyh/Api/TxcHandler.php";
include_once "../../tfyh/Api/Container.php";

use App\TxHandler;
use Control\LoggerSeverity;
use Control\Monitor;
use Control\Runner;
include_once "../../tfyh/Control/LoggerSeverity.php";
include_once "../../tfyh/Control/Monitor.php";
include_once "../../tfyh/Control/Runner.php";

// ===== initialise the session type to api
$monitor = Monitor::getInstance("api");
$runner = Runner::getInstance();
if ($runner->debugOn)
    $runner->logger->log(LoggerSeverity::DEBUG, "post_tx.php", "Request handling started at " . date("H:i:s"));

// ===== parse tx container and return, if parsing errors occur
$txc = (isset($_POST["txc"])) ? trim($_POST["txc"]) : "";
$container = Container::getInstance();
$container->parseRequest(trim($txc));
if ($container->txc["containerResultCode"] >= 40)
    $container->sendResponseAndExit();

// ===== now start the script execution. Since the $runner and $monitor are singleton classes, the normal script start
// ===== can be called.
$userRequestedFile = __FILE__;
include_once "../../tfyh/init/init.php";

// ===== handle all transactions
$txc_handler = new TxcHandler();  // this is the standard framework class
$tx_handler = new TxHandler();  // This is the application-specific handling class
$txc_handler->handleRequestContainer($tx_handler, $runner->menu);
if ($runner->debugOn)
    $runner->logger->log(LoggerSeverity::DEBUG, "post_tx.php", "Request handling completed at " . date("H:i:s"));
$container->sendResponseAndExit();
