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

use tfyh\control\Runner;
use tfyh\data\Config;
use tfyh\util\I18n;

/**
 * The help page (simple text)
 */

// ===== initialize
$userRequestedFile = __FILE__;
include_once "../../tfyh/init/init.php";
$runner = Runner::getInstance();
$i18n = I18n::getInstance();

// ===== start page output
echo $runner->pageStart();

// page heading, identical for all workflow steps

echo "<h3><br><br><br>" . $i18n->t("9fGTH0|Help on application usag...") . "</h3>";
$appUrl = Config::getInstance()->getItem(".framework.app.url")->valueStr();
echo "<p>" .
         $i18n->t( "hmrHzL|Please note, that any do...", "<a href='$appUrl' target='_blank'>$appUrl</a>") . "</p>";
$runner->endScript();
