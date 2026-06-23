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

namespace dilbo\pages;

use Control\Runner;
use Control\Users;
use Data\Config;
use Data\DatabaseConnector;
use Data\Record;
use Util\I18n;
use Util\Language;

/**
 * The page to display the user's profile
 */

// ===== initialize
$userRequestedFile = __FILE__;
include_once "../../tfyh/init/init.php";
$runner = Runner::getInstance();
$i18n = I18n::getInstance();
$config = Config::getInstance();

// ===== start page output
echo $runner->pageStart();

echo "<h3>" . $i18n->t("j8pXJK|Profile of") . " " . $runner->sessions->userFullName() . "</h3>";
echo "<p>" . $i18n->t("Alao36|This is your personal pr...") . "</p>";
echo "</div>\n<div class='w3-container'>";

$user = DatabaseConnector::getInstance()->find($runner->users->userTableName, $runner->users->userIdFieldName,
    $runner->sessions->userId());
if (strcasecmp($runner->sessions->userRole(), $user["role"]) !== 0)
    echo "<p style='color:#f00'><b>" . $i18n->t("XRkVQO|logged in as °%1°", $runner->sessions->userRole()) .
             "</b></p>";

$userTableName = Users::getInstance()->userTableName;
$userRecordItem = $config->getItem(".tables.$userTableName");
$userRecord = new Record($userRecordItem);
$userRecord->parse($user, Language::SQL);
echo $userRecord->toHtmlTable($config->language());

if (strcasecmp($runner->sessions->userRole(), "bths") !== 0)
    echo "<br><a href='../../tfyh/forms/changeUser.php?uid=" . $user["uid"] . "'> &gt; " . $i18n->t("IZjKgK|Change profile") . "</a>";

echo "</div>\n<div class='w3-container'>";
echo file_get_contents("../../dilbo/Texts/" . $config->language()->value . "/dataPrivacyDisclaimer.html");
echo "</div>";

$runner->endScript();
