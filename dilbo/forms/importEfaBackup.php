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
 * The form for upload and import of multiple data records as csv-tables. Based on the Form class, please read
 * instructions there to better understand this PHP-code part.
 */

// ===== initialize toolbox and socket and start session.
// ===== initialize
namespace dilbo\app;

include_once "../../dilbo/App/EfaImport.php";

use tfyh\control\Runner;
use tfyh\data\Config;
use tfyh\data\DatabaseConnector;
use tfyh\data\DatabaseSetup;
use tfyh\data\Formatter;
use tfyh\data\ParserName;
use tfyh\data\WordIndex;
use tfyh\util\FileHandler;
use tfyh\util\Form;
use tfyh\util\I18n;

$userRequestedFile = __FILE__;
include_once "../../tfyh/init/init.php";
$i18n = I18n::getInstance();
$config = Config::getInstance();
$dbc = DatabaseConnector::getInstance();
$runner = Runner::getInstance();
$todo = ($runner->done == 0) ? 1 : $runner->done;
$formErrors = "";
$formResult = "";

// === APPLICATION LOGIC ==============================================================
$doStep = (isset($_GET["doStep"])) ? intval($_GET["doStep"]) : 0;
$chunk = (isset($_GET["chunk"])) ? intval($_GET["chunk"]) : 0;
$from = (isset($_GET["from"])) ? intval($_GET["from"]) : 0;
$tmpUploadFile = "";

$i18n = I18n::getInstance();
$formDefinition = [
    1 => "R;user_file;\n" .
        "R;~_no_input;" .
        Formatter::format(array($i18n->t("OZGFzr|CAUTION: A configuration...")), ParserName::STRING_LIST) . "\n" .
        "r;import_config;\n" .
        "R;~_no_input;" .
        Formatter::format([ $i18n->t("fK6t7h|CAUTION: A data import u...") ], ParserName::STRING_LIST) . "\n" .
        "r;import_data;\n" .
        "R;~_no_input;" .
        Formatter::format([ $i18n->t("egsf47|Uncheck to only add furt...") ], ParserName::STRING_LIST) . "\n" .
        "r;reset_db;\n" .
        "R;efaCloud_password;\n" .
        "R;submit;" . $i18n->t("0QmP94|Upload"),
    2 => "r;~_no_input;Control handed over to progress management via Javascript.",
    3 => "r;~_no_input;Javascript progress management completed."

];


// ======== start with form filled in last step: check of the entered values.
if ($runner->done > 0) {
    $formFilled = new Form(Config::getInstance()->invalidItem, $formDefinition[$runner->done]);
    $formFilled->validate();
    $formErrors = $formFilled->formErrors;
    $validatedEntries = $formFilled->getEntered();
    // application logic, step-by-step
    // cache parameters. Entered data always contained all data ever entered in a form sequence.
    $resetDb = (isset($validatedEntries["reset_db"])) && (strcasecmp($validatedEntries["reset_db"], "on") == 0);
    $efaCloudPassword = ((isset($validatedEntries["efaCloud_password"])) &&
        (strlen($validatedEntries["efaCloud_password"]) >= 8)) ? $validatedEntries["efaCloud_password"] : false;
    // application logic, step by step
    if (strlen($formErrors) == 0) { // do nothing if form errors occurred.
        if ($runner->done == 1) {
            // step 1 is a simple file upload
            file_put_contents("../../var/Log/efa_import.log", date("Y-m-d H:i:s") . ": Starting import.\n");
            // upload files
            if (($_FILES['user_file']['error'] == UPLOAD_ERR_INI_SIZE) ||
                ($_FILES['user_file']['error'] == UPLOAD_ERR_FORM_SIZE))
                $formErrors .= $i18n->t("Wy3oFO|File size limit exceeded...");
            elseif ($_FILES['user_file']['error'] == UPLOAD_ERR_PARTIAL)
                $formErrors .= $i18n->t("9dSKwb|The file was only partia...");
            elseif ($_FILES['user_file']['error'] > 0)
                $formErrors .= $i18n->t("OVDL2X|There was an error durin...");
            // step 1 form was filled. Values were valid
            elseif (strlen($_FILES['user_file']["name"]) < 1) {
                // Special case upload error. user_file cannot be checked after
                // being entered, must be checked
                // after upload was tried.
                $formErrors .= $i18n->t("4Vpkuw|No file given. Please tr...");
            } else {
                $tmpUploadFile = file_get_contents($_FILES['user_file']['tmp_name']);
                if (!$tmpUploadFile)
                    $formErrors .= $i18n->t("a5koMa|Unknown error during upl...");
                else {
                    $_SESSION["uploadFilename"] = $_FILES['user_file']["name"];
                    $efaImportUploadDir = "../../var/Uploads/efa_import";
                    // remove any previous import. Only the very last counts.
                    if (file_exists($efaImportUploadDir))
                        FileHandler::rrmdir($efaImportUploadDir);
                    mkdir($efaImportUploadDir);
                    $_SESSION["uploadPath"] = $efaImportUploadDir . "/" . $_SESSION["uploadFilename"];
                    $result = file_put_contents($_SESSION["uploadPath"], $tmpUploadFile);
                    if ($result === false)
                        $formErrors .= $i18n->t("w2qoFy|Unknown error while uplo...", $_SESSION["uploadPath"]);
                    else {
                        $_SESSION["uploadResult"] = $result;
                        // restore default settings
                        FileHandler::rrmdir("../../Config/added");
                        FileHandler::rrmdir("../../Config/actual");
                        $todo = $runner->done + 1;
                    }
                }
            }
        }
        // ============== repetitive execution =========================================
        // the "$done == 2" section will be control at every little step anew.
        // The  modal will use the response to trigger the next step.
        // ============== repetitive execution =========================================
        if ($runner->done == 2) {
            // configure stepwise upload efa
            $uploadSteps = 2;
            $configFileSteps = 3;
            $dataFileSteps = (isset($_SESSION["efaImport"]["fileListData"])) ? count(
                $_SESSION["efaImport"]["fileListData"]) : 12;
            $efaCloudDataSteps = 1;
            $wordIndexSteps = 1;
            // configure stepwise upload efaCloud
            $includeEfaCloud = ($efaCloudPassword !== false);

            // data import. Will be performed in fractions to enable progress display
            $efaImport = new EfaImport();
            if ($doStep > 0) {
                $remainder = 0; // this tells that the step is fully done; default
                $doneStep = 0; // no step was executed
                // === Import preparation steps.
                if ($doStep <= $uploadSteps) {
                    if ($doStep == 1) {
                        // Import upload step.
                        $efaImport->addProgress("<h4>" . $i18n->t("jivK7Y|Backup import log") . "</h4>",
                            false);
                        $efaImport->addProgress(
                            $i18n->t("Tu6j0f|%1 Bytes were uploaded.", $_SESSION["uploadResult"]) . "<br>");
                        $efaImport->step1LoadZip($_SESSION["uploadPath"], $resetDb);
                        $doneStep = $doStep;
                    } elseif ($doStep == 2) {
                        if ($resetDb) {
                            // database reset step.
                            $dbSetup = new DatabaseSetup();
                            $resetResult = $dbSetup->initDataBase();
                            $efaImport->addProgress($i18n->t("IJxXoI|Data base was reset.") . "<br>");
                        } else
                            $efaImport->addProgress($i18n->t("h0EUOC|Data base reset was skip...") . "<br>");
                        // block completed
                        $efaImport->addProgress("<br>");
                        $doneStep = $doStep;
                    }
                }
                // === Import configuration steps.
                if (($doStep > $uploadSteps) && ($doStep <= ($uploadSteps + $configFileSteps))) {
                    if (strcasecmp($validatedEntries["import_config"], "on") == 0) {
                        // execute step
                        if ($doStep == 3) {
                            // import efa configuration.
                            $efaImport->step3aClearImport();
                            $efaImport->step3bImportEfaConfig();
                        } elseif ($doStep == 4) {
                            // compile boat variants and create a message number mapping
                            $efaImport->step4aCompileBoatVariants();
                            $efaImport->step4bMapDamageNumbers();
                        } elseif ($doStep == 5) {
                            // now check for efaCloudConfig
                            if ($includeEfaCloud)
                                $efaImport->step5ImportEfaCloudConfig($_SESSION["efaImport"]["projectName"],
                                    $efaCloudPassword);
                            // block completed
                            $efaImport->addProgress("<br>");
                        }
                        $doneStep = $doStep;
                    } else
                        // skip block
                        $doStep = $uploadSteps + $configFileSteps + 1;
                }
                // === Import efa data steps, can control in fractions.
                if (($doStep > ($uploadSteps + $configFileSteps)) &&
                    ($doStep <= ($uploadSteps + $configFileSteps + $dataFileSteps))) {
                    if (strcasecmp($validatedEntries["import_data"], "on") == 0) {
                        // execute step, or step fraction. start with $file_index = 0.
                        $fileIndex = $doStep - ($uploadSteps + $configFileSteps) - 1; // starts with 0
                        $remainder = $efaImport->step6ImportEfaData($fileIndex, $chunk, $from);
                        $doneStep = $doStep;
                        if ($doneStep == ($uploadSteps + $configFileSteps + $dataFileSteps)) {
                            // block completed, if not in update
                            if ($resetDb)
                                $efaImport->addProgress("<br>");
                        }
                    } else
                        // skip block
                        $doStep = $uploadSteps + $configFileSteps + $dataFileSteps + 1;
                }
                // === Import efaCloud data steps, can control in fractions. start with $file_index = 0.
                if (($doStep > ($uploadSteps + $configFileSteps + $dataFileSteps)) && ($doStep <=
                        ($uploadSteps + $configFileSteps + $dataFileSteps + $efaCloudDataSteps))) {
                    if ((strcasecmp($validatedEntries["import_data"], "on") == 0) && $includeEfaCloud) {
                        // execute step, or step fraction
                        $fileIndex = $doStep - ($uploadSteps + $configFileSteps + $dataFileSteps) - 1;
                        $remainder = $efaImport->step7ImportEfaCloudData($fileIndex, $chunk, $from,
                            $_SESSION["efaImport"]["projectName"], $efaCloudPassword);
                        $doneStep = $doStep;
                        if ($doneStep ==
                            ($uploadSteps + $configFileSteps + $dataFileSteps + $efaCloudDataSteps))
                            // block completed
                            $efaImport->addProgress("<br>");
                    } else
                        // skip block
                        $doStep = $uploadSteps + $configFileSteps + $dataFileSteps + $efaCloudDataSteps +
                            1;
                }
                // === Build the word index.
                if (($doStep > ($uploadSteps + $configFileSteps + $dataFileSteps)) && ($doStep <= ($uploadSteps +
                            $configFileSteps + $dataFileSteps + $efaCloudDataSteps + $wordIndexSteps))) {
                    if ((strcasecmp($validatedEntries["import_data"], "on") == 0)) {
                        // execute step, or step fraction
                        $wordIndex = new WordIndex();
                        $wordIndex->rebuild();
                        $doneStep = $doStep;
                        // block completed
                        $efaImport->addProgress($i18n->t("8dPpRp|Word index built.") . "<br>");
                    } else
                        // skip block
                        $doStep = $uploadSteps + $configFileSteps + $dataFileSteps + $efaCloudDataSteps +
                            $wordIndexSteps + 1;
                }
                // respond after step execution with the step done
                if ($doneStep > 0) {
                    // add the progress text if a step was executed
                    echo "$doneStep;$remainder;" . $efaImport->getProgress();
                } else {
                    // return the idle keyword if no further step was to be done
                    echo "0;0;idle";
                }
                $runner->endScript(false);
            } else
                $todo = $runner->done + 1;
        }
    }

} else {
    // done == 0, clear session parameters for a fresh start.
    unset($_SESSION["efaImport"]);
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
echo "<h3>" . $i18n->t("FVkWAh|Import an efa backup arc...") . "</h3>";
echo "<p>" . $i18n->t("RBI9tL|This form is import an e...") . "</p>";

if ($todo == 1) { // step 1. No texts for output
    echo Form::formErrorsToHtml($formErrors);
    echo $formToFill->getHtml(true); // enable file upload
} elseif ($todo == 2) { // step 2. Files were uploaded, started import
    echo Form::formErrorsToHtml($formErrors);
    if (strlen($formErrors) == 0) // trigger the JavaScript progress management by providing the 'progressUrl'
        echo "<span class='tfyhProgressUrl' id='../../dilbo/forms/importEfaBackup.php?f_seq=" . $runner->fsId . "2" . "'>Loading ...</span>";
} elseif ($todo == 3) { // step 3. fractional import of data, output at last step
    echo "<p>" . $i18n->t("HBcQsm|Your backup has been imp...") . "</p>";
    if (isset($efaImport))
        echo $efaImport->getProgress(true);
}

// Help texts and page footer for output.
echo "</div>";
$runner->endScript();
