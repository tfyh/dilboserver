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
use Data\Config;
use Data\WordIndex;
use Util\I18n;

/**
 * A selection of operations tasks
 */

// ===== initialize
$userRequestedFile = __FILE__;
include_once "../../tfyh/init/init.php";
$runner = Runner::getInstance();
$i18n = I18n::getInstance();
$config = Config::getInstance();

$do = isset($_GET["do"]) ? intval($_GET["do"]) : 0;
$done = isset($_GET["done"]) ? intval($_GET["done"]) : 0;

if ($do == 1) {
    $word_index = new WordIndex();
    $word_index->rebuild();
    header("Location: ?done=1");
}
// for $do == 2 do nothing, because task 2 redirects.

// ===== start page output
echo $runner->pageStart();

echo "<h3>" . $i18n->t("FVuVpT|Operations Tasks") . "</h3>";
$tasks = [
    $i18n->t("nTIsc0|Rebuild the word index."),
    $i18n->t("B9ys2A|Run database audit."),
    $i18n->t("TdJY1c|Run housekeeping")
];

if ($done > 0)
    echo "<p>" . $i18n->t("v0ntYf|The task °%1° was comple...", $tasks[$done - 1]) . "</p>";
echo "<ol>\n";
echo "<li><a href='?do=1'>" . $tasks[0] . "</a></li>\n";
echo "<li><a href='../../tfyh/pages/databaseAudit.php'>" . $tasks[1] . "</a></li>\n";
echo "<li><a href='../../dilbo/pages/runCronJobs.php'>" . $tasks[2] . "</a></li>\n";
echo "</ol>";
$runner->endScript();
