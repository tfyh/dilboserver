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

use tfyh\data\Codec;

/**
 * This class provides the API to an efaCloud server to retrieve configuration from there
 */
class EfaCloudApi
{

    /**
     * explanation texts for server result codes. array key = code, value = explanation text.
     */
    public static array $result_codes = [300 => "Transaction completed.",400 => "XHTTPrequest Error.",
            401 => "Syntax error.",402 => "Unknown client.",403 => "Authentication failed.",
            404 => "Server side busy.",405 => "Wrong transaction ID.",406 => "Overload detected.",
            407 => "No database connection.",500 => "Transaction container aborted.",
            501 => "Transaction invalid.",502 => "Transaction failed.",
            503 => "Transaction missing in container.",504 => "Transaction container decoding failed.",
            505 => "Server response empty",506 => "Internet connection aborted",
            507 => "Could not decode server response"
    ];

    /**
     * The transaction separator String
     */
    public static string $transactionSeparator = "\n|-eFa-|\n";

    /**
     * The transaction separator replacement String
     */
    public static string $transactionSeparatorReplacement = "\n|-efa-|\n";

    /**
     * The efaCloud server URL to be connected to
     */
    private string $server;

    /**
     * The efaCloudUserID to be used for the connection
     */
    private int $clientID;

    /**
     * The password of the efaCloudUser to be used for the connection
     */
    private string $password;

    /**
     * The first transaction id to be used for the connection
     */
    private int $txId = 42;

    /**
     * The first transaction container id to be used for the connection
     */
    private int $txcId = 42;

    /**
     * Status of the container: open for appending, locked (full or in sending process)
     */
    private string $txcOpen;

    /**
     * The queue of transactions which shall be sent. Maximum capacity is 10 transactions.
     */
    private array $txcMessages = [];

    /**
     * The queue of transactions which shall be sent. Maximum capacity is 10 transactions.
     */
    private array $txcHeader = [];

    /**
     * Construct the instance. URL and credentials are hard coded.
     * @param String $server the URL of the efaCloud server, without the api/posttx.php part.
     * @param String $clientID the efaCloudUserID to be used for the connection
     * @param String $password the password of the efaCloudUser to be used for the connection
     */
    function __construct (String $server, String $clientID, String $password)
    {
        $this->server = (str_ends_with($server, "/")) ? ($server . "api/posttx.php") : ($server . "/api/posttx.php");
        $this->clientID = $clientID;
        $this->password = $password;
        $this->initContainer();
    }

    /**
     * Encode a plain text container. This converts the String first to UTF-8, encodes the result in base64,
     * and replaces the characters "=/+" by "_-*" respectively.
     * @param String $txcPlain the plain text container to be encoded
     * @return string the encoded container
     */
    private static function encodeContainer (String $txcPlain): string
    {
        return str_replace("=", "_", 
                str_replace("/", "-", str_replace("+", "*", base64_encode(mb_convert_encoding($txcPlain, 'UTF-8', 'ISO-8859-1')))));
    }

    /**
     * Decode a plain text container. This replaces the characters "_-*" by "=/+" respectively. It then decodes
     * the base64 sequence and finally decodes the resulting UTF-8 String to PHP native.
     * @param String $txcEncoded the encoded container to be decoded
     * @return string the decoded container
     */
    private static function decodeContainer (String $txcEncoded): string
    {
        return base64_decode(
                str_replace("_", "=", str_replace("-", "/", str_replace("*", "+", $txcEncoded))));
    }

    /**
     * clear the container header and remove all messages from the container
     */
    private function initContainer (): void
    {
        $this->txcHeader["version"] = 3; // as with efaWeb. Used to ensure checks are performed at
                                          // efaCloud side.
        $this->txcHeader["containerId"] = 0;
        $this->txcHeader["container_result_code"] = 502;
        $this->txcHeader["container_result_message"] = "[default on construction]";
        $this->txcMessages = [];
        $this->txcOpen = true;
    }

    /**
     * Creates a transaction container String as plain text.
     */
    private function createContainer (): string
    {
        $this->txcId ++;
        $this->txcHeader["containerId"] = $this->txcId;
        $txcPlain = $this->txcHeader["version"] . ";" . $this->txcHeader["containerId"] . ";" . $this->clientID .
                 ";" . $this->password . ";";
        foreach ($this->txcMessages as $txID => $transaction) {
            $txmPlain = $txID . ";" . $transaction["retries"] . ";" . $transaction["type"] . ";" .
                     $transaction["table_name"];
            foreach ($transaction["record"] as $key => $value) {
                $encodedValue = Codec::encodeCsvEntry($value);
                $encodedValue = str_replace(self::$transactionSeparator,
                        self::$transactionSeparatorReplacement, $encodedValue);
                $txmPlain .= ";" . $key . ";" . $encodedValue;
            }
            $txcPlain .= $txmPlain . self::$transactionSeparator;
        }
        return substr($txcPlain, 0, strlen($txcPlain) - strlen(self::$transactionSeparator));
    }

    /**
     * Append a single transaction for later sending.
     * @param String $type the type of the transaction, e.g. "insert", "update", "delete"
     * @param String $tableName the name of the table to be operated on
     * @param array $record the record to be operated on, e.g. ["uid"=>123456789, "name"=>""]
     * @return int|bool the transaction id, or false if the container is full
     */
    public function appendTransaction (String $type, String $tableName, array $record): int|bool
    {
        if (! $this->txcOpen)
            return false;
        $tx = array();
        $this->txId ++;
        $tx["retries"] = 0;
        $tx["type"] = $type;
        $tx["table_name"] = $tableName;
        $tx["record"] = $record;
        $tx["result_code"] = 502;
        $tx["result_message"] = "[default on construction]";
        $this->txcMessages[$this->txId] = $tx;
        if (count($this->txcMessages) == 10)
            $this->txcOpen = false;
        return $this->txId;
    }

    /**
     * add a container error to all messages in the buffer
     */
    private function addContainerErrorToMessages (): void
    {
        foreach ($this->txcMessages as $tx_id => $tx_message) {
            $this->txcMessages[$tx_id]["result_code"] = $this->txcHeader["container_result_code"];
            $this->txcMessages[$tx_id]["result_message"] = "transaction container error: " .
                     $this->txcHeader["container_result_message"] . " Transaction ignored.";
        }
    }

    /**
     * Parse the container and handle errors
     * 
     * @param String $decodedResponse
     *            The response received from efaCloud
     */
    private function parseResponseContainer (String $decodedResponse): void
    {
        $response_array = explode(";", $decodedResponse, 5);
        $this->txcHeader["container_result_code"] = intval($response_array[2]);
        $this->txcHeader["container_result_message"] = $response_array[3];
        if ($this->txcHeader["container_result_code"] >= 400)
            $this->addContainerErrorToMessages();
        else {
            $tx_responses = explode(self::$transactionSeparator, $response_array[4]);
            foreach ($tx_responses as $tx_response) {
                $response_array = explode(";", $tx_response, 3);
                $txID = intval($response_array[0]);
                if (isset($this->txcMessages[$txID])) {
                    $this->txcMessages[$txID]["result_code"] = $response_array[1];
                    $this->txcMessages[$txID]["result_message"] = $response_array[2];
                }
            }
        }
    }

    /**
     * Create a transaction container String for debugging and testing purposes.
     */
    public function sendContainer(): bool
    {
        // close container. No more adding is possible from now on.
        $this->txcOpen = false;
        $container = $this->createContainer();
        $data = array('txc' => $this->encodeContainer($container)
        );
        $options = array(
                'http' => array('header' => "Content-type: application/x-www-form-urlencoded\r\n",
                        'method' => 'POST','content' => http_build_query($data)
                )
        );
        $context = stream_context_create($options);
        $response = file_get_contents($this->server, false, $context);
        
        if ($response === false) {
            $this->txcHeader["container_result_code"] = 400;
            $this->txcHeader["container_result_message"] = "Server access failed completely. " .
                     "Either your server URL is wrong, or the server faces some internal server error.";
            $this->addContainerErrorToMessages();
            return false;
        }
        $response_decoded = $this->decodeContainer($response);
        $this->parseResponseContainer($response_decoded);
        return true;
    }

    /**
     * Reset the communication. This will delete all previous results.
     */
    public function reset (): void
    {
        $this->initContainer();
    }

    /**
     * Retrieve the result of a specific transaction based on its transaction ID.
     * Provides the result code and associated message for the transaction.
     *
     * @param int $txId The ID of the transaction for which to retrieve the result.
     * @return array An array containing the result code and result message. If the transaction is not
     *               found or cannot be processed, an error code and message are returned.
     */
    public function getResult (int $txId): array
    {
        if ($this->txcOpen)
            return [502,"The transaction has not been send, or is still waiting for a response."
            ];
        if (! isset($this->txcMessages[$txId]))
            return [502,"The requested transaction is not in the container."
            ];
        return [$this->txcMessages[$txId]["result_code"],$this->txcMessages[$txId]["result_message"]
        ];
    }
}
