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

use tfyh\api\Transactions;
use tfyh\control\Runner;
use tfyh\data\Config;
use tfyh\util\I18n;

/**
 * The start of the session after successful login.
 */

// ===== initialize
$userRequestedFile = __FILE__;
include_once "../../tfyh/init/init.php";
$config = Config::getInstance();
$runner = Runner::getInstance();
$i18n = I18n::getInstance();

$isAdmin = ($runner->sessions->userRole() == "admin");

// ==== check for updates
$version_notification = "";
if ($isAdmin) {
    $ownVersion = $config->appVersion;
    // the currentApplicationVersion file is updated during the cron jobs.
    $serverVersion = file_get_contents("../../var/Log/currentApplicationVersion");
    if ($serverVersion != $ownVersion)
        $version_notification = "<b>" . $i18n->t("p71s5H|Note:") . "</b> " .
            $i18n->t("hYR1lf|A more recent program ve...", $serverVersion) . " =&gt; " .
            "<a href='../../tfyh/pages/upgrade.php'>" . $i18n->t("7lipsQ|UPGRADE") . "</a></b>";
    else
        $version_notification = $i18n->t("Vhea3D|Your server application ...");
}

// ===== start page output
echo $runner->pageStart();

echo "<h3>" . $i18n->t("g6ljZr|dilbo home for %1", $runner->sessions->userFullName()) . "</h3>";
echo "<p>userId: " . $runner->sessions->userId() . ", ";
echo "e-Mail: " . $runner->sessions->userMail() . ".<br>";
echo $version_notification . "</p>";
echo "<h4>" . $i18n->t("cDfe5p|Boats underway") . "</h4>";
echo "<p> - Awaits implementation - </p>";
echo "<h4>" . $i18n->t("Z4VNs0|Active clients") . "</h4>" . Transactions::getLastAccessesApi();
echo "</div>";
$runner->endScript();
