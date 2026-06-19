<?php
/**
 * dilbo - digital logbook for Rowing and Canoeing
 * https://www.dilbo.org
 * Copyright:  2023-2025  Martin Glade
 *
 * Licensed under the Apache License, Version 2.0 (the "License"); you may not use this file except
 * in compliance with the License. You may obtain a copy of the License at
 * http://www.apache.org/licenses/LICENSE-2.0
 * Unless required by applicable law or agreed to in writing, software distributed under the License
 * is distributed on an "AS IS" BASIS, WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express
 * or implied. See the License for the specific language governing permissions and limitations under
 * the License.
 */

namespace dilbo\app;

include_once "DilboRecordHandler.php";

use tfyh\api\Transactions;
use tfyh\api\Container;
use tfyh\api\ResultForTransaction;
use tfyh\control\LoggerSeverity;
use tfyh\control\Menu;
use tfyh\control\Runner;
use tfyh\control\Sessions;

/**
 * The transaction handling class. Clients are the app web-Application or the app Java application or
 * other.
 */
class TxHandler
{

    /**
     * the app API class providing API transaction support.
     */
    private Transactions $api;

    private Runner $runner;
    private bool $debugOn;
    private Container $container;

    /**
     * public Constructor.
     */
    public function __construct()
    {
        $this->runner = Runner::getInstance();
        $this->debugOn = $this->runner->debugOn;
        $this->api = new Transactions(new DilboRecordHandler());
        $this->container = Container::getInstance();
    }

    /**
     * This method verifies the transaction token and sets the current transaction array.
     * @param Menu $menu the menu object to verify the user rights.
     * @return void
     */
    public function handleRequestContainer(Menu $menu): void
    {
        if ($this->debugOn)
            $this->runner->logger->log(LoggerSeverity::DEBUG, "handleRequestContainer",
                "Started");
        for ($i = 0; $i < count($this->container->txs); $i++) {
            $this->runner->logger->log(LoggerSeverity::DEBUG, "handleRequestContainer",
                "Executing " . $this->container->transactionToLog($i, false));
            $this->executeTransaction($i, $menu);
            $isError = (intval($this->container->txs[$i]["resultCode"]) >= 40);
            if ($isError) {
                $this->runner->logger->log(LoggerSeverity::ERROR, "handleRequestContainer",
                    "Transaction handling failed for " . $this->container->transactionToLog($i, true));
            } else
                $this->runner->logger->log(LoggerSeverity::INFO, "handleRequestContainer",
                    "Transaction handling succeeded for " . $this->container->transactionToLog($i, false));
        }
    }

    /**
     * Executes a single transaction based on the provided index and menu permission settings. The result_code and the
     *  result_message fields of the transaction are also set according to the transaction result.
     *
     * @param int $index The index of the transaction in the transaction container.
     * @param Menu $menu An instance of the Menu class used to verify permissions for the transaction.
     * @return void This method does not return a value.
     */
    private function executeTransaction(int $index, Menu $menu): void
    {
        if ($this->debugOn)
            $this->runner->logger->log(LoggerSeverity::DEBUG, "executeTransaction",
                $this->container->transactionToLog($index, false));

        $txType = $this->container->txs[$index]["type"];
        $txTableName = $this->container->txs[$index]["tableName"];
        $record = $this->container->txs[$index]["record"];
        $txResultCode = $this->container->txs[$index]["resultCode"];
        $txResponse = "65;programming fault. Please raise a support request.";

        $typeRecognized = true;
        // check user rights
        $transactionPath = "api/" . $txType;
        $isAllowed = $menu->isAllowedMenuItem($transactionPath);
        if (!$isAllowed) {
            $txResponse = ResultForTransaction::TRANSACTION_FORBIDDEN->value .
                ";Transaction '" . $this->container->txs[$index]["type"] .
                "' not allowed in table " . $txTableName . " for role '" . Sessions::getInstance()->userRole() .
                "'";
            if ($this->debugOn)
                $this->runner->logger->log(LoggerSeverity::DEBUG, "executeTransaction",
                    "Aborting because of insufficient user rights.");
        } elseif ($txResultCode != 0)
            $txResponse = $txResultCode . ";" . $this->container->txs[$index]["resultMessage"];

        // Write data
        elseif (strcasecmp($txType, "insert") == 0)
            $txResponse = $this->api->apiModify($txTableName, $record, 1);
        elseif (strcasecmp($txType, "update") == 0)
            $txResponse = $this->api->apiModify($txTableName, $record, 2);
        elseif (strcasecmp($txType, "delete") == 0)
            $txResponse = $this->api->apiModify($txTableName, $record, 3);

        // Read data
        elseif (strcasecmp($txType, "list") == 0) {
            if (str_starts_with($txTableName, "."))
                $txResponse = $this->api->apiCfgList($txTableName);
            else
                $txResponse = $this->api->apiList($txTableName, $record);
        } //

        // Support functions - nop (app clients), session (start, regenerate, close), config (get config
        // settings file) and info
        elseif (strcasecmp($txType, "nop") == 0)
            $txResponse = $this->api->apiNop($record);
        elseif (strcasecmp($txType, "housekeeping") == 0)
            $txResponse = $this->api->apiHousekeeping();
        elseif (strcasecmp($txType, "session") == 0)
            $txResponse = $this->api->apiSession($this->container->txc["sessionId"], $txTableName);
        elseif (strcasecmp($txType, "info") == 0) {
            $txResponse = ResultForTransaction::REQUEST_SUCCESSFULLY_PROCESSED->value . ";not yet implemented";
        } elseif (strcasecmp($txType, "parsingError") == 0)
            // parsing error
            $txResponse = $this->container->txs[$index]["resultCode"] . ";" .
                $this->container->txs[$index]["resultMessage"];
        else
            $typeRecognized = false;

        // Error to return on unrecognized transaction type
        if (!$typeRecognized) {
            // 501 => "Transaction invalid."
            $txResponse = ResultForTransaction::TRANSACTION_INVALID->value . ";Invalid type used: " . $txType .
                " for table " . $txTableName . " (API version of request = " . $this->container->txc["version"] . ").";
            $this->runner->logger->log(LoggerSeverity::ERROR, "executeTransaction",
                    "Transaction has invalid type: $txType");
        }

        // pass the result to the transaction
        $txResultCode = substr($txResponse, 0, 2);
        $this->container->txs[$index]["resultCode"] = intval($txResultCode);
        $this->container->txs[$index]["resultMessage"] = substr($txResponse, 3);
        if ($this->debugOn)
            $this->runner->logger->log(LoggerSeverity::DEBUG, "executeTransaction",
                "Transaction #" . $this->container->txs[$index]["transactionId"] .
                " completed with result: " . $this->container->txs[$index]["resultCode"] . ":" .
                substr($this->container->txs[$index]["resultMessage"], 0, 250) . " ... .");
    }
}