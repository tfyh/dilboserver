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
 * Force to run the daily cron jobs
 */
namespace dilbo\pages;

include_once "../App/DilboCronJobs.php";

use dilbo\app\DilboCronJobs;
use tfyh\control\Runner;
use tfyh\util\I18n;

// ===== initialize
$userRequestedFile = __FILE__;
include_once "../../tfyh/init/init.php";
$runner = Runner::getInstance();
$i18n = I18n::getInstance();

unlink("../../var/Log/cronJobsLastDay");
$logBefore = file_get_contents("../../var/Log/cronJobs.log");
DilboCronJobs::runDailyJobs();
$logAfter = file_get_contents("../../var/Log/cronJobs.log");
$logNow = (mb_strlen($logAfter) > mb_strlen($logBefore)) ? mb_substr($logAfter,
        mb_strlen($logBefore)) : $logAfter;

// ===== start page output
echo $runner->pageStart();

echo "<h3>" . $i18n->t("FVuVpT|Operations Tasks") . "</h3>";

echo $i18n->t("4563fT|The daily maintenance ro...");
echo str_replace("\n", "<br>", $logNow);

echo "  </p></div>";
$runner->endScript();
