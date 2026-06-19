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

include_once "DilboConfig.php";
include_once "EfaCloudApi.php";
include_once "DilboRecordHandler.php";

use tfyh\control\Sessions;
use tfyh\data\Codec;
use tfyh\data\Config;
use tfyh\data\DatabaseConnector;
use tfyh\data\Findings;
use tfyh\data\Formatter;
use tfyh\data\Ids;
use tfyh\data\Item;
use tfyh\data\Parser;
use tfyh\data\ParserConstraints;
use tfyh\data\ParserName;
use tfyh\data\PropertyName;
use tfyh\data\Record;
use tfyh\data\Type;
use tfyh\data\Xml;
use tfyh\util\FileHandler;
use tfyh\util\I18n;
use tfyh\util\Language;

/**
 * class file for the specific handling of efa backup import and export.
 */
class EfaImport
{

    /**
     * The tenant meta-data: admins.efa2admins, configuration.efa2config, types.efa2types.
     */
    private static array $efaConfigFiles = ["efa2admins", "efa2config", "efa2types"
    ];

    /**
     * The efa named data files.
     */
    private static array $efaNamedConfigFiles = ["efa2project"
    ];

    /**
     * The efa unique data files, which will be the sequence of import. BOATS MUST BE BEFORE BOATSTATUS.
     */
    private static array $efaDataFiles = ["efa2autoincrement", "efa2boatdamages", "efa2boatreservations",
        "efa2boats", "efa2boatstatus", "efa2crews", "efa2destinations", "efa2fahrtenabzeichen", "efa2groups",
        "efa2messages", "efa2persons", "efa2sessiongroups", "efa2statistics", "efa2status", "efa2waters"
    ];

    /**
     * The backup files, which shall be skipped.
     */
    private static array $efaSkippedFiles = ["meta"
    ];

    /**
     * The efaCloud data files, which will be the sequence of import.
     * EFA2PERSONS above MUST BE IMPORTED BEFORE EFACLOUDUSERS.
     */
    private static array $efacloudDataFiles = ["efaCloudUsers"
    ];

    /**
     * The root nod for the efa imported configuration
     */
    private Item $efaImportRoot;

    private array $efaValues = [];

    /**
     * The directory into which the uploaded zip file is unzipped.
     */
    private string $progressFile;

    /**
     * The directory into which the uploaded zip file is unzipped.
     */
    private string $logFile;

    /**
     * public Constructor.
     */
    public function __construct()
    {
        $this->progressFile = "../../var/Run/executionProgress." . Sessions::getInstance()->sessionId();
        $this->logFile = "../../var/Log/efa_import.log";
    }

    /**
     * Add a statement to the progress file.
     * @param string $progressStatement the statement to be added to the progress file.
     * @param bool $append if true, the statement will be appended to the existing file. If false, the statement will
     * be written to a new file.
     * @return void
     */
    public function addProgress(string $progressStatement, bool $append = true): void
    {
        file_put_contents($this->progressFile, $progressStatement, (($append) ? FILE_APPEND : null));
        file_put_contents($this->logFile,
            date("Y-m-d H:i:s") . ": progress statement " . $progressStatement . "\n", FILE_APPEND);
        usleep(10000);
    }

    /**
     * Clear the progress file and get its contents.
     * @param bool $clear if true, the file will be deleted after reading.
     * @return string the contents of the progress file.
     */
    public function getProgress(bool $clear = false): string
    {
        $progress_contents = file_get_contents($this->progressFile);
        if ($clear)
            unlink($this->progressFile);
        return ($progress_contents === false) ? "" : $progress_contents;
    }

    /**
     * Add a child to the config tree. If the child exists, the existing one will be returned.
     * @param Item $parent the parent of the child to be added.
     * @param string $name the name of the child to be added.
     * @param string $label the label of the child to be added.
     * @param string $valueType the value type of the child to be added.
     * @param mixed|null $value the value of the child to be added.
     * @return Item the child that was added.
     */
    public function addChild(Item   $parent, string $name, string $label,
                             string $valueType, mixed $value = null): Item {
        if ($parent->hasChild($name))
            return $parent->getChild($name);
        $definition = [ "_name" => $name, "default_label" => $label, "value_type" => $valueType ];
        if (! is_null($value)) {
            if ($valueType == "template")
                $definition["value_reference"] = $value;
            else
                $definition["default_value"] = $value;
        }
        $parent->putChild($definition, false);
        // added structure is never part of the Config/packaged file set but added to the appropriate Config/added file.
        return $parent->getChild($name);
    }

    /**
     * Read the value and label of the efa configuration into the dilbo target items actual value and label.
     * @param Item $source the source item containing the efa configuration.
     * @param Item $target the target item into which the efa configuration is to be copied.
     * @return void
     */
    public function copyEfaConfiguration(Item $source, Item $target): void {
        $target->parseProperty("actual_Label", $source->label(), Language::CSV);
        $target->parseProperty("actual_value", $source->valueCsv(), Language::CSV);
    }

    /**
     * Read the value and label of the dilbo configuration into the efa target items actual value and label.
     * @param Item $source the source item containing the dilbo configuration.
     * @param Item $target the target item into which the dilbo configuration is to be copied.
     * @return void
     */
    public function copyConfiguration(Item $source, Item $target): void {
        $target->parseProperty("actual_Label", $source->label(), Language::CSV);
        if (!ParserConstraints::isEmpty($source->value(), $source->type()->parser()))
             $target->parseProperty("actual_value", $source->valueCsv(), Language::CSV);
    }

    /**
     * Unzip the efa-backup archive into the file system and create a list of files to be imported.
     *
     * @param String $zipPath
     * *            path to the eFa-zip archive
     * @param bool $resetDb
     *             if false, only efa2logbook-files are accepted.
     */
    public function step1LoadZip(string $zipPath, bool $resetDb): void
    {
        $i18n = I18n::getInstance();
        // unzip the archive
        $zip_dir_path = mb_substr($zipPath, 0, mb_strrpos($zipPath, "."));
        $file_list = FileHandler::unzip($zipPath, true);
        $this->addProgress($i18n->t("w96wp3|Expanding %1 files from ...", count($file_list)) . "<br><br>");
        // create the import metadata
        $_SESSION["efaImport"] = [];
        $_SESSION["efaImport"]["zipDirPath"] = $zip_dir_path;
        $_SESSION["efaImport"]["fileList"] = $file_list;
        $_SESSION["efaImport"]["backupFileName"] = mb_substr($zipPath,
            mb_strrpos($zipPath, DIRECTORY_SEPARATOR) + 1);
        // use two different file-lists for configuration and data
        $_SESSION["efaImport"]["fileListConfig"] = [];
        $data_files = []; // will be sorted by extension.
        foreach (self::$efaDataFiles as $efa2extension)
            $data_files[$efa2extension] = [];
        // split the file list
        foreach ($file_list as $filepath) {
            // config files are unsorted
            $fileExtension = mb_substr($filepath, mb_strrpos($filepath, ".") + 1);
            file_put_contents($this->logFile, date("Y-m-d H:i:s") . ": unzipping " . $fileExtension . "\n",
                FILE_APPEND);
            if (in_array($fileExtension, self::$efaConfigFiles) ||
                in_array($fileExtension, self::$efaNamedConfigFiles)) {
                $_SESSION["efaImport"]["fileListConfig"][] = $filepath;
            } elseif (!in_array($fileExtension, self::$efaSkippedFiles)) {
                // data files get an extension-based sorting, as defined in self::$efa_data_files, see above.
                $data_files[$fileExtension][] = $filepath;
            }
        }
        // Convert the data files into a flat list, sort by first extension, then name
        $_SESSION["efaImport"]["fileListData"] = [];
        foreach ($data_files as $efa2extension => $filePaths) {
            sort($filePaths);
            foreach ($filePaths as $filepath)
                // if database was not reset, only logbook files are accepted for update.
                if ($resetDb)
                    $_SESSION["efaImport"]["fileListData"][] = $filepath;
                elseif (strcasecmp($efa2extension, "efa2logbook") == 0)
                    $_SESSION["efaImport"]["fileListData"][] = $filepath;
                else
                    $this->addProgress($i18n->t("TkfnPp|No update for non-logboo...", $efa2extension) . "<br>");
        }

        unlink($zipPath);
        if (!is_array($file_list))
            $this->addProgress($file_list . ".<br>");
        else
            $this->addProgress($i18n->t("qqWydo|Unzipping completed.") . "<br>");
        if ($resetDb)
            $this->addProgress($i18n->t("OiGYLb|Resetting database. Plea...") . "<br>");
        file_put_contents($this->logFile, date("Y-m-d H:i:s") . ": unzipping completed.\n", FILE_APPEND);
    }

    /**
     * Remove all previously imported efa settings. This will not affect the dilbo settings.
     */
    public function step3aClearImport(): void
    {
        // remove and reinitialise the application configuration
        if (isset($this->efaImportRoot)) {
            $this->efaImportRoot->parent()->removeChild($this->efaImportRoot);
            $this->efaImportRoot->destroy();
        }
        $this->efaImportRoot = $this->addChild(Config::getInstance()->getItem(""),
            "efa_import", "efa import configuration", "none");
    }

    /**
     * Import the efa configuration in 7 steps. First, read the XML data into the $this->efaValues array. Then
     * import the data into an efaImport configuration branch for inspection purposes. Finally, map the relevant
     * data into the dilbo configuration tree.
     */
    public function step3bImportEfaConfig(): void
    {
        $i18n = I18n::getInstance();

        // read all unzipped files
        $this->addProgress($i18n->t("aveC01|Parsing configuration fi...") . ": ");
        foreach ($_SESSION["efaImport"]["fileListConfig"] as $filepath)
            $this->step3bSub1ReadConfigTree($filepath);
        $this->addProgress(
            count($_SESSION["efaImport"]["fileListConfig"]) . " " . $i18n->t("5gYbSn|files parsed") . "<br>");

        // import admins, config and types
        $this->step3bSub2ImportAndMapAdmins();
        $this->step3bSub3ImportAndMapConfiguration();
        $this->step3bSub4ImportTypes();
        $this->step3bSub5MapTypes();

        // import project and club settings
        $this->step3bSub6ImportProjects();
        $projectsBranch = $this->efaImportRoot->getChild("projects");
        if (!is_null($projectsBranch)) {
            $projectName = $projectsBranch->getChildren()[0]->name();
            $this->step3bSub7MapProject($projectName);
        }
        // write result
        $this->step3bSub8WriteConfigFiles();
    }

    /**
     * Read the project into the $this->efaValues array. This information will not be written to the database.
     * @param string $filePath path to the efa-config file
     * @return void
     */
    private function step3bSub1ReadConfigTree(string $filePath): void
    {
        if (is_dir($filePath))
            return;
        $fileExtension = mb_substr($filePath, mb_strrpos($filePath, ".") + 1);
        $datatype = mb_substr($fileExtension, 4);
        $fileName = mb_substr($filePath, mb_strrpos($filePath, DIRECTORY_SEPARATOR) + 1);
        $objectName = mb_substr($fileName, 0, mb_strrpos($fileName, "."));

        $xmlFilePath = $_SESSION["efaImport"]["zipDirPath"] . "/" . $filePath;
        $xml = file_get_contents($xmlFilePath);
        $tree = new Xml();
        if (in_array($fileExtension, EfaImport::$efaNamedConfigFiles)) {
            $this->efaValues[$datatype . "s"][$objectName] = $tree->readFile($xml);
        } elseif (in_array($fileExtension, EfaImport::$efaConfigFiles)) {
            $this->efaValues["config"][$datatype] = $tree->readFile($xml);
        }
    }

    /**
     * Parse the efa admins information.
     */
    private function step3bSub2ImportAndMapAdmins(): void
    {
        $i18n = I18n::getInstance();
        $this->addProgress($i18n->t("LKteOp|Importing the admins con...") . ": ");
        if (!$this->step3bSub345SubInvalidEfaConfigBranchWarning())
            return;

        $efaAdminsData = $this->efaValues["config"]["admins"]->getAsArray("data", "record");
        $configBranch = $this->addChild($this->efaImportRoot,"config", $i18n->t("H9tW8m|configuration"), "none");
        $adminsBranch = $this->addChild($configBranch,"admins", $i18n->t("mIJlp8|administrators"), "none");
        $cnt = 0;

        // the flat types structure is mapped to a tree of categories nd types within
        foreach ($efaAdminsData as $adminRecord) {
            // get the category and type and remove the respective information from the record
            $adminName = $adminRecord["Name"];
            $adminsBranchAdmin = $this->addChild($adminsBranch, $adminName, $adminName, "none");
            $adminsBranchAdmin->parseProperty(PropertyName::ACTUAL_LABEL->value, $adminName, Config::getInstance()->language());
            foreach ($adminRecord as $key => $value)
                $this->addChild($adminsBranchAdmin, $key, $value, "string");
            $cnt++;
            file_put_contents($this->logFile, date("Y-m-d H:i:s") . ": imported admin " . $adminName . "\n",
                FILE_APPEND);
        }
        $this->addProgress($cnt . " " . $i18n->t("31PJH2|Admins imported") . "<br>");
    }

    /**
     * Parse the efa configuration information.
     */
    private function step3bSub3ImportAndMapConfiguration(): void
    {
        $i18n = I18n::getInstance();
        $this->addProgress($i18n->t("MVk9OQ|Importing the configurat...") . ": ");
        if (!$this->step3bSub345SubInvalidEfaConfigBranchWarning())
            return;
        $configurationData = $this->efaValues["config"]["config"]->getAsArray("data", "record");
        $configBranch = $this->efaImportRoot->getChild("config");
        $configConfigurationBranch = $this->addChild($configBranch, "configuration",
            $i18n->t("c2S7Zr|configuration"), "none");
        $cnt = 0;
        // the flat types structure is mapped to a tree of categories nd types within
        foreach ($configurationData as $configurationRecord) {
            // get the category and type and remove the respective information from the record
            $rName = $configurationRecord["Name"];
            // add the respective type branch. This will
            $configurationBranchChild = $this->addChild($configConfigurationBranch, $rName, $rName, "none");
            file_put_contents($this->logFile,
                date("Y-m-d H:i:s") . ": added config'" . $configurationBranchChild->getPath() . "'\n",
                FILE_APPEND);
            // add the value, position and LastModified values
            $recordValue = (isset($configurationRecord["Value"])) ? $configurationRecord["Value"] : "[" .
                $i18n->t("uLDW4W|empty") . "]";
            $this->addChild($configurationBranchChild, "Value", $i18n->t("p31A4j|value"),
                "string", $recordValue);
            $cnt++;
        }
        $this->addProgress($cnt . " " . $i18n->t("7BVGfL|configuration values imp...") . "<br>");
    }

    /**
     * Parse the efa types information.
     */
    private function step3bSub4ImportTypes(): void
    {
        $i18n = I18n::getInstance();
        $this->addProgress($i18n->t("O19Vr8|Importing the types conf...") . ": ");
        if (!$this->step3bSub345SubInvalidEfaConfigBranchWarning())
            return;
        $efaTypesData = $this->efaValues["config"]["types"]->getAsArray("data", "record");
        $typesBranch = $this->addChild($this->efaImportRoot->getChild("config"), "types",
            $i18n->t("BZTGSR|types"), "none");
        $cnt = 0;
        // the flat types structure is mapped to a tree of categories nd types within
        foreach ($efaTypesData as $typeRecord) {
            // get the category and type and remove the respective information from the record
            $rCategory = $typeRecord["Category"];
            unset($typeRecord["Category"]);
            $rType = $typeRecord["Type"];
            $rValue = $typeRecord["Value"];
            unset($typeRecord["Type"]);
            // add the respective category branch, if missing.
            $typesCategory = (!$typesBranch->hasChild($rCategory))
                ? $this->addChild($typesBranch, $rCategory, $rCategory, "none")
                : $typesBranch->getChild($rCategory);
            // add the respective type branch, if missing.
            if (!$typesCategory->hasChild($rType))
                $this->addChild($typesCategory, $rType, $rValue, "none");
            // ignore the position and LastModified values
            file_put_contents($this->logFile,
                date("Y-m-d H:i:s") . ": added type '" . $typesCategory->getPath() . "." . $rType . "'\n",
                FILE_APPEND);
            $cnt++;
        }
        $this->addProgress($cnt . " " . $i18n->t("44XRtA|type values imported") . "<br>");
    }

    /**
     * copy all imported types into the dilbo catalogues.
     */
    private function step3bSub5MapTypes(): void
    {
        $typeLists = ["BOAT" => "asset_subtype", "COXING" => "boat_coxing",
            "GENDER" => "person_gender", "NUMSEATS" => "boat_seating", "RIGGING" => "boat_rigging",
            "SESSION" => "session_types", "STATUS" => "person_status"
        ];
        $efaConfigRoot = $this->efaImportRoot->getChild("config");
        $efaTypesRoot = $efaConfigRoot->getChild("types");
        $config = Config::getInstance();

        foreach ($typeLists as $efaTypeList => $dilboTypeList) {
            $efaListRoot = $efaTypesRoot->getChild($efaTypeList);
            if (is_null($efaListRoot)) // no types file provided.
                return;
            $dilboListRoot = $config->getItem(".catalogs.$dilboTypeList");
            foreach ($efaListRoot->getChildren() as $item) {
                $name = $item->name();
                if (!$dilboListRoot->hasChild($name))
                    $dilboChild = $this->addChild($dilboListRoot, $item->name(), $item->label(), "none");
                else {
                    $dilboChild = $dilboListRoot->getChild($name);
                    $this->copyConfiguration($item, $dilboChild);
                }
                file_put_contents($this->logFile,
                    date("Y-m-d H:i:s") . ": copied type to '" . $dilboChild->getPath() . "'\n",
                    FILE_APPEND);
            }
        }
    }

    /**
     * @return bool true if the efa config branch is valid, false otherwise.
     */
    private function step3bSub345SubInvalidEfaConfigBranchWarning(): bool
    {
        $i18n = I18n::getInstance();
        if (!isset($this->efaValues)) {
            $this->addProgress($i18n->t("pZ1nbk|No efa config files prov...") . "<br>");
            return false;
        }
        if (!isset($this->efaValues["config"])) {
            $this->addProgress($i18n->t("cNDfYs|No efa config section pr...") . "<br>");
            return false;
        }
        return true;
    }

    /**
     * Parse the efa projects information.
     */
    private function step3bSub6ImportProjects(): void
    {
        $i18n = I18n::getInstance();
        $this->addProgress($i18n->t("8c3xMn|Importing the projects c..."));
        if (!is_array($this->efaValues["projects"])) {
            $this->addProgress(" - " . $i18n->t("d4yKBL|No projects provided."));
            return;
        }

        $projectsBranch = $this->addChild($this->efaImportRoot, "projects", $i18n->t("M3LyZI|projects"), "none");
        $cnt = 0;
        foreach ($this->efaValues["projects"] as $projectName => $projectXmlBranch) {
            if (!$projectsBranch->hasChild($projectName))
                $projectsBranch->putChild( [ "_name" => $projectName, "value_type" => "none"], false );
                // added structure is never part of the Config/packaged file set but added to the appropriate Config/added file.
            if (! isset($_SESSION["efaImport"]["projectName"]))
                $_SESSION["efaImport"]["projectName"] = $projectName;
            $projectsBranchProject = $projectsBranch->getChild($projectName);
            $projectsBranchProject->parseProperty(PropertyName::ACTUAL_LABEL->value, $projectName, Config::getInstance()->language());
            $this->addProgress(" - " . $projectName . ": ");
            $projectBranchArray = $projectXmlBranch->getAsArray("data");
            foreach ($projectBranchArray as $projectBranchRecord) {
                // read the type and open a branch, if not existing
                $rType = $projectBranchRecord["Type"];
                if (strcasecmp($rType, "Project") == 0) {
                    $projectsBranchProjectMain = $this->addChild($projectsBranchProject, "Properties",
                        $i18n->t("dFEmkC|Properties"), "none");
                    foreach ($projectBranchRecord as $rKey => $rValue) {
                        $projectsBranchProjectMainChild = $this->addChild($projectsBranchProjectMain,
                            $rKey, $rKey, "string", $rValue);
                        file_put_contents($this->logFile,
                            date("Y-m-d H:i:s") . ": added to project main '" .
                            $projectsBranchProjectMainChild->getPath() . "'\n", FILE_APPEND);
                        $cnt++;
                    }
                } else {
                    $rTypeBranchName = $rType . "s";
                    unset($projectBranchRecord["Type"]);
                    $projectsBranchProjectType = $this->addChild($projectsBranchProject, $rTypeBranchName,
                        $rTypeBranchName,"none");
                    file_put_contents($this->logFile,
                        date("Y-m-d H:i:s") . ": added/recalled to project types '" .
                        $projectsBranchProjectType->getPath() . "'\n", FILE_APPEND);
                    // read the name and open a record branch, if not existing
                    if (isset($projectBranchRecord["Name"]))
                        $nameField = "Name";
                    elseif (isset($projectBranchRecord["ClubName"]))
                        $nameField = "ClubName";
                    else $nameField = "Undefined";
                    $rName = Formatter::toIdentifier($projectBranchRecord[$nameField]);
                    unset($projectBranchRecord[$nameField]);
                    $projectsBranchProjectTypeName = $this->addChild($projectsBranchProjectType, $rName, $rName,
                        "none");
                    file_put_contents($this->logFile,
                        date("Y-m-d H:i:s") . ": added/recalled to project records '" .
                        $projectsBranchProjectTypeName->getPath() . "'\n", FILE_APPEND);
                    $cnt++;
                    // add all values to the record branch
                    foreach ($projectBranchRecord as $rKey => $rValue) {
                        $projectsBranchProjectTypeNameKey = $this->addChild($projectsBranchProjectTypeName,
                            $rKey, $rKey, "string", $rValue);
                        file_put_contents($this->logFile,
                            date("Y-m-d H:i:s") . ": added to project records keys '" .
                            $projectsBranchProjectTypeNameKey->getPath() . "'\n",
                            FILE_APPEND);
                        $cnt++;
                    }
                }
            }
            $this->addProgress($cnt . " " . $i18n->t("lTdLce|configuration values imp..."));
        }
        $this->addProgress("<br>");
    }

    /**
     * @param Item $efaBookList the efa book list to read the logbooks from.
     * @param Item $dilboBookList the dilbo book list to add the logbooks to.
     * @return Int the start month of the logbooks.
     */
    private function step3bSub7bMapProjectAddBooks(Item $efaBookList, Item $dilboBookList): Int {
        // read logbooks
        $bookNames = [];
        $efaBooks = $efaBookList->getChildren();
        $startMonth = 1;
        foreach ($efaBooks as $bookItem) {
            $bookNames[] = DilboConfig::normalizeBookName($bookItem->name(), $dilboBookList->name());
            $startDate = Parser::parse($bookItem->getChild("StartDate")->valueStr(), ParserName::DATE, Language::CSV);
            $startMonth = intval($startDate->format("m"));
        }
        foreach ($bookNames as $bookName) {
            $dilboBook = $this->addChild($dilboBookList, $bookName, $bookName, "template",
                ".templates." . mb_substr($dilboBookList->name(), 0, mb_strlen($dilboBookList->name()) - 1));
            file_put_contents($this->logFile,
                date("Y-m-d H:i:s") . ": copied logbook '" . $dilboBook->getPath() . "'\n",
                FILE_APPEND);
        }
        return strval($startMonth);
    }

    /**
     * Copy the project configuration data into the dilbo app configuration
     * @param string $projectName the name of the project to copy the configuration for.
     * @return void
     */
    private function step3bSub7MapProject(string $projectName): void
    {
        $i18n = I18n::getInstance();
        $config = Config::getInstance();
        $language = $config->language();

        $efaProjectsBranch = $this->efaImportRoot->getChild("projects");
        $efaProjectBranch = $efaProjectsBranch->getChild($projectName);
        if(!$efaProjectBranch->hasChild("Clubs"))
            $efaProjectBranch->putChild( [ "_name" => "Clubs", "value_type" => "none"], false );
            // added structure is never part of the Config/packaged file set but added to the appropriate Config/added file.

        $efaClubsBranch = $efaProjectBranch->getChild("Clubs");
        // use the very first club if multiple clubs are listed
        $efaClubBranch = $efaClubsBranch->getChildren()[0];

        // copy club location properties. Assume this is a boat house
        $efaAddressStreet = $this->addChild($efaClubBranch, "AddressStreet", $i18n->t("kLBpHw|address: street"), "string");
        $efaAddressCity = $this->addChild($efaClubBranch, "AddressCity", $i18n->t("nxtlx7|address: city"), "string");
        $efaStorageUser = $efaProjectBranch->getChild("Properties")->getChild("StorageUsername");
        $dilboLocations = Config::getInstance()->getItem(".app.club.locations");
        $firstLocationName = "efa_location_1";
        $dilboLocations->putChild( [ "_name" => $firstLocationName, "label" => "first location in efa",
            "value_type" => "template", "value_reference" => ".templates.location", "type" => "BOATHOUSE",
            "dilbo_user" => $efaStorageUser->valueStr(), "street" => $efaAddressStreet->valueStr(),
            "city" => $efaAddressCity->valueStr()  ], false );
        $newLocation = $dilboLocations->getChild($firstLocationName);
        file_put_contents($this->logFile,
            date("Y-m-d H:i:s") . ": copied location '" . $newLocation->getPath() . "'\n", FILE_APPEND);

        // app club and its location and associations
        $config->getItem(".app.club.clubname")->parseProperty(PropertyName::ACTUAL_VALUE->value, $efaClubBranch->name(), $language);
        $config->getItem(".app.club.address")->parseProperty(PropertyName::ACTUAL_VALUE->value, $firstLocationName, $language);
        $dilboAssociations = $config->getItem(".app.club.associations");
        $associations = ["GlobalAssociationName" => "global", "RegionalAssociationName" => "regional",
            "MemberOfDRV" => "member_of_drv"
        ];
        foreach ($associations as $efaValue => $dilboValue) {
            $efaAssociation = $efaClubBranch->getChild($efaValue);
            $dilboAssociation = $dilboAssociations->getChild($dilboValue);
            file_put_contents($this->logFile,
                date("Y-m-d H:i:s") . ": copied association '" . $dilboAssociation->getPath() . "'\n",
                FILE_APPEND);
            $dilboAssociation->parseProperty(PropertyName::ACTUAL_VALUE->value, $efaAssociation->valueStr(), $language);
        }

        // read logbooks
        $efaLogbooksBranch = $efaProjectBranch->getChild("Logbooks");
        $dilboSportsYearStart = $this->step3bSub7bMapProjectAddBooks(
            $efaLogbooksBranch, $config->getItem(".app.club.logbooks"));
        $config->getItem(".app.club.habits.sports_year_start")->parseProperty(
            PropertyName::DEFAULT_VALUE->value, $dilboSportsYearStart, Language::CSV);
        // use the starting month as sports year start value and read club workbooks
        $efaWorkbooksBranch = $efaProjectBranch->getChild("ClubworkBooks");
        if (! is_null($efaWorkbooksBranch))
            $this->step3bSub7bMapProjectAddBooks($efaWorkbooksBranch, $config->getItem(".app.club.workbooks"));
    }

    /**
     * Execute an api SELECT command to efacloud. This will use the configured efaCloud settings but require
     * the password to be manually entered.
     * @param string $projectName the name of the project to select from efacloud.
     * @param string $efaCloudPassword the password to use for the efacloud connection.
     * @param string $efaCloudTableName the name of the table to select from efacloud.
     * @param array $conditionsRecord the conditions to use for the efacloud SELECT command.
     * @return false|mixed the result of the efacloud SELECT command.
     */
    private function step7subApiSelectFromEfacloud(string $projectName, string $efaCloudPassword,
                                                   string $efaCloudTableName, array $conditionsRecord): mixed
    {
        $i18n = I18n::getInstance();
        $config = Config::getInstance();
        $storageType = $config->getItem(
            ".efa_import.projects." . $projectName . ".Properties.StorageType")->valueStr();
        if (strcasecmp($storageType, "file/efaCloud") != 0) {
            $this->addProgress($i18n->t("8QpdLl|No efacloud project") . "<br>");
            return false;
        } else if (strlen($efaCloudPassword) >= 8) {
            $efaCloudUrl = $config->getItem(
                ".efa_import.projects." . $projectName . ".Properties.EfaCloudURL")->valueStr();
            if (mb_substr($efaCloudUrl, mb_strlen($efaCloudUrl) - 1) != "/")
                $efaCloudUrl .= "/";
            $efaCloudUser = $config->getItem(
                ".efa_import.projects." . $projectName . ".Properties.StorageUsername")->valueStr();
            $efaCloudApi = new EfaCloudApi($efaCloudUrl, $efaCloudUser, $efaCloudPassword);
            $txIdAppend = $efaCloudApi->appendTransaction("select", $efaCloudTableName, $conditionsRecord);
            $efaCloudApi->sendContainer();
            $result = $efaCloudApi->getResult($txIdAppend);
            if (intval($result[0]) >= 400) {
                $this->addProgress($i18n->t("JwyWs2|efaCloud server connnect...", $result[0],
                        $result[1]) . "<br>");
                return false;
            }
            return $result[1];
        } else {
            $this->addProgress($i18n->t("5gZmqR|No efacloud credentials ...") . "<br>");
            return false;
        }
    }

    /**
     * Import all data configuration data from the respective efaCloud server.
     * @param string $projectName the name of the project to import the data for.
     * @param string $efaCloudPassword the password to use for the efacloud connection.
     * @return void
     */
    public function step5ImportEfaCloudConfig(string $projectName, string $efaCloudPassword): void
    {
        $i18n = I18n::getInstance();
        $efaImportConfigCsv = file_get_contents("../../Config/efa_import");
        $efaImportConfig = Codec::csvToMap($efaImportConfigCsv);
        Config::getInstance()->getItem("")->readBranch($efaImportConfig, false);
        $this->efaImportRoot = Config::getInstance()->getItem(".efa_import");
        $this->addProgress($i18n->t("L4Mkdc|Importing the efaCloud c...") . ": ");
        $cnt = 0;
        $efaCloudCfgAppBase64 = $this->step7subApiSelectFromEfacloud($projectName, $efaCloudPassword,
            "efaCloudConfig", [""
            ]);
        $efaCloudCfgApp = unserialize(base64_decode($efaCloudCfgAppBase64));
        if ($efaCloudCfgApp !== false) {
            DilboConfig::mapEfaCloudConfig($efaCloudCfgApp);
            $efaCloudConfig = $this->addChild($this->efaImportRoot, "efaCloudConfig",
                "efaCloud import", "none");
            file_put_contents($this->logFile,
                date("Y-m-d H:i:s") . ": read efaCloud configuration '" . $efaCloudConfig->getPath() .
                "'\n", FILE_APPEND);
            foreach ($efaCloudCfgApp as $name => $value)
                if (!is_array($value)) {
                    $cnt++;
                    $this->addChild($efaCloudConfig, $name, $name, "string", $value);
                }
            // copy into dilbo tree
            $this->step5subCopyEfaCloudConfig();
            // write result
            $this->step3bSub8WriteConfigFiles();
            $this->addProgress($cnt . " " . $i18n->t("UETSE9|configuration values imp...") . "<br>");
        }
    }


    /**
     * Copy all efcloud configuration values to the app configuration
     */
    private function step5subCopyEfaCloudConfig(): void
    {
        $config = Config::getInstance();
        if (!$this->efaImportRoot->hasChild("efaCloudConfig"))
            return;
        $efaCloudItem = $this->efaImportRoot->getChild("efaCloudConfig");
        foreach ($efaCloudItem->getChildren() as $item) {
            $from = $item->name();
            if (isset(DilboConfig::$efaCloudToDilboConfig[$from])) {
                $to = DilboConfig::$efaCloudToDilboConfig[$from];
                $dilboMappedConfig = $config->getItem($to);
                if ($dilboMappedConfig->isValid()) {
                    $dilboMappedConfig->parseProperty(PropertyName::ACTUAL_VALUE->value, $item->valueStr(), $config->language());
                    $dilboMappedConfig->parseProperty(PropertyName::ACTUAL_LABEL->value, $item->label(), $config->language());
                    file_put_contents($this->logFile,
                        date("Y-m-d H:i:s") . ": copied efaCloud configuration '" .
                        $dilboMappedConfig->getPath() . "'\n", FILE_APPEND);
                }
            }
        }
    }

    /**
     * Parse the efa2boats data tables and compile the different boat variants detected.
     */
    public function step4aCompileBoatVariants(): void
    {
        $config = Config::getInstance();
        $i18n = I18n::getInstance();
        // get the boat data file.
        $errorMessage = $i18n->t("geDnMi|Failed to load efa2boats...");
        if (!isset($_SESSION["efaImport"]["fileListData"])) {
            $this->addProgress($errorMessage . "<br>");
            return;
        }
        $boatsFilePath = false;
        foreach ($_SESSION["efaImport"]["fileListData"] as $tableFile)
            if (mb_strpos($tableFile, "boats.efa2boats") !== false)
                $boatsFilePath = $tableFile;
        if (!$boatsFilePath) {
            $this->addProgress($errorMessage . "<br>");
            return;
        }
        $xmlFilePath = $_SESSION["efaImport"]["zipDirPath"] . "/" . $boatsFilePath;
        $xml = file_get_contents($xmlFilePath);
        if (!$xml) {
            $this->addProgress($errorMessage . "<br>");
            return;
        }
        // this will always read the complete file for each chunk, but step-by-step can only store data between
        // steps in the $_SESSSION var, and this is neither very effective.
        $xmlParser = new Xml();
        $boatsFileBranchXml = $xmlParser->readFile($xml);
        $tableBranchArray = $boatsFileBranchXml->getAsArray("data");
        $cnt = count($tableBranchArray);
        $this->addProgress($i18n->t("2trQTF|Extracting boat variant ...", $cnt) . "<br>");
        // $typeFields = ["TypeType" => "built", "TypeSeats" => "seating", "TypeRigging" => "rigging", "TypeCoxing" => "coxing"];
        $boatVariantsBranch = $config->getItem(".app.boat_variants");
        $boatSubtypesBranch = $config->getItem(".catalogs.asset_subtype");
        foreach ($tableBranchArray as $tableBranchRecord) {
            file_put_contents($this->logFile,
                date("Y-m-d H:i:s") . ": compiling boat variants for '" .
                $tableBranchRecord["Name"] . "'\n", FILE_APPEND);
            DilboConfig::compileBoatVariants($tableBranchRecord, $boatVariantsBranch);
            DilboConfig::compileBoatSubtypes($tableBranchRecord, $boatSubtypesBranch);
        }
        $boatVariantsBranch->sortChildrenByName();

        // write result
        $appItem = $config->getItem(".app");
        file_put_contents("../../Config/added/app",
            $appItem->branchToCsv(99, false));
    }

    /**
     * Boat damages may have duplicate numbers. This needs resolution. All damages will be renumbered for this
     * reason. This function creates the number mapping.
     */
    public function step4bMapDamageNumbers(): void
    {
        $i18n = I18n::getInstance();
        if (!isset($_SESSION["efaImport"]["fileListData"]))
            return;
        $damagesFilePath = false;
        foreach ($_SESSION["efaImport"]["fileListData"] as $filepath) {
            $filename = mb_substr($filepath, mb_strrpos($filepath, DIRECTORY_SEPARATOR) + 1);
            $efaTableName = mb_substr($filename, mb_strrpos($filename, ".") + 1);
            if (strcasecmp($efaTableName, "efa2boatdamages") == 0)
                $damagesFilePath = $filepath;
        }
        if (!$damagesFilePath)
            return;
        $xmlFilePath = $_SESSION["efaImport"]["zipDirPath"] . "/" . $damagesFilePath;
        $xml = file_get_contents($xmlFilePath);
        if ($xml === false)
            return;
        $xmlParser = new Xml();
        $damagesBranchXml = $xmlParser->readFile($xml);
        $tableBranchArray = $damagesBranchXml->getAsArray("data");
        $cnt = count($tableBranchArray);
        $mappingOfDamageNumbers = [];
        for ($i = 0; $i < $cnt; $i++)
            $mappingOfDamageNumbers[$tableBranchArray[$i]["Damage"] . "." .
            $tableBranchArray[$i]["BoatId"]] = $i + 100;
        $this->addProgress($i18n->t("T1s1x8|Mapped %1 damage numbers...", $cnt) . "<br>");
        $_SESSION["efaImport"]["map_damage_numbers"] = $mappingOfDamageNumbers;
    }

    /**
     * Read a chunk of a full table of records.
     * @param int $chunk the size of the chunk to read.
     * @param int $from the start index of the chunk to read.
     * @param string $filename the filename of the table to read.
     * @param string $efaTableName the name of the table to read.
     * @param string $bookName the name of the book to read.
     * @return int the next from index to read.
     */
    private function step6and7importRecordsChunk(int    $chunk, int $from, string $filename,
                                                 string $efaTableName, string $bookName) : int
    {
        $config = Config::getInstance();
        $i18n = I18n::getInstance();
        $cnt = count($_SESSION["efaImport"]["data_records"]);
        if (($chunk == 0) || ($from == 0))
            $this->addProgress($i18n->t("elFKdn|Importing %1. %2 records...", $filename, $cnt) . " ");

        // read and validate efa2 records and store it in the database.
        $to = ($chunk == 0) ? $cnt : min($from + $chunk, $cnt);
        $dilboTableName = DilboConfig::$efaEfaCloudToDilboMapping[$efaTableName]["."] ?? false;
        if ((strlen($dilboTableName) < 2) || ($dilboTableName === false)) {
            $this->addProgress($i18n->t("ziigIX|no import required.", $efaTableName) . " ");
            $remainder = 0;
        } else {

            $dilboRecordItemPath = ".tables." . $dilboTableName;
            $dilboRecordItem = $config->getItem($dilboRecordItemPath);
            // collect uniques. NB: this is the pre-modification check. The database will enforce the uniqueness.
            $uniques = [];
            foreach ($dilboRecordItem->getChildren() as $child)
                if (str_contains($child->sqlIndexed(), "u")  // all uniques
                    && !str_contains($child->sqlIndexed(), "a") // without the auto-incrementing
                    && ($child->name() != "uid"))  // and without the auto-set
                    $uniques[] = $child->name();
            $errPrefix = "<br> --! ";
            $warnPrefix = "<br> -- ";
            for ($i = $from; $i < $to; $i++) {
                // reformat the record
                $efaRecordCleansed = [];
                Findings::clearFindings();
                foreach ($_SESSION["efaImport"]["data_records"][$i] as $efaColumn => $value) {
                    $policy = DilboConfig::$efaEfaCloudToDilboMapping[$efaTableName][$efaColumn];
                    $type = Type::get($policy["value_type"]);
                    // use DE as default language for efa.
                    $parsed = Parser::parse($value, $type->parser(), Language::DE);
                    $efaRecordCleansed[$efaColumn] = Formatter::format($parsed, $type->parser(), Language::CSV);
                    if ($policy["rule efa2dilbo"] == "copy_distance") {
                        // keep unit for distance
                        $parsed = Parser::parse(explode(" ", $value)[0], $type->parser(), Language::DE);
                        $efaRecordCleansed[$efaColumn] = Formatter::format($parsed, $type->parser(), Language::CSV) .
                            ((str_contains($value, " ")) ? " " . explode(" ", $value)[1] : "");
                    }
                }
                if (Findings::countErrors() > 0)
                    $this->addProgress($errPrefix . Findings::getFindings(false));
                // Map the record to app format
                DilboConfig::clearFindings();
                $dilboRow = DilboConfig::mapEfaRecordToDilbo($efaTableName, $bookName,
                    $efaRecordCleansed);
                foreach (DilboConfig::getErrors() as $error)
                    $this->addProgress($errPrefix . $error);
                foreach (DilboConfig::getWarnings() as $warning)
                    $this->addProgress($warnPrefix . $warning);
                // on failures which prohibit insertion or update, $dilbo_record_str will be empty.

                // add the record to the database
                if ($dilboRow !== false) {

                    // normal mode is insert, but ...
                    $mode = 1;
                    // ... check for existence in logbook update mode
                    $dbc = DatabaseConnector::getInstance();
                    if (strcasecmp($dilboTableName, "logbook") == 0) {
                        $matching = ["number" => $dilboRow["number"], "logbookname" => $dilboRow["logbookname"],
                            "start" => $dilboRow["start"] ];
                        $alreadyThere = $dbc->findAll($dilboTableName, $matching, 3);
                        if ($alreadyThere !== false) {
                            if (count($alreadyThere) > 1) {
                                // There must not be multiple trips in the same logbook at the same date with
                                // the same number. Skip record.
                                $this->addProgress(
                                    $i18n->t("3fFURY|Multiple trips for logbo...",
                                        $dilboRow["logbookname"], $dilboRow["number"], $dilboRow["start"]) . " ");
                                $dilboRow = false;
                            } else {
                                $dilboRow["uid"] = $alreadyThere[0]["uid"];
                                $mode = 2;
                            }
                        }
                    }
                    // for the base status update of an asset record, the mode shall also be "update".
                    if ($efaTableName == "efa2boatstatus")
                        $mode = 2;
                    if (!isset($dilboRow["uid"]))
                        $dilboRow["uid"] = Ids::generateUid(6);
                    // check uniques
                    foreach ($uniques as $unique) {
                        $value = $dilboRow[$unique] ?: "(empty)";
                        if (!isset($_SESSION["efaImport"][$dilboTableName]["uniques"]))
                            $_SESSION["efaImport"][$dilboTableName]["uniques"] = [];
                        if (!isset($_SESSION["efaImport"][$dilboTableName]["uniques"][$unique]))
                            $_SESSION["efaImport"][$dilboTableName]["uniques"][$unique] = [];
                        if (!isset($_SESSION["efaImport"][$dilboTableName]["uniques"][$unique][$value]))
                            $_SESSION["efaImport"][$dilboTableName]["uniques"][$unique][$value] = 1;
                        else {
                            $occurrence = $_SESSION["efaImport"][$dilboTableName]["uniques"][$unique][$value] + 1;
                            $this->addProgress($warnPrefix . $i18n->t(
                                    "dKvaHQ|Repeated occurrence (%1 ...",
                                    $occurrence, $value, $unique, $dilboTableName) . " ");
                            $_SESSION["efaImport"][$dilboTableName]["uniques"][$unique][$value] = $occurrence;
                            $dilboRow = false;
                        }
                    }

                    if ($dilboRow !== false) {
                        Findings::clearFindings();
                        $dilboRecord = new Record($dilboRecordItem);
                        $modifyResult = $dilboRecord->modify($dilboRow, $mode, Language::CSV);
                        if (str_starts_with($modifyResult, "!"))
                            $this->addProgress($warnPrefix . $modifyResult);
                        foreach (Findings::getErrors() as $error)
                            $this->addProgress($errPrefix . $error);
                    }
                }
            }
            $remainder = ($cnt - $to);
        }

        $progressChar = (($remainder == 0) || ($to < $chunk)) ? "&#x2713;" : ((($to % (10 * $chunk)) < $chunk) ? "!" : ((($to %
                (5 * $chunk)) < $chunk) ? ":" : ".")); // "&#x2713;" = '✓'
        $this->addProgress($progressChar . (($remainder == 0) ? "<br>" : ""));
        return $remainder;
    }

    /**
     * Parse the efa data tables and map it onto the app data tables. Store the data tables.
     * @param int $fileIndex the index of the file to parse.
     * @param int $chunk the size of the chunk to read.
     * @param int $from the start index of the chunk to read.
     * @return int|string the next from index to read or an empty string, if nothing is to be done.
     */
    public function step6ImportEfaData(int $fileIndex, int $chunk, int $from) : string|int
    {
        $i18n = I18n::getInstance();
        if (!isset($_SESSION["efaImport"]["fileListData"][$fileIndex]))
            return "";
        $filePath = $_SESSION["efaImport"]["fileListData"][$fileIndex];
        if (strlen($filePath) == 0)
            return "";

        DilboConfig::loadMapping();
        $fileName = mb_substr($filePath, mb_strrpos($filePath, DIRECTORY_SEPARATOR) + 1);
        $efaTableName = mb_substr($fileName, mb_strrpos($fileName, ".") + 1);
        $bookName = mb_substr($fileName, 0, mb_strrpos($fileName, "."));

        // this will always read the complete file for ach chunk, but step-by-step can only store data between
        // steps in the $_SESSION var, and this is neither very effective.
        $xmlFilePath = $_SESSION["efaImport"]["zipDirPath"] . "/" . $filePath;
        $xml = file_get_contents($xmlFilePath);
        if ($xml === false) {
            $this->addProgress($i18n->t("hVTaaQ|Failed") . $fileName . ": ");
            return "";
        }
        $xmlParser = new Xml();
        $xmlTreeRoot = $xmlParser->readFile($xml);

        // use records cache
        if (!isset($_SESSION["efaImport"]["data_records"]))
            $_SESSION["efaImport"]["data_records"] = $xmlTreeRoot->getAsArray("data");
        // import chunk
        $remainder = $this->step6and7importRecordsChunk($chunk, $from, $fileName, $efaTableName,
            $bookName);
        // delete cache after completion
        if ($remainder == 0)
            unset($_SESSION["efaImport"]["data_records"]);
        return $remainder;
    }

    /**
     * Parse the efa data tables and map it onto the app data tables. Store the data tables.
     * @param int $fileIndex the index of the file to parse.
     * @param int $chunk the size of the chunk to read.
     * @param int $from the start index of the chunk to read.
     * @param string $projectName the name of the project to import.
     * @param string $efaCloudPassword the password to access the efaCloud data.
     * @return int the next from index to read.
     */
    public function step7ImportEfaCloudData(int    $fileIndex, int $chunk, int $from,
                                            string $projectName, string $efaCloudPassword): int
    {
        $i18n = I18n::getInstance();
        // data will be stored in the same directory as the efa-files.
        $dirPath = $_SESSION["efaImport"]["zipDirPath"];

        $efaCloudTableName = self::$efacloudDataFiles[$fileIndex];
        if (!file_exists($dirPath . "/efacloud"))
            mkdir($dirPath . "/efacloud");
        $efaCloudDataFilePath = $dirPath . "/efacloud/" . $efaCloudTableName . ".csv";
        $efaCloudDataCsv = "";
        if ($from == 0) {
            // first chunk. get csv data from efacloud server.
            $efaCloudDataCsv = $this->step7subApiSelectFromEfacloud($projectName, $efaCloudPassword,
                $efaCloudTableName, ["LastModified" => "0", "?" => ">"
                ]);
            if ($efaCloudDataCsv !== false)
                file_put_contents($efaCloudDataFilePath, $efaCloudDataCsv);
            else {
                $this->addProgress($i18n->t("GP3RV9|Failed to retrieve data ...", $efaCloudTableName) . " ");
                return 0;
            }
        }

        // use records cache
        if (!isset($_SESSION["efaImport"]["data_records"]))
            $_SESSION["efaImport"]["data_records"] = Codec::csvToMap($efaCloudDataCsv);
        // import chunk
        $remainder = $this->step6and7importRecordsChunk($chunk, $from, $efaCloudTableName,
            $efaCloudTableName, "");
        // delete cache after completion
        if ($remainder == 0)
            unset($_SESSION["efaImport"]["data_records"]);
        return $remainder;
    }

    /**
     * Write the import result into the efa2import configuration file
     */
    private function step3bSub8WriteConfigFiles(): void
    {
        $i18n = I18n::getInstance();
        $this->addProgress($i18n->t("yqXP9E|Writing settings/efa2con...") . "<br>");
        foreach ([ "app", "catalogs", "efa_import"] as $settingsFile) {
            $csv = Config::getInstance()->getItem(".$settingsFile")->branchToCsv(99, false);
            file_put_contents("../../Config/added/$settingsFile", $csv);
        }
    }
}
