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

use tfyh\util\I18n;
use tfyh\util\ListHandler;

/**
 * class file for resolving the UUIDs into names.
 */
class DilboIds
{
    /**
     * a set of all Names of boats, destinations, persons, and waters as an associative array with the UUID being
     * the key.
     */
    private array $names;

    /**
     * a set of all UUIDs of boats, destinations, persons, and waters as an associative array with the name (for
     * the person's full name = first name plus last name) being the key.
     */
    private array $uuids;

    public function __construct()
    {
        $this->names = [];
        $this->uuids = [];
    }

    /**
     * Collect for a single table all UUIDs and reference arrays
     * @param String $listName the name of the list to be processed
     * @return void
     */
    private function collect_arrays_per_table(String $listName): void
    {
        $list = new ListHandler("uuid2names", $listName);
        $tableName = $list->getTableName();
        $records = $list->getRows("localized");
        $isPersons = (str_contains($tableName, "persons"));
        // the first 4 lists contain the last valid record for versioned tables
        // the other lists contain the only record for non-versioned tables
        foreach ($records as $record) {
            $uuid = $record["uuid"];
            if (strlen($uuid) > 30) {
                $name = ($isPersons) ? $record["first_name"] . " " . $record["last_name"] : $record["name"];
                // this index provides a UUID for a name, may be multiple
                if (!isset($this->uuids[$name]))
                    $this->uuids[$name] = [];
                $this->uuids[$name][] = [$uuid, $tableName
                ];
            }
        }
    }

    /**
     * Collect all UUIDs and names reference arrays
     */
    private function collectArrays(): void
    {
        $this->names = [];
        $this->uuids = [];
        $list = new ListHandler("uuid2names", "");
        $allDefinitions = $list->getAllListDefinitions();
        foreach ($allDefinitions as $definition)
            $this->collect_arrays_per_table($definition["_name"]);
    }

    /**
     * Find out to which table the UUID belongs and return the name for it. This will only return the last
     * valid name for versioned records.
     * @param string $uuid the UUID to be resolved.
     * @return array the names associated with this UUID.
     */
    public function uuid2name(string $uuid) : array
    {
        if (count($this->names) == 0)
            $this->collectArrays();
        if (!isset($this->names[$uuid])) {
            $i18n = I18n::getInstance();
            return [$i18n->t("ZV56RU|unknown ID"), $i18n->t("tWIACB|unknown")
            ];
        }
        else
            return $this->names[$uuid];
    }

    /**
     * Find the first uuid for a name. This is a convenience option for the case, the table name is defined.
     * @param string $name the name to be resolved.
     * @param string $tableName the name of the table to used for the resolution.
     * @return string
     */
    public function name2uuid(string $name, string $tableName) : string
    {
        return $this->name2uuids($name, $tableName)[0];
    }

    /**
     * Resolves a name to its corresponding UUID(s). May be multiple, e.g. for a name of a boat and a person being
     * identical. This can optionally filter by table name.
     * If the name or table is not found, an unresolved placeholder is returned.
     *
     * @param string $name The name to be resolved to UUID(s).
     * @param string|null $tableName An optional table name to further filter the UUID resolution.
     * @return array The UUID(s) associated with the given name or the unresolved placeholder if not found.
     */
    public function name2uuids(string $name, string $tableName = null): array
    {
        $i18n = I18n::getInstance();
        $unresolved = [$i18n->t("TjA5DZ|unknown ID"), $i18n->t("OuG821|unknown")
        ];
        if (count($this->uuids) == 0)
            $this->collectArrays();
        if (!isset($this->uuids[$name]))
            return [$unresolved
            ];
        if (is_null($tableName))
            return $this->uuids[$name];
        foreach ($this->uuids[$name] as $uuid_for_name)
            if (strcasecmp($uuid_for_name["table"], $tableName) == 0)
                return [$uuid_for_name
                ];
        return [$unresolved
        ];
    }
}
