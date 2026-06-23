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
 * The form for upload of the club logo.
 */

use Control\Runner;
use Data\Config;
use Util\Form;
use Util\I18n;

// ===== initialize
$userRequestedFile = __FILE__;
include_once "../../tfyh/init/init.php";
$i18n = I18n::getInstance();
$runner = Runner::getInstance();
$todo = ($runner->done == 0) ? 1 : $runner->done;
$formErrors = "";
$findResultHtml = $i18n->t("hpKnGj|I°m afraid there is noth...");

$formDefinition = [
    1 => "R;logo_file,;\n".
        "R;submit;" . $i18n->t("t7YNl9|Upload"),
    2 => ""
];

$tmpUploadFile = "";

// === APPLICATION LOGIC ==============================================================
// ======== Start with form filled in last step: check of the entered values.
if ($runner->done > 0) {
    $formFilled = new Form(Config::getInstance()->invalidItem,
        $formDefinition[$runner->done]);
    $formFilled->validate(); // (includes password rule check)
    $formErrors = $formFilled->formErrors;
    $validatedEntries = $formFilled->getEntered(false);

    // application logic, step by step
    if (strlen($formErrors) == 0) { // do nothing if form errors occurred.
        // do nothing. This avoids any change if form errors occurred.
        // step 1 form was filled. Values were valid
        if (strlen($_FILES['logo_file']["name"]) < 1) {
            // Special case upload error. user file cannot be checked after
            // being entered, must be checked
            // after upload was tried.
            $formErrors .= $i18n->t("UGYekG|No file name provided. P...");
        } else {
            $logoFile = "../../var/Uploads/tenant/logo.png";
            $tmpUploadFile = file_get_contents($_FILES['logo_file']["tmp_name"]);
            if (! $tmpUploadFile)
                $formErrors .= $i18n->t("OcbYIX|Undefined error on uploa...");
            else {
                $storeResult = file_put_contents($logoFile, $tmpUploadFile);
                if ($storeResult !== false)
                    $todo = $runner->done + 1;
                else
                    $formErrors .= $i18n->t("nHhQOA|Error when saving tempor...",
                        $tmpUploadFile, $logoFile);
            }
        }
    }
}

// ==== continue with the definition and eventually initialisation of form to fill for the next step
if (isset($formFilled) && ($todo == $runner->done)) {
    // redo the 'done' form, if the $to do == $done, i.e. the validation failed.
    $formToFill = $formFilled;
} else {
    // if it is the start or all is fine, use a form for the coming step.
    $formToFill = new Form(Config::getInstance()->invalidItem, $formDefinition[$todo]);
}

// === PAGE OUTPUT ===================================================================
echo $runner->pageStart();

// page heading, identical for all workflow steps

echo "<h3>" . $i18n->t("tcyZNe|Upload of your club logo...") . "</h3>";
echo "<p>" . $i18n->t("TaDJo1|Your logo must be a .png...") . "</p>";
if ($todo == 1) { // step 1. Texts for output
    echo Form::formErrorsToHtml($formErrors);
    echo $formToFill->getHtml(true); // enable file upload
} elseif ($todo == 2) { // step 2. Texts for output
    echo "<p>" . $i18n->t("Lk3S4w|The file upload was succ...") .
             "<br><a href='../../dilbo/forms/uploadLogo.php'>" . $i18n->t("boE0VN|Change logo") . "</a></p>";
}
// page footer for output.
echo "</div>";
$runner->endScript();
