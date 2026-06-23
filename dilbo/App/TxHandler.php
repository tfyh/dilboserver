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

namespace App;

use Api\Transaction;
use Api\TxHandlerIF;
use Api\Container;
use Api\ResultForTransaction;
use Control\LoggerSeverity;
use Control\Menu;
use Control\Runner;
use Control\Sessions;

/**
 * The transaction handling class. Clients are the app web-Application or the app Java application or
 * other.
 */
class TxHandler implements TxHandlerIF
{

    /**
     * the app API class providing API transaction support.
     */
    private Transaction $api;

    private Runner $runner;
    private bool $debugOn;

    /**
     * public Constructor.
     */
    public function __construct()
    {
        $this->runner = Runner::getInstance();
        $this->debugOn = $this->runner->debugOn;
        $this->api = new Transaction(new DilboRecordHandler());
    }

    /**
     * Executes a single transaction based on the provided index and menu permission settings. The result_code and the
     *  result_message fields of the transaction are also set according to the transaction result.
     *
     * @param Container $container The container of transactions of aa API handshake.
     * @param int $index The index of the transaction in the transaction container.
     * @param Menu $menu An instance of the Menu class used to verify permissions for the transaction.
     * @return void This method does not return a value, instead the transaction within the containr is modified
     * to represent the result..
     */
    public function executeTransaction(Container $container, int $index, Menu $menu): void
    {
        if ($this->debugOn)
            $this->runner->logger->log(LoggerSeverity::DEBUG, "executeTransaction",
                $container->transactionToLog($index, false));

        $txType = $container->txs[$index]["type"];
        $txTableName = $container->txs[$index]["tableName"];
        $record = $container->txs[$index]["record"];
        $txResultCode = $container->txs[$index]["resultCode"];
        $txResponse = "65;programming fault. Please raise a support request.";

        $typeRecognized = true;
        // check user rights
        $transactionPath = "api/" . $txType;
        $isAllowed = $menu->isAllowedMenuItem($transactionPath);
        if (!$isAllowed) {
            $txResponse = ResultForTransaction::TRANSACTION_FORBIDDEN->value .
                ";Transaction '" . $container->txs[$index]["type"] .
                "' not allowed in table " . $txTableName . " for role '" . Sessions::getInstance()->userRole() .
                "'";
            if ($this->debugOn)
                $this->runner->logger->log(LoggerSeverity::DEBUG, "executeTransaction",
                    "Aborting because of insufficient user rights.");
        } elseif ($txResultCode != 0)
            $txResponse = $txResultCode . ";" . $container->txs[$index]["resultMessage"];

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
            $txResponse = $this->api->apiSession($container->txc["sessionId"], $txTableName);
        elseif (strcasecmp($txType, "info") == 0) {
            $txResponse = ResultForTransaction::REQUEST_SUCCESSFULLY_PROCESSED->value . ";not yet implemented";
        } elseif (strcasecmp($txType, "parsingError") == 0)
            // parsing error
            $txResponse = $container->txs[$index]["resultCode"] . ";" .
                $container->txs[$index]["resultMessage"];
        else
            $typeRecognized = false;

        // Error to return on unrecognized transaction type
        if (!$typeRecognized) {
            // 501 => "Transaction invalid."
            $txResponse = ResultForTransaction::TRANSACTION_INVALID->value . ";Invalid type used: " . $txType .
                " for table " . $txTableName . " (API version of request = " . $container->txc["version"] . ").";
            $this->runner->logger->log(LoggerSeverity::ERROR, "executeTransaction",
                    "Transaction has invalid type: $txType");
        }

        // pass the result to the transaction
        $txResultCode = substr($txResponse, 0, 2);
        $container->txs[$index]["resultCode"] = intval($txResultCode);
        $container->txs[$index]["resultMessage"] = substr($txResponse, 3);
        if ($this->debugOn)
            $this->runner->logger->log(LoggerSeverity::DEBUG, "executeTransaction",
                "Transaction #" . $container->txs[$index]["transactionId"] .
                " completed with result: " . $container->txs[$index]["resultCode"] . ":" .
                substr($container->txs[$index]["resultMessage"], 0, 250) . " ... .");
    }
}