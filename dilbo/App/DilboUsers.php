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

namespace App;

use Control\Users;
use Data\DatabaseConnector;
use Util\I18n;

/**
 * A utility class to read and provide the specific user profile according to the application. It is separated
 * from the user.php to keep the latter generic for all applications.
 */
class DilboUsers
{

    /* ======================== Application specific user property management ===================== */
    /**
     * Provide the HTML table with all stored data of the user.
     * @param int $userId the id of the user to read.
     * @param bool $short if true, only the first and last name are shown.
     * @return string the HTML table with all stored data of the user.
     */
    public static function getUserProfile(int $userId, bool $short = false): string
    {
        $users = Users::getInstance();
        $userToRead = DatabaseConnector::getInstance()->find($users->userTableName, $users->userIdFieldName, $userId);
        if ($userToRead === false)
            return "<table><tr><td><b>" . I18n::getInstance()->t("gAFiMp|User not found") .  "</b>&nbsp;&nbsp;&nbsp;</td>" . "<td>" .
                $users->userIdFieldName . ": '" . $userId . "'</td></tr>\n";
        else
            return self::getUserProfileOnArray($userToRead, $short);
    }

    /**
     * Generates an HTML table representation of a user's profile data.
     *
     * @param array $userToRead An associative array containing user data where keys represent property names and values
     * represent property values.
     * @param bool $short Determines whether to generate a short format of the profile limiting details or a full
     * version. Defaults to false.
     *
     * @return string A string containing an HTML representation of the user's profile.
     */
    public static function getUserProfileOnArray(array $userToRead, bool $short = false): string
    {
        $i18n = I18n::getInstance();
        $users = Users::getInstance();
        // main data
        $htmlStr = "<div style='overflow-x:scroll'><table>";
        if ($short) {
            $htmlStr .= "<tr><td><b>" .
                $userToRead[$users->userFirstNameFieldName] . " " .
                $userToRead[$users->userLastNameFieldName] . "</b>&nbsp;&nbsp;&nbsp;</td>";
        }
        $htmlStr .= "<tr><th><b>" . $i18n->t("0Cdq2W|Property") . "</th><th>". $i18n->t("Y3rWps|Value") . "</th></tr>";
        $noValuesFor = "";
        foreach ($userToRead as $key => $value) {
            $show = !$short || (strcasecmp($key, $users->userMailFieldName) === 0) || (strcasecmp($key, "role") === 0);
            if ($value && $show) {
                if (strcasecmp($key, "password_hash") === 0)
                    $htmlStr .= "<tr><td><b>" . $key . "</b>&nbsp;&nbsp;&nbsp;</td><td>" .
                        ((strlen($value) > 10) ? $i18n->t("6HRhB6|set") : $i18n->t("8MAcUu|not set")) . "</td></tr>\n";
                elseif (strcasecmp($key, "history") === 0)
                    $htmlStr .= "<tr><td><b>history</b>&nbsp;&nbsp;&nbsp;</td><td>" .
                        ((strlen($value) > 0) ? $i18n->t("ZkvC3i|more versions") : $i18n->t("IF1cIg|none")) . "</td></tr>\n";
                elseif (strcasecmp($key, "subscriptions") === 0)
                    $htmlStr .= $users->getUserServices("subscriptions", $key, $value);
                elseif (strcasecmp($key, "workflows") === 0)
                    $htmlStr .= $users->getUserServices("workflows", $key, $value);
                elseif (strcasecmp($key, "concessions") === 0)
                    $htmlStr .= $users->getUserServices("concessions", $key, $value);
                else
                    $htmlStr .= "<tr><td><b>" . $key . "</b>&nbsp;&nbsp;&nbsp;</td><td>" . $value .
                        "</td></tr>\n";
            }
            if ((!$value) && (strcasecmp($key, "history") != 0) && (strcasecmp($key, "workflows") != 0) &&
                (strcasecmp($key, "concessions") != 0))
                $noValuesFor .= $key . ", ";
        }
        if (strlen($noValuesFor) > 0)
            $htmlStr .= "<tr><td><b>" . $i18n->t("AYpSu8|no values within") . "</b>&nbsp;&nbsp;&nbsp;</td><td>" .
                $noValuesFor . "</td></tr>\n";

        $htmlStr .= "</table></div>";
        return $htmlStr;
    }
}
