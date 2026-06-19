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

use tfyh\api\ResultForTransaction;
use tfyh\data\Codec;
use tfyh\data\Config;
use tfyh\data\DatabaseConnector;
use tfyh\data\Formatter;
use tfyh\data\Ids;
use tfyh\data\ParserName;

/**
 * class file for the specific handling of dilbo public information which is to be passed to different clients.
 */
class Info
{

    /**
     * empty Constructor.
     */
    public function __construct() {}

    /**
     * get a list of all groups of the groups to which identified crew members belong.
     */
    private function getCrewMembersGroups(array $allCrewIds): string
    {
        $allCrewGroups = [];
        foreach ($allCrewIds as $crewMemberId) {
            $memberGroups = DatabaseConnector::getInstance()->findAllSorted("groups",
                ["member_uuids" => "%" . $crewMemberId . "%"
                ], 10, "LIKE", "Name", true);
            if ($memberGroups !== false) {
                foreach ($memberGroups as $memberGroup) {
                    if (!isset($allCrewGroups[$memberGroup["Name"]]))
                        $allCrewGroups[$memberGroup["Name"]] = 0;
                    $allCrewGroups[$memberGroup["Name"]]++;
                }
            }
        }
        if (count($allCrewGroups) == 0)
            return "-";
        ksort($allCrewGroups);
        $crewGroups = "";
        foreach ($allCrewGroups as $crewGroupName => $crewGroupCnt)
            $crewGroups .= $crewGroupName . "(" . $crewGroupCnt . ")" . ", ";
        if (strlen($crewGroups) > 0)
            $crewGroups = mb_substr($crewGroups, 0, mb_strlen($crewGroups) - 2);
        return $crewGroups;
    }

    /**
     * Get the header for the open trips table.
     */
    private function getTripHeader(bool $getEmptyRow): array
    {
        $values = [];
        $show = Config::getInstance()->getItem(".app.public_info.trip_data");
        if ($show->getChild("entry_id")->value())
            $values[] = ($getEmptyRow) ? "-" : $show->getChild("entry_id")->label();
        if ($show->getChild("boat_name")->value())
            $values[] = ($getEmptyRow) ? "-" : $show->getChild("boat_name")->label();
        if ($show->getChild("boat_affix")->value())
            $values[] = ($getEmptyRow) ? "-" : $show->getChild("boat_affix")->label();
        if ($show->getChild("boat_type")->value())
            $values[] = ($getEmptyRow) ? "-" : $show->getChild("boat_type")->label();
        if ($show->getChild("crew_groups")->value())
            $values[] = ($getEmptyRow) ? "-" : $show->getChild("crew_groups")->label();
        if ($show->getChild("start_time")->value())
            $values[] = ($getEmptyRow) ? "-" : $show->getChild("start_time")->label();
        if ($show->getChild("destination")->value())
            $values[] = ($getEmptyRow) ? "-" : $show->getChild("destination")->label();
        if ($show->getChild("distance")->value())
            $values[] = ($getEmptyRow) ? "-" : $show->getChild("distance")->label();
        return $values;
    }

    /**
     * get the table information for a single trip for a boat on the water in the open trips table.
     */
    private function getTripRow(array $trip): array
    {
        $tripRow = [];
        $show = Config::getInstance()->getItem(".app.public_info.trip_data");

        if ($show->getChild("entry_id")->value())
            $tripRow[] = strval($trip["EntryId"]);
        $boat = DatabaseConnector::getInstance()->find("boats", "asset_uuid", $trip["asset_uuid"]);
        if ($show->getChild("boat_name")->value()) {
            $boatName = ($boat && isset($boat["name"])) ? $boat["name"] : "???";
            $tripRow[] = $boatName;
        }
        if ($show->getChild("boat_affix")->value()) {
            $boatAffix = ($boat && isset($boat["name_affix"])) ? "(" . $boat["name_affix"] . ")" : "";
            $tripRow[] = $boatAffix;
        }
        if ($show->getChild("boat_type")->value()) {
            $config = Config::getInstance();
            $boatType = "???";
            if (isset($boat["asset_subtype"]))
                $boatType = $config->getItem(".catalogs.asset_subtype." . $boat["asset_subtype"])->label();
            if (isset($trip["asset_variant"]))
                $boatType .= "(" . $config->getItem(".catalogs.boat_variants." . $trip["asset_variant"])->label() . ")";
            $tripRow[] = $boatType;
        }
        if ($show->getChild("crew_groups")->value()) {
            $crewGroups = (isset($trip["crew"]) && is_array($trip["crew"]) && (count($trip["crew"]) > 0)) ?
                $this->getCrewMembersGroups($trip["crew"]) : "-";
            $tripRow[] = $crewGroups;
        }
        if ($show->getChild("start_time")->value()) {
            $tripRow[] = Formatter::format($trip["start_time"], ParserName::TIME);
        }
        if ($show->getChild("destination")->value()) {
            if (Ids::isUuid($trip["destination"])) {
                $destinationRecord = DatabaseConnector::getInstance()->find("destinations", "Id",
                    $trip["DestinationId"]);
                $destination = ($destinationRecord && isset($destinationRecord["Name"])) ? $destinationRecord["Name"] : "-";
            } else
                $destination = $trip["DestinationName"];
            if (is_null($destination) || (strlen($destination) == 0))
                $destination = "-";
            $tripRow[] = $destination;
        }
        if ($show->getChild("distance")->value()) {
            $tripRow[] = (isset($trip["Distance"]) && (strlen($trip["Distance"]) > 0)) ? $trip["Distance"] : "-";
        }
        return $tripRow;
    }

    /**
     * Return an HTML or csv representation of all boats on the water
     * @return string the html or csv representation of the table with all boats on the water
     */
    private function getOnTheWater(bool $withHeadline, bool $asHtml): string
    {
        $openTrips = Logbook::getOpenTrips();
        $table = [];
        $table[] = $this->getTripHeader(false);
        foreach ($openTrips as $openTrip)
            $table[] = $this->getTripRow($openTrip);
        if (count($openTrips) == 0)
            $table[] = $this->getTripHeader(true);
        if ($asHtml)
            return Codec::tableToHtml($table, $withHeadline);
        else
            return Codec::tableToCsv($table, $withHeadline);
    }

    /**
     * Removes all fields from the $boat which are not "name", "asset_subtype", or "default_variant" and replaces
     * the values for "asset_subtype" and "default_variant" by the label of the chosen value.
     */
    private function filterAnLocalizeBoatInfo(array &$boats): void
    {
        $boatInfoFields = ["name", "asset_subtype", "default_variant" ];
        foreach ($boats as &$boat) {
            foreach($boat as $field => $value) {
                if (! in_array($field, $boatInfoFields))
                    unset($boat[$field]);
            }
            $config = Config::getInstance();
            if (isset($boat["asset_subtype"]))
                $boat["asset_subtype"] = $config->getItem(".catalogs.asset_subtypes." . $boat["asset_subtype"])->label();
            if (isset($boat["default_variant"]))
                $boat["default_variant"] = $config->getItem(".app.boat_variants." . $boat["default_variant"])->label();
        }
    }

    /**
     * Return an HTML or csv representation of all not available
     */
    private function getNotAvailable(bool $withHeadline, bool $asHtml): string
    {
        $unavailableBoats = Logbook::getUnavailableBoats();
        $this->filterAnLocalizeBoatInfo($unavailableBoats);
        if ($asHtml)
            return Codec::tableToHtml($unavailableBoats, $withHeadline);
        else
            return Codec::tableToCsv($unavailableBoats, $withHeadline);
    }

    /**
     * Return an HTML or csv representation of all boats reserved in the next 14 days
     */
    private function getReserved(bool $onlyApproved, bool $withHeadline, bool $asHtml): String
    {
        $bookedBoats = Logbook::getBookedBoats($onlyApproved);
        $this->filterAnLocalizeBoatInfo($bookedBoats);
        if ($asHtml)
            return Codec::tableToHtml($bookedBoats, $withHeadline);
        else
            return Codec::tableToCsv($bookedBoats, $withHeadline);
    }

    /**
     * Return an HTML or csv representation of all boats with damages in "not_usable" status
     */
    private function getNotUsable(bool $withHeadline, bool $asHtml): String
    {
        $notUsableBoats = Logbook::getDamagedBoats(false);
        $this->filterAnLocalizeBoatInfo($notUsableBoats);
        if ($asHtml)
            return Codec::tableToHtml($notUsableBoats, $withHeadline);
        else
            return Codec::tableToCsv($notUsableBoats, $withHeadline);
    }

    /**
     * Get the information of the requested type as state in the transaction record.
     */
    public function apiInfo(array $txRecord): string
    {
        $txResponse = sprintf("%s;type invalid in %s", ResultForTransaction::TRANSACTION_INVALID->value,
            json_encode($txRecord));
        $ok = ResultForTransaction::REQUEST_SUCCESSFULLY_PROCESSED->value . ";";
        if (isset($txRecord["type"])) {
            $withHeadline = $txRecord["with_headline"] ?? false;
            $asHtml = $txRecord["as_html"] ?? false;
            $type = $txRecord["type"];
            if (strcasecmp("onthewater", $type) == 0) {
                $txResponse = $ok . $this->getOnTheWater($withHeadline, $asHtml);
            } elseif (strcasecmp("notavailable", $type) == 0) {
                $txResponse = $ok . $this->getNotAvailable($withHeadline, $asHtml);
            } elseif (strcasecmp("notusable", $type) == 0) {
                $txResponse = $ok . $this->getNotUsable($withHeadline, $asHtml);
            } elseif (strcasecmp("reserved", $type) == 0) {
                $txResponse = $ok . $this->getReserved(true, $withHeadline, $asHtml);
            }
        }
        return $txResponse;
    }
}
