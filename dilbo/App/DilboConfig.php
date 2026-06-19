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

use DateTimeImmutable;
use Exception;
use DateInterval;

use tfyh\util\I18n;
use tfyh\util\Language;
use tfyh\data\Codec;
use tfyh\data\Config;
use tfyh\data\DatabaseConnector;
use tfyh\data\Formatter;
use tfyh\data\Ids;
use tfyh\data\Item;
use tfyh\data\ParserConstraints;
use tfyh\data\ParserName;
use tfyh\data\PropertyName;

/**
 * Digital Logbook Configuration class mainly for managing mappings and synchronisation
 * between efaCloud configurations and Dilbo application configurations.
 */
class DilboConfig
{

    /**
     * Mapping of all efaCloud configuration parameters into the app configuration tree
     */
    public static array $efaCloudToDilboConfig = [
        "Verein" => ".app.club.clubname",
        "ClubworkAgeDays" => ".app.maintenance.archive.clubwork_age_days",
        "DamageAgeDays" => ".app.maintenance.archive.damage_age_days",
        "MessageAgeDays" => ".app.maintenance.archive.message_age_days",
        "PersonsAgeDays" => ".app.maintenance.archive.persons_age_days",
        "ReservationAgeDays" => ".app.maintenance.archive.reservation_age_days",
        "TripAgeDays" => ".app.maintenance.archive.trip_age_days",
        "mail_footer" => ".app.mailer.mail_footer",
        "mail_subscript" => ".app.mailer.mail_subscript",
        "system_mail_sender" => ".app.mailer.system_mail_sender",
        "pdf_footer_text" => ".framework.pdf.footer_text",
        "pers_logbook_cols" => ".app.notification.personal_logbook.columns",
        "pers_logbook_table" => ".app.notification.personal_logbook.table_tag",
        "pers_logbook_td" => ".app.notification.personal_logbook.td_tag",
        "pers_logbook_th" => ".app.notification.personal_logbook.th_tag",
        "pers_logbook_tr" => ".app.notification.personal_logbook.tr_tag",
        "public_notavailable" => ".app.public_info.notavailable",
        "public_notusable" => ".app.public_info.notusable",
        "public_onthewater" => ".app.public_info.onthewater",
        "public_reserved" => ".app.public_info.reserved",
        "public_tripdata_BoatAffix" => ".app.public_info.trip_data.boat_affix",
        "public_tripdata_BoatName" => ".app.public_info.trip_data.boat_name",
        "public_tripdata_BoatType" => ".app.public_info.trip_data.boat_type",
        "public_tripdata_CrewGroups" => ".app.public_info.trip_data.crew_groups",
        "public_tripdata_Destination" => ".app.public_info.trip_data.destination",
        "public_tripdata_Distance" => ".app.public_info.trip_data.distance",
        "public_tripdata_EntryId" => ".app.public_info.trip_data.entry_id",
        "public_tripdata_StartTime" => ".app.public_info.trip_data.start_time",
        "synch_check_period" => ".app.synchronisation.synch_check_period",
        "synch_period" => ".app.synchronisation.synch_period",
        "configured_jobs" => ".app.maintenance.configured_jobs",
        "PurgeDeletedAgeDays" => ".app.maintenance.purge_deleted_age_days",
        "AVVdatum" => ".app.operations.date_of_cdp_agreement",
        "debug_support" => ".app.operations.debug_on", "Hoster" => ".app.operations.hoster",
        "Betriebsverantwortlich" => ".app.operations.operations_responsible",
        "acronym" => ".app.club.acronym", "language_code" => ".app.club.habits.language",
        "efa_NameFormat" => ".app.club.habits.name_format",
        "sports_year_start" => ".app.club.habits.sports_year_start",
        "notify_admin_message_to" => ".app.notification.notify_admin_message_to",
        "notify_damage_to" => ".app.notification.notify_damage_to",
        "notify_damage_unusable_only" => ".app.notification.notify_damage_unusable_only",
        "notify_reservation_to" => ".app.notification.notify_reservation_to",
        "app_url" => false, "backup" => false, "current_logbook" => false, "current_logbook2" => false,
        "current_logbook3" => false, "current_logbook4" => false, "mail_schriftwart" => false,
        "pdf_document_author" => false, "reference_client" => false
    ];

    /**
     * the handling options for efa/efaCloud to app value mapping.
     */
    public static array $efaEfaCloudToDilboHandlings = ["add_time", "copy", "copy_bookname",
        "copy_distance", "create_boat_variants", "id_at_other_table", "map_damage_number",
        "merge_crew", "millis_to_microtime", "skip", "use_name_if_empty"
    ];

    /**
     * The efa/efaCloud to app table mapping. Records may be merged, as for efa2boats + efa2boatstatus =>
     * assets, and efa2persons + efaCloudUsers => persons. The policy is a ";"-separated String with
     * "target;handling;if_empty;add", i.e. the target app field, the applicable handling, a value to be
     * used if empty, and a value to be added (datetime join). Handling is one of
     * $efa_efaClud_to_dilbo_tables_handling.
     */
    public static array $efaEfaCloudToDilboMapping;

    /**
     * The result cache for a record mapping
     */
    private static array|bool $dilboRow;

    /**
     * Errors, warnings, and info log for mapping.
     */
    private static array $errors = [];

    private static array $warnings = [];

    /**
     * Load the efa2dilbo mapping configuration from the file "../../Config/mapping/efa2dilbo".
     * @return void
     */
    public static function loadMapping(): void {
        self::$efaEfaCloudToDilboMapping = [];
        self::$efaEfaCloudToDilboHandlings = [];
        $efaToDilboMappings = Codec::csvFileToMap("../../Config/mapping/efa2dilbo");
        foreach($efaToDilboMappings as $efaToDilboMapping) {
            $efaTableName = $efaToDilboMapping["efa2able"];
            $handling = $efaToDilboMapping["rule efa2dilbo"];
            if (!isset(self::$efaEfaCloudToDilboMapping[$efaTableName])) {
                self::$efaEfaCloudToDilboMapping[$efaTableName] = [];
                self::$efaEfaCloudToDilboMapping[$efaTableName]["."] = $efaToDilboMapping["dilbo table"];
            }
            if (! in_array($handling, self::$efaEfaCloudToDilboHandlings))
                self::$efaEfaCloudToDilboHandlings[] = $handling;
            if ($handling != "skip_table") {
                $efaColumnName = $efaToDilboMapping["efa2column"];
                // the handling includes the information on the value type of the efa column
                self::$efaEfaCloudToDilboMapping[$efaTableName][$efaColumnName] = $efaToDilboMapping;
            }
        }
    }

    /**
     * Clear the self:$errors and self:$findings
     */
    public static function clearFindings(): void
    {
        self::$errors = [];
        self::$warnings = [];
    }

    /**
     * Get the first variant's "TypeType" value. This is assumed to be identical for all variants
     * @param array $efa2boatsRecord the efa2boats record
     * @return string the boat type first variant's "TypeType" value
     */
    public static function getBoatType(array $efa2boatsRecord): string
    {
        return explode(";", $efa2boatsRecord["TypeType"])[0];
    }

    /**
     * Compile all available boat subtypes from the boats within the efa configuration.
     * @param array $efa2boatsRecord the efa2boats record
     * @param Item|null $assetSubtypesItem the item to which the subtypes are added.
     * @return string the first boat subtype name.
     */
    public static function compileBoatSubtypes(array $efa2boatsRecord, ?Item $assetSubtypesItem = null): string
    {
        // extract the first variants construction
        $subtypeName = explode(";",
            (isset($efa2boatsRecord["TypeType"])) ? $efa2boatsRecord["TypeType"] : "OTHER")[0];
        if (!is_null($assetSubtypesItem)) {
            if (!$assetSubtypesItem->hasChild($subtypeName))
                $assetSubtypesItem->putChild([ "_name" => $subtypeName, PropertyName::VALUE_TYPE->value => "none",
                    PropertyName::ACTUAL_LABEL->value => $subtypeName], false);
            // added structure is never part of the Config/packaged file set but added to the appropriate Config/added file.

        }
        return $subtypeName;
    }

    /**
     * Compile a set of boat variants as are available in this boat record and append it o the configuration.
     * @param array $efa2boatsRecord the efa2boats record
     * @param Item|null $boatVariantsItem the item to which the variants are added.
     * @return array the boat variant names.
     */
    public static function compileBoatVariants(array $efa2boatsRecord,
                                               Item  $boatVariantsItem = null): array
    {
        $variantIndices = explode(";", $efa2boatsRecord["TypeVariant"]);
        $bvNames = [];
        $catalogs = Config::getInstance()->getItem(".catalogs");
        $language = Config::getInstance()->language();
        for ($v = 0; $v < count($variantIndices); $v++) {
            // extract the values
            $seating = explode(";",
                (isset($efa2boatsRecord["TypeSeats"])) ? $efa2boatsRecord["TypeSeats"] : "OTHER")[$v];
            $seatingItem = $catalogs->getChild("boat_seating")->getChild($seating);
            $coxing = explode(";",
                (isset($efa2boatsRecord["TypeCoxing"])) ? $efa2boatsRecord["TypeCoxing"] : "OTHER")[$v];
            $coxingItem = $catalogs->getChild("boat_coxing")->getChild($coxing);
            $places = intval($seating) + ((strcasecmp($coxing, "COXED") == 0) ? 1 : 0);
            $rigging = explode(";",
                (isset($efa2boatsRecord["TypeRigging"])) ? $efa2boatsRecord["TypeRigging"] : "OTHER")[$v];
            $riggingItem = $catalogs->getChild("boat_rigging")->getChild($rigging);
            // create a boat variant
            $bv_name = $seating . "_" . $coxing . "_" . $rigging;
            $bv_label = $seatingItem->label() . "-" . $coxingItem->label() . "-" . $riggingItem->label();
                $bvNames[$variantIndices[$v]] = $bv_name;
            if (!is_null($boatVariantsItem)) {
                $boatVariantsItem->putChild( [ "_name" => $bv_name, PropertyName::VALUE_TYPE->value => "template",
                    PropertyName::VALUE_REFERENCE->value => ".templates.boat_variant" ], false);
                // added structure is never part of the Config/packaged file set but added to the appropriate Config/added file.
                $variant = $boatVariantsItem->getChild($bv_name);
                // set the variant label
                $variant->parseProperty(PropertyName::ACTUAL_LABEL->value, $bv_label, $language);
                // set the variant template items
                $variant->getChild("coxing")->parseProperty(PropertyName::ACTUAL_VALUE->value, $coxing, $language);
                $variant->getChild("seating")->parseProperty(PropertyName::ACTUAL_VALUE->value, $seating, $language);
                $variant->getChild("places")->parseProperty(PropertyName::ACTUAL_VALUE->value, $places, $language);
                $variant->getChild("rigging")->parseProperty(PropertyName::ACTUAL_VALUE->value, $rigging, $language);
            }
        }
        return $bvNames;
    }

    /**
     * Set the self::$validation_error according to the reason of validation failure
     * @param int $typeOfFinding 1 = warning, 2 = error
     * @param string $message the finding's message
     * @return void
     */
    private static function addFinding(int $typeOfFinding, string $message): void
    {
        if ($typeOfFinding == 1)
            self::$warnings[] = $message;
        else if ($typeOfFinding == 2)
            self::$errors[] = $message;
    }

    /**
     * Simple getter
     */
    public static function getErrors(): array
    {
        return self::$errors;
    }

    /**
     * Simple getter
     */
    public static function getWarnings(): array
    {
        return self::$warnings;
    }

    /**
     * Map all efaCloud parameter values to the respective app configuration fields
     * @param array $efaCloudCfgApp the efaCloud app configuration as an array of key-value pairs.
     * @return void
     */
    public static function mapEfaCloudConfig(array $efaCloudCfgApp): void
    {
        $config = Config::getInstance();
        $language = $config->language();
        foreach (self::$efaCloudToDilboConfig as $efaCloudName => $dilboPath) {
            if ($dilboPath !== false) {
                $target = $config->getItem($dilboPath);
                if (! $target->isValid())
                    $target->parseProperty(PropertyName::ACTUAL_VALUE->value, $efaCloudCfgApp[$efaCloudName], $language);
            }
        }
    }

    /**
     * Remove the year part of a book name and replace it by default if the remainder is empty.
     * @param string $bookName the book name to be normalized.
     * @param string $bookListName the book list name to be used as a default.
     * @return string the normalized book name.
     */
    public static function normalizeBookName(string $bookName, string $bookListName): string
    {
        $normalized = trim(str_replace("_", " ", $bookName));
        $yearStart = strpos($normalized, "2");
        if (($yearStart !== false) && (mb_strlen($normalized) >= ($yearStart + 4)) &&
            is_numeric(mb_substr($normalized, $yearStart, 4)) &&
            (intval(mb_substr($normalized, $yearStart, 4)) >= 2000) &&
            (intval(mb_substr($normalized, $yearStart, 4)) <= 2999)) {
            $normalized = str_replace(mb_substr($normalized, $yearStart, 4), "", $normalized);
            if (strlen($normalized) < 2)
                $normalized = substr($bookListName, 0, strlen($bookListName) - 1);
        }
        return $normalized;
    }

    /**
     * Apply a policy as provided in self::$efaEfaCloudToDilboMapping and self::$efaExtraToDilboTables
     * to add one or more fields to self::$dilboRecord. Findings are reported. In case of failure of the
     * target record, self::$dilbo_record will be false.
     * @param string $efaTableName the name of the efa table to which the policy applies.
     * @param string $efaColumn the name of the efa column to which the policy applies.
     * @param string $efaBookName the name of the efa book to which the policy applies.
     * @param array $efaRow the efa row (the record with all values as String) to which the policy applies.
     * @return void
     */
    private static function applyPolicy(string $efaTableName, string $efaColumn, string $efaBookName,
                                        array  $efaRow): void
    {
        $i18n = I18n::getInstance();
        $dbc = DatabaseConnector::getInstance();

        $mappingPolicy = self::$efaEfaCloudToDilboMapping[$efaTableName][$efaColumn];
        if (! isset(self::$efaEfaCloudToDilboMapping[$efaTableName][$efaColumn]))
            return;
        $dilboTable = $mappingPolicy["dilbo table"];
        $dilboColumn = $mappingPolicy["dilbo field"];
        $value = $efaRow[$efaColumn] ?? "";
        $handling = $mappingPolicy["rule efa2dilbo"];
        $efaToJoinColumn = $mappingPolicy["rule join field"];
        $efaToJoinValue = ((strlen($efaToJoinColumn) > 0) && isset($efaRow[$efaToJoinColumn])) ? $efaRow[$efaToJoinColumn] : "";
        if (($handling == "use_name_if_empty") && (strlen($value) == 0) && (strlen($efaToJoinValue) == 0))
            self::addFinding(1, $i18n->t("7zx0jm|empty Id and Name for °%...",
                $efaTableName . "." . $efaToJoinColumn));

        // now execute handling as defined in self::$efa_efaCloud_to_dilbo_handlings.
        if ($handling == "add_time")
            self::$dilboRow[$dilboColumn] = trim(
                ((strlen($value) > 0) ? explode(" ", $value)[0] : date("d.m.Y")) . " " . $efaToJoinValue);
        elseif ($handling == "add_date_if_empty") {
            // special case: for the logbook end time the date is empty, it is the same as for the start.
            // dilbo does not use separate date and time fields and has to join the date value to the time
            // if the EndDate was not already joined by the EndTime.
            if (!isset(self::$dilboRow[$dilboColumn]) || (strlen(self::$dilboRow[$dilboColumn]) == 0))
                self::$dilboRow[$dilboColumn] = $efaToJoinValue . " " . $value;
        }
        elseif ($handling == "copy")
            self::$dilboRow[$dilboColumn] = $value;
        elseif ($handling == "copy_bookname")
            self::$dilboRow[$dilboColumn] = self::normalizeBookName($efaBookName, substr($efaTableName, 4) . "s");
        elseif ($handling == "copy_distance") {
            if (!str_contains($value, "km")) {
                if ((str_contains($value, "m")) && is_numeric(trim(str_replace("m", "", $value))))
                    $distance = floatval(trim(str_replace("m", "", $value))) / 1000;
                else {
                    self::addFinding(2,
                        $i18n->t("dvGVan|missing unit °km° or °m°...", $value, $efaTableName, $efaColumn));
                    $distance = floatval(trim($value));
                }
            } else
                $distance = floatval(trim(str_replace("km", "", $value)));
            self::$dilboRow[$dilboColumn] = strval($distance);
        } elseif ($handling == "copy_list") {
            // replace list separator and uuid by short uuid. Affects allowed_group_uuids, destination_areas,
            // waters_uuids, and member_uuids.
            $elements = Codec::splitCsvRow($value);
            foreach ($elements as &$element)
                if (Ids::isUuid($element))
                    $element = substr($element, 0, 11);
            self::$dilboRow[$dilboColumn] = Codec::joinCsvRow($elements, ",");
        } elseif ($handling === "create_boat_variants") {
            $bvNames = self::compileBoatVariants($efaRow);
            $subtypeName = self::compileBoatSubtypes($efaRow);
            $variantOptions = "";
            foreach ($bvNames as $option)
                $variantOptions .= "," . $option;
            if (strlen($variantOptions) > 0)
                $variantOptions = substr($variantOptions, 1);
            self::$dilboRow[$dilboColumn] = $variantOptions;
            self::$dilboRow["asset_subtype"] = $subtypeName;
        } elseif ($handling == "map_damage_numbers") {
            // <=== efa damage numbers are not unique, they are therefore renumbered, and
            // the efa number is cached.
            self::$dilboRow["efa_number"] = $value;
            $reference = $value . "." . $efaRow["BoatId"];
            $newNumber = $_SESSION["efaImport"]["map_damage_numbers"][$reference];
            self::$dilboRow[$dilboColumn] = strval($newNumber);
        } elseif ($handling === "map_to_assets") {
            // fill-in for the base status field from efa's efa2boatstatus to dilbo's assets
            if ((strlen($value) > 0) && Ids::isUuid($value)) {
                $matching = [ "uuid" => $value ];
                $targetRecords = $dbc->findAllSorted("assets", $matching, 1, "=",
                    "invalid_from", false);
                if ($targetRecords === false) {
                    // reference could not be resolved. Refuse insert
                    self::addFinding(2, $i18n->t("QR0NE4|Failed to find record wi...", $value, $dilboTable));
                    self::$dilboRow = false;
                } else {
                    // add the uid of the record to update
                    self::$dilboRow["uid"] = $targetRecords[0]["uid"];
                    self::$dilboRow[$dilboColumn] = $efaRow["BaseStatus"];
                }
            }
        } elseif ($handling == "merge_crew") {
            $crew = [];
            $coxed = ""; // this is boolean false. Will be parsed and formatted.
            if (isset($efaRow["CoxId"]) && (Ids::isUuid($efaRow["CoxId"]))) {
                $crew[] = substr($efaRow["CoxId"], 0, 11);
                $coxed = "on";
            } elseif (isset($efaRow["CoxName"]) &&
                (strlen($efaRow["CoxName"]) > 0)) {
                $crew[] = $efaRow["CoxName"];
                $coxed = "on";
            }
            for ($i = 1; $i <= 24; $i++) {
                $idName = "Crew" . $i . "Id";
                $nameName = "Crew" . $i . "Name";
                if (isset($efaRow[$idName]) && (Ids::isUuid($efaRow[$idName]))) {
                    $crew[] = substr($efaRow[$idName], 0, 11);
                } elseif (isset($efaRow[$nameName]) &&
                    (strlen($efaRow[$nameName]) > 0)) {
                    $crew[] = $efaRow[$nameName];
                }
            }
            self::$dilboRow[$dilboColumn] = Codec::joinCsvRow($crew, ",");
            self::$dilboRow["coxed"] = $coxed;
        } elseif ($handling == "millis_to_microtime") {
            // eternity in efa is 9,223,372,036,854,775,807 (19 characters) = 9.2E+18. Conversion to float will
            // ensure that this is always the greatest value and search for the last valid one will return
            // this value first
            self::$dilboRow[$dilboColumn] = strval(floatval($value) / 1000);
        } elseif ($handling == "millis_to_date") {
            // usually the millis represent a date at 00:00 hours AM. Due to timezone effects, this sometimes
            // is shifted to 23PM of the previous day. To ensure that this is covered, three hours are added
            $date = ((floatval($value) / 1000) >= ParserConstraints::FOREVER_SECONDS)
                ? ParserConstraints::empty(ParserName::DATE)
                // usually the millis represent a date at 00:00 hours AM. Due to timezone effects, this sometimes
                // is shifted to 23PM of the previous day. To ensure that this is covered, three hours are added
                : Formatter::microTimeToDateTime((floatval($value) / 1000) + 10800);
            // Remove the extra hours by formatting it as date
            self::$dilboRow[$dilboColumn] = Formatter::format($date, ParserName::DATE, Language::CSV);
        } elseif ($handling === "use_name_if_empty") {
            // this includes efa2logbook.WatersIdList. So if there were a mix of Ids and Names, the names will be dropped.
            self::$dilboRow[$dilboColumn] = ((strlen($value) > 0) ? $value : $efaToJoinValue);
        }
    }

    /**
     * Map an efa record to an app record. This requires full configuration initialisation. The result may be
     * more than one record if a record is split into two, e.g.
     * @param string $efaTableName the name of the efa table to which the policy applies.
     * @param string $efaBookName the name of the efa book to which the policy applies.
     * @param array $efaRecordAsStrings the efa record as an array of key-value pairs, values are Strings.
     * @return bool|array the mapped record or false if the mapping failed.
     */
    public static function mapEfaRecordToDilbo(string $efaTableName, string $efaBookName,
                                               array  $efaRecordAsStrings): bool|array
    {
        // map existing fields
        self::$dilboRow = [];
        if (isset(self::$efaEfaCloudToDilboMapping[$efaTableName])) {
            // map existing fields
            foreach ($efaRecordAsStrings as $efaColumn => $value) {
                if (isset(self::$efaEfaCloudToDilboMapping[$efaTableName][$efaColumn])) {
                    DilboConfig::applyPolicy($efaTableName, $efaColumn, $efaBookName,
                        $efaRecordAsStrings);
                    if (self::$dilboRow === false)
                        return false;
                }
            }
        }
        // add book name fields
        if ($efaTableName == "efa2clubwork")
            DilboConfig::applyPolicy("efa2clubwork", "Clubworkbookname", $efaBookName, []);
        if ($efaTableName == "efa2logbook")
            DilboConfig::applyPolicy("efa2logbook", "Logbookname", $efaBookName, []);
        // return result
        return self::$dilboRow;
    }

    /**
     * Retrieves the start date of the sports year, adjusted for a specified number
     * of years in the past.
     *
     * @param int $soManyYearsAgo The number of years before the current year to calculate
     *                            the sports year start. Defaults to 0 for the current sports year.
     * @return DateTimeImmutable The calculated start date of the sports year as a DateTimeImmutable object.
     */
    public static function getSportsYearStart(int $soManyYearsAgo = 0): DateTimeImmutable
    {
        $config = Config::getInstance();
        $sportsYearStartMonth = $config->getItem(".app.club.habits.sports_year_start")->valueStr();
        $sportsYearStartMonth = (strlen($sportsYearStartMonth) == 1) ? "0" . $sportsYearStartMonth : $sportsYearStartMonth;
        $sportsYearStartYearsAgo = ParserConstraints::$DATETIME_MIN;
        try {
            $sportsYearStartThisYear = new DateTimeImmutable(date("Y") . "-" . $sportsYearStartMonth . "-01");
            if ($sportsYearStartThisYear > (new DateTimeImmutable("now")))
                // the current sports year started last calendar year
                $soManyYearsAgo++;
            $sportsYearStartYearsAgo = new DateTimeImmutable(
                (intval(date("Y")) - $soManyYearsAgo) . "-" . $sportsYearStartMonth . "-01");
        } catch (Exception) {
            // ignore
        }
        return $sportsYearStartYearsAgo;
    }

    private function getSportsYearEnd(int $soManyYearsAgo = 0): DateTimeImmutable
    {
        $sports_year_start = DilboConfig::getSportsYearStart($soManyYearsAgo - 1);
        try {
            return $sports_year_start->sub(new DateInterval("P1D"));
        } catch (Exception) {
            return $sports_year_start;
        }
    }

}

