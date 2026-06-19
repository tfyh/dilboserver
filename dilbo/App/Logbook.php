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

use tfyh\data\Config;
use tfyh\data\DatabaseConnector;
use tfyh\data\Ids;
use tfyh\data\ParserName;
use tfyh\data\ParserConstraints;
use tfyh\util\ListHandler;

/**
 * class file for logbook reading, auditing, and formatting capabilities.
 */
class Logbook
{

    /**
     * uuid resolving utility class.
     */
    private DilboIds $dilboUuids;

    /**
     * Public Constructor. Runs the anonymisation.
     */
    public function __construct()
    {
        $this->dilboUuids = new DilboIds();
    }

    /**
     * Get the statistics of the given name.
     * @param string $type e.g. "total_distance"
     * @param string $field "crew" or "waters"
     * @return string the statistics in csv format.
     */
    public function getStatistics(string $type, string $field): string
    {
        if (strcasecmp($type, "total_distance") == 0) {
            $sports_year_start = DilboConfig::getSportsYearStart();
            $list_args = ["{sports_year_start}" => date("Y-m-d", $sports_year_start)
            ];
            $data_list = new ListHandler("logbook", "statistics", $list_args);
            $records = $data_list->getRows("localized");
            $is_list = (strcasecmp($field, "crew")) || (strcasecmp($field, "waters"));
            $pivot = [];
            foreach ($records as $record) {
                $pivot_line_items = ($is_list) ? $record[$field] : [$record[$field]];
                $distance = floatval($record["distance"]);
                foreach ($pivot_line_items as $pivot_line_item) {
                    if (Ids::isUuid($pivot_line_item)) {
                        $name = $this->dilboUuids->uuid2name($pivot_line_item)[0];
                        if (!isset($pivot[$name]))
                            $pivot[$name] = ["distance" => 0, "sessions" => 0
                            ];
                        $pivot[$name]["distance"] += $distance;
                        $pivot[$name]["sessions"] += 1;
                    }
                }
            }
            $csv = "name;total_distance;sessions_count";
            foreach ($pivot as $name => $statistics)
                $csv .= "\n$name;" . $statistics["distance"] . ";" . $statistics["sessions"];
            return $csv;
        }
        return "";
    }

    /**
     * Get a list of all open trips of the user's logbook. Set logbookName to get it for another logbook.
     */
    public static function getOpenTrips(String $logbookName = ""): array {
        if (strlen($logbookName) == 0)
            $logbookName = Config::getInstance()->getItem(".app.user_preferences.logbook")->valueStr();
        $openTripsList = new ListHandler("logbook","open_trips", ["{logbookname}" => $logbookName]);
        return $openTripsList->getRows("localized");
    }

    /**
     * Get a list of all open trips of the user's logbook. Set logbookName to get it for another logbook.
     */
    public static function getUnavailableBoats(): array {
        $unavailableBoatsList = new ListHandler("logbook","unavailable_boats");
        return $unavailableBoatsList->getRows("localized");
    }

    /**
     * Get a list of all boats currently unavailable due to a reservation. If $onlyApproved == false, this is for.
     * All reservations which were not cancelled, else only for the approved ones.
     */
    public static function getBookedBoats(bool $onlyApproved): array {
        $openReservationsList = new ListHandler("logbook","open_reservations");
        $reservations = $openReservationsList->getRows("localized");
        $boats = [];
        $dbc = DatabaseConnector::getInstance();
        foreach ($reservations as $reservation) {
            if (($onlyApproved && ! ParserConstraints::isEmpty($reservation["approved_on"], ParserName::DATETIME))
                && ParserConstraints::isEmpty($reservation["cancelled_on"], ParserName::DATETIME)) {
                $boatId = $reservation["asset_uuid"];
                $boat = $dbc->findAllSorted("assets",
                    [ "uuid" =>  $boatId ], 1, "=", "invalid_from", false);
                if ($boat)
                    $boats[] = $boat;
            }
        }
        return $boats;
    }

    /**
     * Get a list of all boats currently unavailable due to severe damage. Set $withLimitedUsable = true to gat also
     * those boats set ti limitedUsable.
     */
    public static function getDamagedBoats(bool $withLimitedUsable): array {
        $openDamagesList = new ListHandler("logbook","open_damages");
        $damages = $openDamagesList->getRows("localized");
        $boats = [];
        $dbc = DatabaseConnector::getInstance();
        foreach ($damages as $damage) {
            if (($withLimitedUsable && ($damage["severity"] != "fully_usable" ))
                || ($damage["severity"] = "not_usable" )) {
                $boatId = $damage["asset_uuid"];
                $boat = $dbc->findAllSorted("assets",
                    [ "uuid" =>  $boatId ], 1, "=", "invalid_from", false);
                if ($boat)
                    $boats[] = $boat;
            }
        }
        return $boats;
    }
}
    
