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

include_once '../../tfyh/Api/PreModificationCheck.php';
use tfyh\api\PreModificationCheck;

use tfyh\control\LoggerSeverity;
use tfyh\control\Runner;
use tfyh\control\Sessions;
use tfyh\data\Codec;
use tfyh\data\Config;
use tfyh\data\DatabaseConnector;
use tfyh\data\Findings;
use tfyh\data\Formatter;
use tfyh\data\Ids;
use tfyh\data\Parser;
use tfyh\data\ParserName;
use tfyh\data\Record;
use tfyh\util\I18n;
use tfyh\util\Language;
use tfyh\util\ListHandler;

/**
 * Class file for the app data verification and modification. This class adds to the Efa_tables class, which
 * defines table type semantics and contains static checker functions.
 */
class DilboRecordHandler implements PreModificationCheck
{

    /**
     * Column names of those columns that must not be empty. Forms may require more fields to be set.
     */
    public static array $checkNotEmptyFields = ["assets" => ["uuid", "_name", "asset_type"
    ], "badges" => ["personid"
    ], "crews" => ["uuid", "name"
    ], "damages" => ["number", "asset_uuid", "severity"
    ], "destinations" => ["uuid", "name"
    ], "groups" => ["uuid", "name"
    ], "logbook" => ["logbookname", "number", "asset_uuid"
    ], "messages" => ["number", "to", "subject"
    ], "persons" => ["uuid", "first_name", "last_name", "user_id", "role", "workflows", "concessions"
    ], "reservations" => ["number", "asset_uuid", "type"
    ], "status" => ["uuid", "name", "type"
    ], "waters" => ["uuid", "name"
    ], "workbook" => ["number", "worbookname", "uuid", "date", "description"
    ]
    ];

    public static array $numberedTables = ["logbook", "workbook", "damages", "messages", "reservations"
    ];

    /**
     * The list indices for name-to-uuid resolution referencing. Use $this->build_indices to initialise
     * respective associative arrays. For versioned tables only the last valid name will be used, no
     * previous ones. But uniqueness must be given over all, including the invalid ones, to ensure unique
     * selection in form fields.
     */
    private static array $uuid2nameListId = ["assets" => 1, "crews" => 2, "destinations" => 3, "groups" => 4,
        "persons" => 5, "status" => 6, "waters" => 7
    ];

    /**
     * For a bulk operation collect first all names and their ids to speed up uniqueness and references checks
     */
    private array $uuidsForNames = array();

    /**
     * For a bulk operation with versioned data, make sure only the most recent names are used. This is done
     * during index creation.
     */
    private array $invalidFormForNames = array();

    /**
     * Empty Constructor.
     */
    public function __construct() {}

    /* --------------------------------------------------------------------------------------- */
    /* --------------- PRE-MODIFICATION CHECKS AND CORRECTIONS ------------------------------- */
    /* --------------------------------------------------------------------------------------- */

    /**
     * Initialise the arrays for checking in bulk operations.
     * @param string $listName the name of the list to build indices for.
     * @return void
     */
    private function buildIndices(string $listName): void
    {
        if ((isset($this->uuidsForNames[$listName]) && (count($this->uuidsForNames[$listName]) > 0)))
            return;
        // clear index
        $this->uuidsForNames[$listName] = [];
        $uuidNames = new ListHandler("uuid2names", $listName, []);
        $isVersioned = $uuidNames->hasField("invalid_from");
        foreach ($uuidNames->getRows("csv") as $record) {
            $uuid = $record["uuid"];
            // build name index
            if ($listName == self::$uuid2nameListId["persons"]) // Special case persons' name
                $name = $record["first_name"] . " " . $record["last_name"];
            else
                $name = $record["name"]; // includes names_clubwork
            if ($isVersioned === false)
                $this->uuidsForNames[$listName][$name] = $uuid;
            else {
                // in case of duplicate names (in versioned tables), use the most recent one
                $invalidFrom = floatval($record["invalid_from"]);
                if (!isset($this->invalidFormForNames[$listName][$name]) ||
                    ($invalidFrom > $this->invalidFormForNames[$listName][$name])) {
                    $this->uuidsForNames[$listName][$name] = $uuid;
                    $this->invalidFormForNames[$listName][$name] = $invalidFrom;
                }
            }
        }
    }

    /**
     * Assert that name fields are unique. The result will be appended to the
     * Tfyh_validate::$validation_errors or Tfyh_validate::$validation_warnings.
     * @param Record $record the record to check for uniqueness.
     * @param string $tableName the name of the table to check for uniqueness.
     * @return void
     */
    private function checkNameUniqueness(Record $record, string $tableName): void
    {
        $this->buildIndices($tableName);
        $nameToAssert = $record->value("name");
        if (strcmp($tableName, "persons") == 0)
            $nameToAssert = $record->value("firstname") . " " . $record->value("lastname");
        $referencedUuid = $this->uuidsForNames[$tableName][$nameToAssert];
        if (!is_null($referencedUuid) && strcasecmp($referencedUuid, $record->value("uuid")) != 0)
            Findings::addFinding(5, $nameToAssert, $referencedUuid);
    }

    /**
     * Check whether all fields that shall be unique are unique. The row should be formatted to SQL because its
     * values are used to find duplicate records in the database. Usually this is of no relevance because these fields
     * are Strings or integers where language doesn't matter.
     * @param string $tableName the name of the table to check for uniqueness.
     * @param array $rowSql the row to check for uniqueness, formatted as SQL-syntax String.
     * @param string|null $idFieldName the name of the id field, if any.
     * @return void
     */
    public function writeCheckUniqueFields(string $tableName, array $rowSql,
                                              string $idFieldName = null): void
    {
        $i18n = I18n::getInstance();
        $recordItem = Config::getInstance()->getItem(".tables.$tableName");
        if (! $recordItem->isValid()) {
            Findings::addFinding(6, $i18n->t("ynq0Ng|no table configuration a...", $tableName));
            return;
        }
        $findings = "";
        foreach ($rowSql as $column => $value_str) {
            if ($recordItem->hasChild($column)) {
                $sqlIndexed = $recordItem->getChild($column)->sqlIndexed();
                if ((strlen($sqlIndexed) > 0) && (str_contains($sqlIndexed, "u"))) {
                    $alreadyThere = DatabaseConnector::getInstance()->findAll($tableName, [$column => $value_str], 2);
                    if ($alreadyThere !== false) {
                        $finding = $i18n->t("yxn8R3|A record with %1 °%2° al...", $column, $value_str, $tableName) .
                            " ";
                        if (is_null($idFieldName)) // insert case, no overlap allowed
                            $findings .= $i18n->t("AQ6jNh|Cannot insert record.") . " " . $finding;
                        elseif (strcmp($rowSql[$idFieldName], $alreadyThere[0][$idFieldName]) !=
                            0) {
                            // update case and different id
                            $ids = " $idFieldName for both: ";
                            $ids .= $rowSql[$idFieldName] . "/" .
                                $alreadyThere[0][$idFieldName];
                            $findings .= $i18n->t("4vF8NA|Cannot update record.") . " " . $finding . $ids;
                        } elseif (count($alreadyThere) > 1) {
                            // update case and multiple records with this id
                            $findings .= $i18n->t("2A3jgB|Multiple records with %1...", $column, $value_str,
                                    $tableName) . " ";
                        }
                    }
                }
            }
        }
        Findings::addFinding(6, $findings);
    }

    /**
     * Get a single record as csv, two lines - header and one row.
     * @param string $tableName the name of the table to get the record from.
     * @param array $record the record to get as csv.
     * @return string the csv for the record.
     */
    public function getCsv(string $tableName, array $record): string
    {
        $header = "";
        $values = "";
        $tableItem = Config::getInstance()->getItem(".tables." . $tableName);
        foreach ($record as $key => $value) {
            $header .= ";" . $key;
            $type = $tableItem->getChild($key)->type();
            $values .= ";" . Codec::encodeCsvEntry(
                    Formatter::format($value, $type->parser(), Language::CSV));
        }
        return substr($header, 1) . "\n" . substr($values, 1);
    }

    /**
     * Get the next number based on the existing ones.
     * @param string $tableName the name of the table to get the next number for.
     * @param string|null $bookName the name of the book to get the next number for, if it is a book with per
     * year numbering
     * @return int the next number for the table, and 1 if the table is empty.
     */
    private function getNextNumber(string $tableName, string $bookName = null): int
    {
        include_once '../App/DilboConfig.php';
        $sportsYearStart = DilboConfig::getSportsYearStart();
        if (strcmp($tableName, "logbook") == 0)
            $matching = ["logbookname" => $bookName, "start" => $sportsYearStart->format("Y-m-d")
            ];
        elseif (strcmp($tableName, "workbook") == 0)
            $matching = ["workbookname" => $bookName, "date" => $sportsYearStart->format("Y-m-d")
            ];
        else
            $matching = [];
        $maxNumberRecords = DatabaseConnector::getInstance()->findAllSorted($tableName, $matching, 1, "=,>",
            "number", false);
        if ($maxNumberRecords !== false)
            return $maxNumberRecords[0]["number"];
        return 1;
    }

    /**
     * Check whether the necessary fields are all set
     * @param array $row the row to check for empty fields, String values.
     * @param string $tableName the name of the table to check for empty fields.
     * @param int $mode the mode of the check, 1 for insert, 2 for update.
     * @return void
     */
    private function checkNotEmpty(array $row, string $tableName, int $mode): void
    {
        // an uid is in every table.
        $checkNotEmptyFields = array_merge(self::$checkNotEmptyFields[$tableName], ["uid"
        ]);
        // check for insertion of a new or a copy of a record after delimitation that no necessary fields are
        // empty.
        foreach ($checkNotEmptyFields as $notEmptyField) {
            $isMissing = !isset($row[$notEmptyField]);
            $isEmpty = !$isMissing && (strlen($row[$notEmptyField]) == 0);
            if (($mode == 1) && ($isMissing || $isEmpty)) {
                Findings::addFinding(4, $notEmptyField);
            } elseif (($mode == 2) && !$isMissing && $isEmpty)
                Findings::addFinding(4, $notEmptyField);
        }
    }

    /**
     * If validity is given as dates (valid_from_date, invalid_from_date), replace those by milliseconds
     * into valid_from and invalid_from. The row is expected to be formatted according to the default language.
     * @param array $rowLocale the row to replace the dates in, formatted according to the default language.
     * @return void
     */
    private function replaceValidityDates(array &$rowLocale): void
    {
        $replacements = ["valid_from_date" => "valid_from", "invalid_from_date" => "invalid_from"
        ];
        foreach ($replacements as $from => $to) {
            if ((isset($rowLocale[$from])) && (strlen($rowLocale[$from]) > 0)) {
                $dateTime = Parser::parse($rowLocale[$from], ParserName::DATE,
                    Config::getInstance()->language());
                $rowLocale[$to] = $dateTime->format("Uv");
                unset($rowLocale[$from]);
            }
        }
    }

    /**
     * Resolve all names into uuids, if found. For single value fields and uuid/name-lists.
     * @param Record $record the record to resolve the names for.
     * @return void
     */
    private function replaceNamesByUuids(Record $record): void
    {
        $recordItem = $record->item;
        // control through all fields provided
        foreach ($recordItem->getChildren() as $fieldItem) {
            $value = $fieldItem->valueStr();
            $dataType = $fieldItem->type;
            if ((strcmp($dataType, "uuid_or_name") == 0) && (strlen($value) > 0)) {
                // find the reference for the lookup
                $lookupTable = explode(".", $fieldItem->valueReference())[0];
                // build the name index or reuse it.
                $this->buildIndices($lookupTable);
                // resolve all elements. This works for single elements and lists.
                $resolved = [];
                $valueArray = is_array($value) ? $value : [$value];
                foreach ($valueArray as $element) {
                    if (!Ids::isUuid($element)) {
                        $uuid = $this->uuidsForNames[$lookupTable][$element];
                        if (strlen($uuid) == 36)
                            $resolved[] = $uuid;
                        else
                            $resolved[] = $element;
                    }
                }
                // replace field
                $fieldItem->setValueActual((is_array($value)) ?
                    $resolved : $resolved[0]);
            }
        }
    }

    /**
     * Get all reservations for the boat with the $reservationRecord[ "BoatId"] value and check whether any
     * overlap.
     */
    // =============================
    // NOT TESTED; EFACLOUD RAW CODE
    // =============================
    private function hasOverlapReservation(array $reservationRecord)
    {
        $allReservations = DatabaseConnector::getInstance()->findAllSorted("efa2boatreservations",
            ["BoatId" => $reservationRecord["BoatId"]
            ], 1000, "=", "DateFrom", false);
        if ($allReservations === false)
            return false;
        if (strcasecmp($reservationRecord["Type"], "WEEKLY") == 0) {
            $timeFromA = strtotime($reservationRecord["DateTo"]);
            $timeToA = strtotime($reservationRecord["TimeTo"]);
        } else {
            $timeFromA = strtotime($reservationRecord["DateFrom"] . " " . $reservationRecord["TimeFrom"]);
            $timeToA = strtotime($reservationRecord["DateTo"] . " " . $reservationRecord["TimeTo"]);
        }
        $isWeeklyRecord = (strcasecmp($reservationRecord["Type"], "WEEKLY") == 0);
        foreach ($allReservations as $reservationToCheck) {
            $isThisReservation = (strcmp($reservationToCheck["uid"], $reservationRecord["uid"]) == 0);
            $isWeeklyCheck = (strcasecmp($reservationToCheck["Type"], "WEEKLY") == 0);
            // must be different from the one provided, but must be of same type (see efa code for reference
            // of condition
            if (!$isThisReservation) {
                if ($isWeeklyRecord == $isWeeklyCheck) {
                    if ($isWeeklyRecord) {
                        if (strcasecmp($reservationToCheck["DayOfWeek"], $reservationRecord["DayOfWeek"]) ==
                            0) {
                            // compare weekly reservations, use time only
                            $timeFromB = strtotime($reservationToCheck["TimeFrom"]);
                            $timeToB = strtotime($reservationToCheck["TimeTo"]);
                        } else {
                            // different days of the week, invalidate time for further check
                            $timeFromB = -1;
                            $timeToB = -1;
                        }
                    } else {
                        // compare one-time reservations
                        $timeFromB = strtotime(
                            $reservationToCheck["DateFrom"] . " " . $reservationToCheck["TimeFrom"]);
                        $timeToB = strtotime(
                            $reservationToCheck["DateTo"] . " " . $reservationToCheck["TimeTo"]);
                    }
                    $aStartsWithinB = ($timeFromA > $timeFromB) && ($timeFromA < $timeToB);
                    $aEndsWithinB = ($timeToA > $timeFromB) && ($timeToA < $timeToB);
                    $aIncludesB = ($timeFromA <= $timeFromB) && ($timeToA >= $timeToB);
                    if ($aStartsWithinB || $aIncludesB || $aEndsWithinB)
                        return I18n::getInstance()->t("AebG0R|The boat is occupied wit...", $reservationToCheck["Reason"],
                            $reservationToCheck["DateFrom"], $reservationToCheck["TimeFrom"],
                            $reservationToCheck["DateTo"], $reservationToCheck["TimeTo"]);
                }
            }
        }
        return false;
    }

    public function isOk(Record $record, int $mode): bool {

        // replace names by ids
        if (($mode == 1) || ($mode == 2))
            $this->replaceNamesByUuids($record);

        // apply semantic adjustments
        Findings::clearFindings();
        $formatted = $record->format(Language::SQL, false);
        $tableName = $record->item->name();
        // --- not-empty checks
        $this->checkNotEmpty($formatted, $tableName, $mode);
        // --- duplicate name check for tables with names.
        if (array_key_exists($tableName, self::$uuid2nameListId))
            $this->checkNameUniqueness($record, $tableName);
        // --- add fields on insert
        $formatted["modified"] = microtime(true);
        if ($mode == 1) {
            $formatted["created_by"] = Sessions::getInstance()->userId();
            $formatted["created_on"] = $formatted["modified"];
            // enforce the number uniqueness for numbered tables on insert
            if (in_array($tableName, self::$numberedTables)) {
                $bookName = (strcmp($tableName, "logbook") == 0) ?
                    $record->value("logbookname") : $record->value("workbookname"); // names are String, no formatting required
                $number = $this->getNextNumber($tableName, $bookName);
                if (!isset($formatted["number"]) || (intval($formatted["number"]) <= 0))
                    $formatted["number"] = strval($number + 1);
            }
        }
        $record->parse($formatted, Language::SQL);
        $runner = Runner::getInstance();
        if ((Findings::countErrors() > 0)) {
            $runner->logger->log(LoggerSeverity::INFO, "preModificationCheck", json_encode(Findings::getErrors()));
            return false;
        }
        if ($runner->debugOn && (Findings::countWarnings() > 0))
            $runner->logger->log(LoggerSeverity::DEBUG, "preModificationCheck", json_encode(Findings::getErrors()));
        return true;
    }

}
