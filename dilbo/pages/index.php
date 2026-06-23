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

use Control\Runner;
include_once "../../tfyh/Control/Runner.php";

use Data\Config;
include_once "../../tfyh/Data/Config.php";

/**
 * If just the main domain URL is entered, show the about page.
 */

// ===== initialise toolbox and socket and start session.
$userRequestedFile = __FILE__;
include_once "../../tfyh/init/init.php";
$language = Config::getInstance()->language()->value;

// ===== start page output
echo Runner::getInstance()->pageStart();

echo file_get_contents("../../dilbo/Texts/$language/about.html");

Runner::getInstance()->endScript();