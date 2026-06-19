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

/**
 * The display of a user profile
 */
namespace dilbo\pages;

use tfyh\control\Runner;
use tfyh\data\Codec;
use tfyh\data\DatabaseConnector;
use tfyh\util\I18n;

$userRequestedFile = __FILE__;
include_once "../../tfyh/init/init.php";
$runner = Runner::getInstance();
$i18n = I18n::getInstance();
if (! isset($_SESSION["get_parameters"][$runner->fsId]["uid"]) || (strlen($_SESSION["get_parameters"][$runner->fsId]["uid"]) == 0))
    $runner->displayError($i18n->t("jA4eJf|Not allowed"), $i18n->t("NiYUV5|You have to provide the ..."),
        $userRequestedFile);

$user = DatabaseConnector::getInstance()->find($runner->users->userTableName, "uid",
    $_SESSION["get_parameters"][$runner->fsId]["uid"]);
if ($user === false)
    $runner->displayError($i18n->t("jA4eJf|Not allowed"), $i18n->t("HdnMsl|the provided user uid is..."),
        $userRequestedFile);

// ===== start page output
echo $runner->pageStart();
$userFullName = $user[$runner->users->userFirstNameFieldName] . " " . $user[$runner->users->userLastNameFieldName];

echo "<h3>" . $i18n->t("j8pXJK|Profile of") . " " . $userFullName . "</h3>";
echo "</div>\n<div class='w3-container'>";
echo Codec::tableToHtml($user, true);
echo "</div>";
$runner->endScript();
