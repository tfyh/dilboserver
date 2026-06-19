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

class Notifier
{

    /**
     * Construct the Util class. This reads the configuration, initialises the logger and the navigation menu,
     * asf.
     */
    public function __construct() {}

    /**
     * Notification of an API write transaction: new reservation, new damage, new admin message to a mail
     * account.
     * @param int $mode The mode indicating the type of database operation performed (e.g., insert, update, delete).
     * @param string $tableName The name of the database table where the write operation occurred.
     * @param array $record An associative array containing the data involved in the write operation.
     * @return void
     */
    public function notifyDbWriteEvent(int $mode, string $tableName, array $record): void
    {
    }
}    
