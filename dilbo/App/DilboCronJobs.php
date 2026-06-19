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

include_once "../../tfyh/Control/CronJobs.php";
use tfyh\control\CronJobs;
use tfyh\data\Config;

/**
 * Static class container file for a daily jobs routine. It may be triggered by whatever, checks whether it was
already controlled this day, and if not, starts the sequence.
 */
class DilboCronJobs extends CronJobs
{

    /**
     * control all daily jobs.
     */
    public static function runDailyJobs(): bool
    {
        $dailyRun = CronJobs::runDailyJobs();

        // add application-specific cron jobs here.
        // The sequence is an implicit priority in case one of the jobs fails.
        if ($dailyRun) {

            // OPEN LOG
            // --------
            $cronLog = "../../var/Log/cron.log";
            file_put_contents($cronLog, date("Y-m-d H:i:s") . " +0: specific app cronjob started.\n",
                FILE_APPEND);
            $cron_started = time();

            // CLOSE LOG
            // ---------
            file_put_contents($cronLog,
                date("Y-m-d H:i:s") . ": Cron jobs done. Total cron jobs duration = " .
                (time() - $cron_started) . ".\n", FILE_APPEND);
        }
        return true;
    }

    /**
     * Returns the repeat period if a task is due today based on the $task_day specification: starts with a letter (D =
     * Daily, W = Weekly, M = Monthly), continues with a number (for D: 1 always, for W: 1 = Monday, 2 =
     * Tuesday asf., for M: day of month, 31 is the same as ultimo). Returns an empty String, if not due today.
     */
    private static function dueToday(string $taskDay): string
    {
        $period = substr($taskDay, 0, 1);
        $day = intval(substr(trim($taskDay), 1));
        // daily control
        if (strcasecmp($period, "D") == 0)
            return "daily";
        // weekly control
        if ((strcasecmp($period, "W") == 0) && ($day == intval(date("w"))))
            return "weekly";
        // monthly control, any day
        if ((strcasecmp($period, "M") == 0) && ($day == intval(date("j"))))
            return "monthly";
        // monthly control, ultimo. 86,400 seconds are 1 day
        if ((strcasecmp($period, "M") == 0) && ($day == 31) && (intval(date("j", time() + 86400)) == 1))
            return "ultimo";
        return "";
    }

    /**
     * Jobs can be configured to be control together with the cron jobs trigger, which should be called every day.
     * A job consists of a scheduled day (see Tfyh_tasks->due_today for details) and a task type.
     */
    private static function runConfiguredJobs(): void
    {
        // get the job list
        $jobsItem = Config::getInstance()->getItem(".app.maintenance.configured_jobs");
        // TODO
    }
}
